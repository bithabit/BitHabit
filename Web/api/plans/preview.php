<?php
/**
 * BitHabit - 预览分配（不写入数据库）
 *
 * POST /api/plans/preview.php
 * 
 * 节奏权重曲线 + 时间窗口 + 每日上限 → 返回每日分配预览
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';

$user = requireAuth();
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 POST', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$conn = getDbConnection();

$planId = (int)($input['planId'] ?? 0);
$rhythm = (int)($input['rhythm'] ?? 0);
$rhythm = max(-2, min(2, $rhythm)); // clamp
$maxDailyMinutes = (int)($input['maxDailyMinutes'] ?? 300);
$homeworkOverrides = $input['homeworkOverrides'] ?? [];

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

// 获取日程约束（用于过滤全天休息日）
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

// 生成可用日期列表
$dates = [];
for ($d = $startTs; $d <= $endTs; $d += 86400) {
    $dateStr = date('Y-m-d', $d);
    $dow = (int)date('w', $d);
    $isFullDayBlocked = false;

    foreach ($weeklySchedule as $wsItem) {
        if ($wsItem['day_of_week'] === $dow && $wsItem['start_time'] === null && $wsItem['end_time'] === null) {
            $isFullDayBlocked = true; break;
        }
    }
    if ($isFullDayBlocked) continue;

    foreach ($specialSchedule as $ssItem) {
        if ($dateStr >= $ssItem['date_from'] && $dateStr <= ($ssItem['date_to'] ?? $ssItem['date_from'])) {
            if ($ssItem['start_time'] === null && $ssItem['end_time'] === null) {
                $isFullDayBlocked = true; break;
            }
        }
    }
    if ($isFullDayBlocked) continue;

    $dates[] = ['date' => $dateStr, 'idx' => count($dates)];
}

$N = count($dates);
if ($N === 0) {
    echo json_encode(['daily' => [], 'warnings' => ['可用天数为 0']]);
    exit;
}

// 生成权重曲线
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

// 获取作业列表（含约束字段）
$hwStmt = $conn->prepare(
    'SELECT id, subject, task_type, total_amount, unit, time_per_unit, window_start, window_end, locked
     FROM homework WHERE plan_id = ? AND user_id = ?'
);
$hwStmt->bind_param('ii', $planId, $userId);
$hwStmt->execute();
$homeworkRows = $hwStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 应用 homeworkOverrides
$overridesMap = [];
foreach ($homeworkOverrides as $ov) {
    $hid = (int)($ov['homeworkId'] ?? 0);
    if ($hid > 0) $overridesMap[$hid] = $ov;
}

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

// 分配算法
$allocation = []; // [dayIdx][hwIdx] => units
for ($i = 0; $i < $N; $i++) $allocation[$i] = [];

$warnings = [];

foreach ($homework as $hwIdx => $hw) {
    $hwId = $hw['id'];
    $totalUnits = (int)$hw['total_amount'];
    if ($totalUnits <= 0) continue;

    // 确定有效天数范围（时间窗口）
    $winStart = $hw['window_start'] ?? $startDate;
    $winEnd = $hw['window_end'] ?? $endDate;

    // 找出窗口内的天数索引
    $windowIndices = [];
    foreach ($dates as $di => $day) {
        if ($day['date'] >= $winStart && $day['date'] <= $winEnd) {
            $windowIndices[] = $di;
        }
    }

    if (empty($windowIndices)) continue; // 窗口外

    $windowWeights = [];
    $totalWeight = 0;
    foreach ($windowIndices as $di) {
        $w = $weights[$di];
        $windowWeights[$di] = $w;
        $totalWeight += $w;
    }

    if ($totalWeight <= 0) continue;

    // 按权重分配整数单位
    $baseUnits = (int)($totalUnits * $weights[$windowIndices[0]] / $totalWeight); // placeholder
    // Better: proportional allocation with integer rounding
    $raw = [];
    $rawTotal = 0;
    foreach ($windowIndices as $di) {
        $fractional = $totalUnits * $windowWeights[$di] / $totalWeight;
        $intPart = (int)$fractional;
        $raw[$di] = ['int' => $intPart, 'frac' => $fractional - $intPart];
        $rawTotal += $intPart;
    }

    // 分配余数
    $remaining = $totalUnits - $rawTotal;
    if ($remaining > 0) {
        uasort($raw, function ($a, $b) { return $b['frac'] <=> $a['frac']; });
        foreach ($raw as $di => &$v) {
            if ($remaining <= 0) break;
            $v['int']++;
            $remaining--;
        }
        unset($v);
    }

    foreach ($raw as $di => $v) {
        if ($v['int'] > 0) {
            $allocation[$di][$hwIdx] = $v['int'];
        }
    }
}

// 超限处理
for ($di = 0; $di < $N; $di++) {
    $dayMinutes = 0;
    foreach ($allocation[$di] as $hwIdx => $units) {
        $dayMinutes += $units * $homework[$hwIdx]['time_per_unit'];
    }

    if ($dayMinutes > $maxDailyMinutes) {
        // 尝试向相邻日期溢出
        $hwIndices = array_keys($allocation[$di]);
        usort($hwIndices, function ($a, $b) use ($allocation, $di, $homework) {
            return ($allocation[$di][$b] * $homework[$b]['time_per_unit'])
                 - ($allocation[$di][$a] * $homework[$a]['time_per_unit']);
        });

        $overflow = $dayMinutes - $maxDailyMinutes;
        foreach ($hwIndices as $hwIdx) {
            $unitMinutes = $homework[$hwIdx]['time_per_unit'];
            while ($overflow > 0 && ($allocation[$di][$hwIdx] ?? 0) > 0) {
                // 先尝试前一天
                $moved = false;
                foreach ([$di - 1, $di + 1] as $td) {
                    if ($td < 0 || $td >= $N) continue;
                    $targetMin = 0;
                    foreach ($allocation[$td] as $tid => $tu) {
                        $targetMin += $tu * $homework[$tid]['time_per_unit'];
                    }
                    if ($targetMin + $unitMinutes <= $maxDailyMinutes) {
                        $allocation[$di][$hwIdx]--;
                        $allocation[$td][$hwIdx] = ($allocation[$td][$hwIdx] ?? 0) + 1;
                        $dayMinutes -= $unitMinutes;
                        $overflow -= $unitMinutes;
                        $moved = true;
                        break;
                    }
                }
                if (!$moved) break;
            }
        }

        // 再次校验
        $finalMinutes = 0;
        foreach ($allocation[$di] as $hwIdx => $units) {
            $finalMinutes += $units * $homework[$hwIdx]['time_per_unit'];
        }
        if ($finalMinutes > $maxDailyMinutes) {
            $warnings[] = $dates[$di]['date'] . " 超出每日上限 {$maxDailyMinutes} 分钟（实际 {$finalMinutes} 分钟）";
        }
    }
}

// 构建输出
$daily = [];
foreach ($dates as $di => $day) {
    $tasks = [];
    $totalMin = 0;
    foreach ($allocation[$di] as $hwIdx => $units) {
        if ($units <= 0) continue;
        $hw = $homework[$hwIdx];
        $estMin = $units * $hw['time_per_unit'];
        $tasks[] = [
            'homeworkId' => $hw['id'],
            'subject' => $hw['subject'],
            'taskType' => $hw['task_type'],
            'amount' => $units,
            'unit' => $hw['unit'],
            'estimatedMinutes' => $estMin,
        ];
        $totalMin += $estMin;
    }

    if (!empty($tasks)) {
        usort($tasks, fn($a, $b) => strcmp($a['subject'], $b['subject']));
    }

    $daily[] = [
        'date' => $day['date'],
        'totalMinutes' => $totalMin,
        'overLimit' => $totalMin > $maxDailyMinutes,
        'tasks' => $tasks,
    ];
}

echo json_encode([
    'daily' => $daily,
    'warnings' => $warnings,
    'stats' => [
        'availableDays' => $N,
        'maxDailyMinutes' => $maxDailyMinutes,
        'rhythm' => $rhythm,
    ],
], JSON_UNESCAPED_UNICODE);
