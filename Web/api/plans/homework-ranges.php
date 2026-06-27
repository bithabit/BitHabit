<?php
/**
 * BitHabit - 获取作业预估时间范围 (v3)
 *
 * POST /api/plans/homework-ranges.php?planId=X
 *
 * 轻量预览，仅返回每项作业的预估日期范围 + 自动推荐间隔。
 * 不返回每日明细。
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/scheduler.php';

$user = requireAuth();
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 POST', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$conn = getDbConnection();

$planId = (int)($_GET['planId'] ?? 0);
$input = json_decode(file_get_contents('php://input'), true);
$rhythm = max(-2, min(2, (int)($input['rhythm'] ?? 0)));
$maxDailyMinutes = (int)($input['maxDailyMinutes'] ?? 300);

if ($planId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => '缺少 planId', 'code' => 'INVALID_INPUT']);
    exit;
}

// 验证计划归属
$planStmt = $conn->prepare('SELECT id, start_date, end_date FROM plans WHERE id = ? AND user_id = ?');
$planStmt->bind_param('ii', $planId, $userId);
$planStmt->execute();
$plan = $planStmt->get_result()->fetch_assoc();
if (!$plan) {
    http_response_code(404);
    echo json_encode(['error' => '计划不存在', 'code' => 'NOT_FOUND']);
    exit;
}

$startDate = $plan['start_date'];
$endDate = $plan['end_date'];
$startTs = strtotime($startDate);
$endTs = strtotime($endDate);

// 日程过滤
$weeklySchedule = [];
$ws = $conn->prepare('SELECT day_of_week, start_time, end_time FROM schedule_weekly WHERE user_id = ?');
$ws->bind_param('i', $userId);
$ws->execute();
$wsr = $ws->get_result();
while ($r = $wsr->fetch_assoc()) { $r['day_of_week'] = (int)$r['day_of_week']; $weeklySchedule[] = $r; }

$specialSchedule = [];
$ss = $conn->prepare('SELECT date_from, date_to, start_time, end_time FROM schedule_special WHERE user_id = ?');
$ss->bind_param('i', $userId);
$ss->execute();
$ssr = $ss->get_result();
while ($r = $ssr->fetch_assoc()) { $specialSchedule[] = $r; }

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
    echo json_encode(['ranges' => []]);
    exit;
}

// 获取作业 (直接用数据库值，不用 overrides)
$hwStmt = $conn->prepare(
    'SELECT id, subject, task_type, total_amount, unit, time_per_unit,
            window_start, window_end, locked, priority, interval_days
     FROM homework WHERE plan_id = ? AND user_id = ?'
);
$hwStmt->bind_param('ii', $planId, $userId);
$hwStmt->execute();
$homeworkRows = $hwStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 调用完整分配算法但不传 overrides（使用数据库当前值）
$allocResult = allocatePlan($homeworkRows, $dates, $rhythm, $maxDailyMinutes, null, []);

// 整理返回：只返回 ranges
$hwMap = [];
foreach ($homeworkRows as $row) {
    $hwMap[(int)$row['id']] = $row;
}

$ranges = [];
foreach ($allocResult['allocatedRanges'] as $ar) {
    $hwId = $ar['homeworkId'];
    if (isset($hwMap[$hwId])) {
        $hw = $hwMap[$hwId];
        $ranges[] = [
            'homeworkId' => $hwId,
            'subject' => $hw['subject'],
            'taskType' => $hw['task_type'],
            'range' => $ar['range'],
        ];
    }
}

echo json_encode(['ranges' => $ranges], JSON_UNESCAPED_UNICODE);
