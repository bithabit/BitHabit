<?php

/**
 * BitHabit - 生成暑假作业计划 (v2)
 *
 * POST /api/plans/generate.php
 *
 * 节奏权重曲线分配：
 * 1. 节奏(rhythm) → 权重曲线 → 整数分配
 * 2. 时间窗口约束 + 每日上限
 * 3. 写入 plan_tasks（保留锁定任务）
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// 丢弃任何在 header 之前产生的输出
while (ob_get_level()) ob_end_clean();

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

$planId = (int)($input['planId'] ?? 0);
$rhythm = max(-2, min(2, (int)($input['rhythm'] ?? 0)));
$maxDailyMinutes = (int)($input['maxDailyMinutes'] ?? 300);
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
$totalAvailableMinutes = 0;

for ($d = $startTs; $d <= $endTs; $d += 86400) {
    $dateStr = date('Y-m-d', $d);
    $dow = (int)date('w', $d);
    $blocked = false;
    $blockedMin = 0;

    foreach ($weeklySchedule as $wsItem) {
        if ($wsItem['day_of_week'] === $dow) {
            if ($wsItem['start_time'] === null && $wsItem['end_time'] === null) { $blocked = true; break; }
            $bs = timeToMinutes($wsItem['start_time']); $be = timeToMinutes($wsItem['end_time']);
            $blockedMin += max(0, $be - $bs);
        }
    }
    if ($blocked) continue;

    foreach ($specialSchedule as $ssItem) {
        if ($dateStr >= $ssItem['date_from'] && $dateStr <= ($ssItem['date_to'] ?? $ssItem['date_from'])) {
            if ($ssItem['start_time'] === null && $ssItem['end_time'] === null) { $blocked = true; break; }
            $bs = timeToMinutes($ssItem['start_time']); $be = timeToMinutes($ssItem['end_time']);
            $blockedMin += max(0, $be - $bs);
        }
    }
    if ($blocked) continue;

    $avail = max(0, 14 * 60 - $blockedMin); // 14h default
    if ($avail <= 0) continue;
    $dates[] = ['date' => $dateStr, 'idx' => count($dates)];
    $totalAvailableMinutes += $avail;
}

$N = count($dates);
if ($N === 0) { http_response_code(400); echo json_encode(['error' => '无可用日期', 'code' => 'NO_AVAILABLE_DAYS']); exit; }

// === 权重曲线 ===
$weights = [];
for ($i = 0; $i < $N; $i++) {
    $t = ($N > 1) ? $i / ($N - 1) : 0.5;
    switch ($rhythm) {
        case -2: $w = 1 - 0.7 * $t; break;
        case -1: $w = 1 - 0.35 * $t; break;
        case 1:  $w = 0.65 + 0.35 * $t; break;
        case 2:  $w = 0.3 + 0.7 * $t; break;
        default: $w = 1.0;
    }
    $weights[] = max(0.01, $w);
}

// === 获取作业 ===
$hwStmt = $conn->prepare(
    'SELECT id, subject, task_type, total_amount, unit, time_per_unit, window_start, window_end, locked FROM homework WHERE plan_id = ? AND user_id = ?'
);
$hwStmt->bind_param('ii', $planId, $userId);
$hwStmt->execute();
$homeworkRows = $hwStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$overridesMap = [];
foreach ($homeworkOverrides as $ov) { $hid = (int)($ov['homeworkId'] ?? 0); if ($hid > 0) $overridesMap[$hid] = $ov; }

$homework = [];
foreach ($homeworkRows as $row) {
    $row['id'] = (int)$row['id'];
    $row['total_amount'] = (float)$row['total_amount'];
    $row['time_per_unit'] = $row['time_per_unit'] !== null ? (int)$row['time_per_unit'] : 0;
    if ($row['time_per_unit'] <= 0) continue;
    $hid = $row['id'];
    if (isset($overridesMap[$hid])) {
        $ov = $overridesMap[$hid];
        $row['window_start'] = $ov['windowStart'] ?? $row['window_start'];
        $row['window_end'] = $ov['windowEnd'] ?? $row['window_end'];
        $row['locked'] = isset($ov['locked']) ? (int)$ov['locked'] : (int)$row['locked'];
    }
    $homework[] = $row;
}

if (empty($homework)) {
    http_response_code(400);
    echo json_encode(['error' => '请先录入作业', 'code' => 'NO_HOMEWORK']);
    exit;
}

// === 分配算法（同 preview.php）===
$allocation = []; for ($i = 0; $i < $N; $i++) $allocation[$i] = [];
$warnings = [];

foreach ($homework as $hwIdx => $hw) {
    $totalUnits = (int)$hw['total_amount'];
    if ($totalUnits <= 0) continue;
    $winStart = $hw['window_start'] ?? $startDate;
    $winEnd = $hw['window_end'] ?? $endDate;

    $windowIndices = [];
    foreach ($dates as $di => $day) {
        if ($day['date'] >= $winStart && $day['date'] <= $winEnd) $windowIndices[] = $di;
    }
    if (empty($windowIndices)) continue;

    $totalWeight = 0; $windowWeights = [];
    foreach ($windowIndices as $di) { $w = $weights[$di]; $windowWeights[$di] = $w; $totalWeight += $w; }
    if ($totalWeight <= 0) continue;

    $raw = []; $rawTotal = 0;
    foreach ($windowIndices as $di) {
        $frac = $totalUnits * $windowWeights[$di] / $totalWeight;
        $intPart = (int)$frac;
        $raw[$di] = ['int' => $intPart, 'frac' => $frac - $intPart];
        $rawTotal += $intPart;
    }
    $remaining = $totalUnits - $rawTotal;
    if ($remaining > 0) {
        uasort($raw, fn($a, $b) => $b['frac'] <=> $a['frac']);
        foreach ($raw as &$v) { if ($remaining <= 0) break; $v['int']++; $remaining--; }
        unset($v);
    }
    foreach ($raw as $di => $v) { if ($v['int'] > 0) $allocation[$di][$hwIdx] = $v['int']; }
}

// 超限溢出
for ($di = 0; $di < $N; $di++) {
    $dayMinutes = 0;
    foreach ($allocation[$di] as $hwIdx => $units) $dayMinutes += $units * $homework[$hwIdx]['time_per_unit'];
    if ($dayMinutes <= $maxDailyMinutes) continue;

    $hwIndices = array_keys($allocation[$di]);
    usort($hwIndices, fn($a, $b) => ($allocation[$di][$b] * $homework[$b]['time_per_unit']) - ($allocation[$di][$a] * $homework[$a]['time_per_unit']));

    foreach ($hwIndices as $hwIdx) {
        $uMin = $homework[$hwIdx]['time_per_unit'];
        while (($allocation[$di][$hwIdx] ?? 0) > 0 && $dayMinutes > $maxDailyMinutes) {
            $moved = false;
            foreach ([$di - 1, $di + 1] as $td) {
                if ($td < 0 || $td >= $N) continue;
                $tMin = 0; foreach ($allocation[$td] as $tid => $tu) $tMin += $tu * $homework[$tid]['time_per_unit'];
                if ($tMin + $uMin <= $maxDailyMinutes) {
                    $allocation[$di][$hwIdx]--; $allocation[$td][$hwIdx] = ($allocation[$td][$hwIdx] ?? 0) + 1;
                    $dayMinutes -= $uMin; $moved = true; break;
                }
            }
            if (!$moved) break;
        }
    }
    $finalMin = 0; foreach ($allocation[$di] as $hwIdx => $units) $finalMin += $units * $homework[$hwIdx]['time_per_unit'];
    if ($finalMin > $maxDailyMinutes) $warnings[] = $dates[$di]['date'] . " 超出上限 {$maxDailyMinutes}分钟（{$finalMin}分钟）";
}

// === 写入 DB ===
$conn->begin_transaction();
try {
    // 删除非锁定的未完成任务（保留已完成 + 锁定）
    $del = $conn->prepare('DELETE pt FROM plan_tasks pt
        JOIN homework h ON h.id = pt.homework_id AND h.plan_id = ?
        WHERE pt.plan_id = ? AND (h.locked = 0 OR pt.completed = 0)');
    $del->bind_param('ii', $planId, $planId);
    $del->execute();

    // 写入新任务
    $taskStmt = $conn->prepare(
        'INSERT INTO plan_tasks (plan_id, homework_id, date, amount, estimated_minutes, sort_order) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $sortOrder = 0;
    $createdTasks = 0;
    foreach ($dates as $di => $day) {
        foreach ($allocation[$di] as $hwIdx => $units) {
            if ($units <= 0) continue;
            $hw = $homework[$hwIdx];
            $estMin = $units * $hw['time_per_unit'];
            $taskStmt->bind_param('iisdii', $planId, $hw['id'], $day['date'], $units, $estMin, $sortOrder);
            $taskStmt->execute();
            $sortOrder++; $createdTasks++;
        }
    }

    $conn->commit();

    // 计算总作业时间（安全包裹，不中断 JSON 输出）
    $totalWorkMinutes = 0;
    try {
        foreach ($dates as $di => $day) {
            foreach ($allocation[$di] as $hwIdx => $units) {
                if ($units <= 0) continue;
                $totalWorkMinutes += $units * $homework[$hwIdx]['time_per_unit'];
            }
        }
    } catch (\Throwable $ignored) {}

    echo json_encode([
        'planId' => $planId,
        'name' => $planName,
        'startDate' => $startDate,
        'endDate' => $endDate,
        'totalWorkMinutes' => $totalWorkMinutes,
        'availableDays' => $N,
        'totalAvailableMinutes' => $totalAvailableMinutes,
        'createdTasks' => $createdTasks,
        'warnings' => $warnings,
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => '生成失败: ' . $e->getMessage(), 'code' => 'DB_ERROR']);
}

function timeToMinutes(?string $time): int {
    if ($time === null) return 0;
    $parts = explode(':', $time);
    return ((int)($parts[0] ?? 0)) * 60 + (int)($parts[1] ?? 0);
}
