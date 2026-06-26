<?php
/**
 * BitHabit - 生成暑假作业计划
 *
 * POST /api/plans/generate.php
 *
 * 平均分配算法 v1：
 * 1. 汇总总工时
 * 2. 生成日期列表 between(start, end)
 * 3. 过滤全天休息/占用日
 * 4. 计算每日可用分钟（减去部分时段占用）
 * 5. 检查总工时 ≤ 总可用分钟
 * 6. 每天配额 = 总工时 / 可用天数
 * 7. 逐天轮询各科分配任务
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';

$user = requireAuth();
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 POST 方法', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$conn = getDbConnection();

// --- 参数解析 & 校验 ---
$planName = trim($input['name'] ?? '暑假作业计划');
$startDate = $input['startDate'] ?? $input['start_date'] ?? '';
$endDate = $input['endDate'] ?? $input['end_date'] ?? '';
$dailyStartTime = $input['dailyStartTime'] ?? $input['daily_start_time'] ?? '08:00:00';
$dailyEndTime = $input['dailyEndTime'] ?? $input['daily_end_time'] ?? '22:00:00';
$strategy = $input['strategy'] ?? 'average';

if (empty($startDate) || empty($endDate)) {
    http_response_code(400);
    echo json_encode(['error' => '开始和结束日期为必填', 'code' => 'INVALID_INPUT']);
    exit;
}

$startTs = strtotime($startDate);
$endTs = strtotime($endDate);

if (!$startTs || !$endTs || $startTs > $endTs) {
    http_response_code(400);
    echo json_encode(['error' => '日期格式错误或开始日期晚于结束日期', 'code' => 'INVALID_DATE']);
    exit;
}

// --- 1. 获取作业列表 ---
$stmt = $conn->prepare(
    'SELECT id, subject, task_type, total_amount, unit, time_per_unit 
     FROM homework WHERE user_id = ? ORDER BY subject'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$homeworkResult = $stmt->get_result();

$homework = [];
$totalWorkMinutes = 0;

while ($row = $homeworkResult->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['total_amount'] = (float)$row['total_amount'];
    $row['time_per_unit'] = $row['time_per_unit'] !== null ? (int)$row['time_per_unit'] : 0;
    $row['remaining'] = $row['total_amount'];  // 剩余分配量

    if ($row['time_per_unit'] <= 0) {
        // 无耗时信息的作业无法参与分配
        continue;
    }

    $row['total_minutes'] = $row['total_amount'] * $row['time_per_unit'];
    $totalWorkMinutes += $row['total_minutes'];
    $homework[] = $row;
}

if (empty($homework)) {
    http_response_code(400);
    echo json_encode(['error' => '请先录入至少一条有耗时信息的作业', 'code' => 'NO_HOMEWORK']);
    exit;
}

// --- 2. 获取日程配置 ---
$weeklyStmt = $conn->prepare(
    'SELECT day_of_week, start_time, end_time FROM schedule_weekly WHERE user_id = ?'
);
$weeklyStmt->bind_param('i', $userId);
$weeklyStmt->execute();
$weeklyResult = $weeklyStmt->get_result();
$weeklySchedule = [];
while ($row = $weeklyResult->fetch_assoc()) {
    $row['day_of_week'] = (int)$row['day_of_week'];
    $weeklySchedule[] = $row;
}

$specialStmt = $conn->prepare(
    'SELECT date_from, date_to, start_time, end_time FROM schedule_special WHERE user_id = ?'
);
$specialStmt->bind_param('i', $userId);
$specialStmt->execute();
$specialResult = $specialStmt->get_result();
$specialSchedule = [];
while ($row = $specialResult->fetch_assoc()) {
    $specialSchedule[] = $row;
}

// --- 3. 生成日期列表并过滤 ---
$dailyStartMinutes = timeToMinutes($dailyStartTime);
$dailyEndMinutes = timeToMinutes($dailyEndTime);

$dates = [];
$totalAvailableMinutes = 0;

for ($d = $startTs; $d <= $endTs; $d += 86400) {
    $dateStr = date('Y-m-d', $d);
    $dow = (int)date('w', $d); // 0=Sun

    // 检查是否是全天休息/占用日
    $isFullDayBlocked = false;
    $blockedMinutes = 0;

    // 每周固定
    foreach ($weeklySchedule as $ws) {
        if ($ws['day_of_week'] === $dow) {
            if ($ws['start_time'] === null && $ws['end_time'] === null) {
                // 全天占用
                $isFullDayBlocked = true;
                break;
            }
            // 部分时段占用
            $bs = timeToMinutes($ws['start_time']);
            $be = timeToMinutes($ws['end_time']);
            $blockedMinutes += max(0, min($be, $dailyEndMinutes) - max($bs, $dailyStartMinutes));
        }
    }

    if ($isFullDayBlocked) continue;

    // 特殊日期
    foreach ($specialSchedule as $ss) {
        $ssFrom = $ss['date_from'];
        $ssTo = $ss['date_to'] ?? $ss['date_from'];
        if ($dateStr >= $ssFrom && $dateStr <= $ssTo) {
            if ($ss['start_time'] === null && $ss['end_time'] === null) {
                $isFullDayBlocked = true;
                break;
            }
            $bs = timeToMinutes($ss['start_time']);
            $be = timeToMinutes($ss['end_time']);
            $blockedMinutes += max(0, min($be, $dailyEndMinutes) - max($bs, $dailyStartMinutes));
        }
    }

    if ($isFullDayBlocked) continue;

    $availableMinutes = max(0, ($dailyEndMinutes - $dailyStartMinutes) - $blockedMinutes);
    if ($availableMinutes <= 0) continue;

    $dates[] = [
        'date' => $dateStr,
        'availableMinutes' => $availableMinutes,
        'allocatedMinutes' => 0,
        'tasks' => [],
    ];
    $totalAvailableMinutes += $availableMinutes;
}

$availableDays = count($dates);

if ($availableDays === 0) {
    http_response_code(400);
    echo json_encode(['error' => '所选日期范围内没有可用学习日，请调整日程或日期范围', 'code' => 'NO_AVAILABLE_DAYS']);
    exit;
}

// --- 4. 检查总工时 ---
if ($totalWorkMinutes > $totalAvailableMinutes) {
    http_response_code(400);
    echo json_encode([
        'error' => "总工时 {$totalWorkMinutes} 分钟超出可用 {$totalAvailableMinutes} 分钟（{$availableDays} 天），请减少作业或扩大日期范围",
        'code' => 'INSUFFICIENT_TIME',
        'totalWorkMinutes' => $totalWorkMinutes,
        'totalAvailableMinutes' => $totalAvailableMinutes,
        'availableDays' => $availableDays,
    ]);
    exit;
}

// --- 5. 平均分配算法 v1 ---
// 每天配额 = 总工时 / 可用天数
// 每天内按比例分配给各科，按 subject 字母序排列

$dailyQuota = $totalWorkMinutes / $availableDays;
$taskOrder = array_keys($homework);

error_log("Plan: totalWork={$totalWorkMinutes}, availableDays={$availableDays}, dailyQuota={$dailyQuota}");

foreach ($dates as &$day) {
    $dayRemaining = $dailyQuota;

    foreach ($taskOrder as $hwIdx) {
        $hw = &$homework[$hwIdx];
        if ($hw['remaining'] <= 0) continue;

        // 该科目占总工时的比例
        $subjectTotalMin = $hw['total_amount'] * $hw['time_per_unit'];
        $proportion = $subjectTotalMin / $totalWorkMinutes;
        $allocMinutes = $dailyQuota * $proportion;

        // 不能超过该科剩余
        $maxForSubject = $hw['remaining'] * $hw['time_per_unit'];
        $allocMinutes = min($allocMinutes, $maxForSubject, $dayRemaining);

        if ($allocMinutes <= 0) continue;

        // 至少1个单位（如果能装下的话）
        if ($allocMinutes < $hw['time_per_unit'] && $dayRemaining >= $hw['time_per_unit']) {
            $allocMinutes = $hw['time_per_unit'];
        }

        $amount = round($allocMinutes / $hw['time_per_unit'], 2);
        $amount = min($amount, $hw['remaining']);
        if ($amount <= 0) continue;

        $actualMinutes = $amount * $hw['time_per_unit'];

        $hw['remaining'] = round($hw['remaining'] - $amount, 2);
        $dayRemaining -= $actualMinutes;

        $day['tasks'][] = [
            'homework_id' => $hw['id'],
            'subject' => $hw['subject'],
            'task_type' => $hw['task_type'],
            'amount' => round($amount, 2),
            'unit' => $hw['unit'],
            'estimated_minutes' => (int)round($actualMinutes),
        ];
    }
    unset($hw);

    $day['allocatedMinutes'] = $dailyQuota - max(0, $dayRemaining);

    // 按 subject 字母序排列当天任务
    usort($day['tasks'], function ($a, $b) {
        return strcmp($a['subject'], $b['subject']);
    });
}
unset($day);

// --- 6. 写入数据库 ---
$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        'INSERT INTO plans (user_id, name, start_date, end_date, daily_start_time, daily_end_time, strategy) 
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssss', $userId, $planName, $startDate, $endDate, $dailyStartTime, $dailyEndTime, $strategy);
    $stmt->execute();
    $planId = $stmt->insert_id;

    $taskStmt = $conn->prepare(
        'INSERT INTO plan_tasks (plan_id, homework_id, date, amount, estimated_minutes, sort_order) 
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $sortOrder = 0;
    foreach ($dates as $day) {
        foreach ($day['tasks'] as $task) {
            $taskStmt->bind_param(
                'iisdii',
                $planId,
                $task['homework_id'],
                $day['date'],
                $task['amount'],
                $task['estimated_minutes'],
                $sortOrder
            );
            $taskStmt->execute();
            $sortOrder++;
        }
    }

    $conn->commit();

    echo json_encode([
        'planId' => $planId,
        'name' => $planName,
        'startDate' => $startDate,
        'endDate' => $endDate,
        'totalWorkMinutes' => $totalWorkMinutes,
        'availableDays' => $availableDays,
        'totalAvailableMinutes' => $totalAvailableMinutes,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => '生成计划失败: ' . $e->getMessage(), 'code' => 'DB_ERROR']);
}

// --- 工具函数 ---
function timeToMinutes(?string $time): int {
    if ($time === null) return 0;
    $parts = explode(':', $time);
    return ((int)($parts[0] ?? 0)) * 60 + (int)($parts[1] ?? 0);
}
