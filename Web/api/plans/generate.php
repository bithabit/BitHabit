<?php
/**
 * BitHabit - 生成暑假作业计划 (v3)
 *
 * POST /api/plans/generate.php
 *
 * 多阶段分配 + 写入 plan_tasks + 更新 allocated_range
 * 保留已完成 + 锁定的任务
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

while (ob_get_level()) ob_end_clean();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/scheduler.php';

$user = requireAuth();
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 POST 方法', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$conn = getDbConnection();

$planId = (int)($input['planId'] ?? 0);
$rhythm = max(-2, min(2, (int)($input['rhythm'] ?? 0)));
$maxDailyMinutes = (int)($input['maxDailyMinutes'] ?? 300);
$targetEndDate = $input['targetEndDate'] ?? null;
$homeworkOverrides = $input['homeworkOverrides'] ?? [];
$startDate = $input['startDate'] ?? $input['start_date'] ?? '';
$endDate = $input['endDate'] ?? $input['end_date'] ?? '';

if ($planId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => '缺少 planId', 'code' => 'INVALID_INPUT']);
    exit;
}

$planStmt = $conn->prepare('SELECT id, name, start_date, end_date FROM plans WHERE id = ? AND user_id = ?');
$planStmt->bind_param('ii', $planId, $userId);
$planStmt->execute();
$plan = $planStmt->get_result()->fetch_assoc();
if (!$plan) {
    http_response_code(404);
    echo json_encode(['error' => '计划不存在', 'code' => 'NOT_FOUND']);
    exit;
}

if (empty($startDate)) $startDate = $plan['start_date'];
if (empty($endDate)) $endDate = $plan['end_date'];
$planName = $plan['name'];
$startTs = strtotime($startDate);
$endTs = strtotime($endDate);

// === 日程过滤 ===
$weeklySchedule = [];
$ws = $conn->prepare('SELECT day_of_week, start_time, end_time FROM schedule_weekly WHERE user_id = ?');
$ws->bind_param('i', $userId);
$ws->execute(); while ($r = $ws->get_result()->fetch_assoc()) { $r['day_of_week'] = (int)$r['day_of_week']; $weeklySchedule[] = $r; }

$specialSchedule = [];
$ss = $conn->prepare('SELECT date_from, date_to, start_time, end_time FROM schedule_special WHERE user_id = ?');
$ss->bind_param('i', $userId);
$ss->execute(); while ($r = $ss->get_result()->fetch_assoc()) { $specialSchedule[] = $r; }

$dates = [];
for ($d = $startTs; $d <= $endTs; $d += 86400) {
    $dateStr = date('Y-m-d', $d);
    $dow = (int)date('w', $d);
    $blocked = false;

    foreach ($weeklySchedule as $wsItem) {
        if ($wsItem['day_of_week'] === $dow && $wsItem['start_time'] === null && $wsItem['end_time'] === null) { $blocked = true; break; }
    }
    if ($blocked) continue;
    foreach ($specialSchedule as $ssItem) {
        if ($dateStr >= $ssItem['date_from'] && $dateStr <= ($ssItem['date_to'] ?? $ssItem['date_from'])) {
            if ($ssItem['start_time'] === null && $ssItem['end_time'] === null) { $blocked = true; break; }
        }
    }
    if ($blocked) continue;

    $dates[] = ['date' => $dateStr, 'idx' => count($dates)];
}

$N = count($dates);
if ($N === 0) {
    http_response_code(400);
    echo json_encode(['error' => '无可用日期', 'code' => 'NO_AVAILABLE_DAYS']);
    exit;
}

// === 获取作业 (v3 含新字段) ===
$hwStmt = $conn->prepare(
    'SELECT id, subject, task_type, total_amount, unit, time_per_unit,
            window_start, window_end, locked, priority, interval_days
     FROM homework WHERE plan_id = ? AND user_id = ?'
);
$hwStmt->bind_param('ii', $planId, $userId);
$hwStmt->execute();
$homeworkRows = $hwStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$overridesMap = [];
foreach ($homeworkOverrides as $ov) { $hid = (int)($ov['homeworkId'] ?? 0); if ($hid > 0) $overridesMap[$hid] = $ov; }

if (empty($homeworkRows)) {
    http_response_code(400);
    echo json_encode(['error' => '请先录入作业', 'code' => 'NO_HOMEWORK']);
    exit;
}

// === 调用公共分配算法 ===
$result = allocatePlan($homeworkRows, $dates, $rhythm, $maxDailyMinutes, $targetEndDate, $overridesMap);

// === 写入 DB ===
$conn->begin_transaction();
try {
    // 删除非锁定且未完成的任务（保留已完成）
    $del = $conn->prepare('DELETE pt FROM plan_tasks pt
        JOIN homework h ON h.id = pt.homework_id AND h.plan_id = ?
        WHERE pt.plan_id = ? AND (h.locked = 0 AND pt.completed = 0)');
    $del->bind_param('ii', $planId, $planId);
    $del->execute();

    // 写入新任务
    $taskStmt = $conn->prepare(
        'INSERT INTO plan_tasks (plan_id, homework_id, date, amount, estimated_minutes, sort_order) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $sortOrder = 0;
    $createdTasks = 0;

    // 重建 homework id => 字段映射
    $hwMap = [];
    foreach ($homeworkRows as $row) {
        $hwMap[(int)$row['id']] = $row;
    }

    foreach ($result['daily'] as $day) {
        foreach ($day['tasks'] as $task) {
            $taskStmt->bind_param('iisdii', $planId, $task['homeworkId'], $day['date'], $task['amount'], $task['estimatedMinutes'], $sortOrder);
            $taskStmt->execute();
            $sortOrder++;
            $createdTasks++;
        }
    }

    // 更新 allocated_range
    $rangeStmt = $conn->prepare('UPDATE homework SET allocated_range = ? WHERE id = ? AND plan_id = ? AND user_id = ?');
    foreach ($result['allocatedRanges'] as $ar) {
        $rangeStr = $ar['range'];
        $hwId = $ar['homeworkId'];
        $rangeStmt->bind_param('siii', $rangeStr, $hwId, $planId, $userId);
        $rangeStmt->execute();
    }

    $conn->commit();

    echo json_encode([
        'planId' => $planId,
        'name' => $planName,
        'startDate' => $startDate,
        'endDate' => $endDate,
        'totalWorkMinutes' => array_sum(array_map(fn($d) => $d['totalMinutes'], $result['daily'])),
        'availableDays' => $N,
        'createdTasks' => $createdTasks,
        'targetEndDate' => $result['targetEndDate'],
        'allocatedRanges' => $result['allocatedRanges'],
        'warnings' => $result['warnings'],
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => '生成失败: ' . $e->getMessage(), 'code' => 'DB_ERROR']);
}
