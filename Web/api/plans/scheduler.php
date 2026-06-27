<?php
/**
 * BitHabit - 公共分配算法 (v3)
 *
 * 多阶段分配：
 *   0. 预处理 (按 priority 排序)
 *   1. 间隔过滤 (interval_days)
 *   2. 自动间隔推荐
 *   3. 目标完成日期缩放
 *   4. 节奏权重
 *   5. 按优先级顺序分配
 *   6. 整数取整 + 溢出处
 *   7. allocatedRanges 计算
 *
 * 函数：allocatePlan($homework, $dates, $rhythm, $maxDailyMinutes, $targetEndDate, $homeworkOverrides)
 */

/**
 * 执行多阶段分配
 *
 * @param array $homework       作业列表 (含 id, total_amount, time_per_unit, window_start, window_end, locked, priority, interval_days)
 * @param array $dates          可用日期列表 [['date'=>'Y-m-d', 'idx'=>N], ...]
 * @param int   $rhythm         节奏偏好 (-2..2)
 * @param int   $maxDailyMinutes 每日上限 (分钟)
 * @param string|null $targetEndDate 目标完成日期 (null=不调整)
 * @param array $overridesMap   字段覆盖 map [homeworkId => [windowStart, windowEnd, intervalDays, locked]]
 *
 * @return array ['daily'=>[], 'allocatedRanges'=>[], 'targetEndDate'=>string, 'warnings'=>[], 'stats'=>[]]
 */
function allocatePlan(array $homework, array $dates, int $rhythm, int $maxDailyMinutes, ?string $targetEndDate, array $overridesMap = []): array {
    $N = count($dates);
    $warnings = [];

    // === 阶段 0：预处理 ===

    // 应用 overrides + 过滤锁定
    $processed = [];
    foreach ($homework as $hw) {
        $hid = (int)$hw['id'];
        if (isset($overridesMap[$hid])) {
            $ov = $overridesMap[$hid];
            if (isset($ov['windowStart'])) $hw['window_start'] = $ov['windowStart'];
            if (isset($ov['windowEnd'])) $hw['window_end'] = $ov['windowEnd'];
            if (isset($ov['intervalDays'])) $hw['interval_days'] = (int)$ov['intervalDays'];
            if (isset($ov['locked'])) $hw['locked'] = (int)$ov['locked'];
        }
        $hw['id'] = $hid;
        $hw['total_amount'] = (float)$hw['total_amount'];
        $hw['time_per_unit'] = (int)($hw['time_per_unit'] ?? 0);
        $hw['priority'] = (int)($hw['priority'] ?? 0);
        $hw['interval_days'] = (int)($hw['interval_days'] ?? 0);
        $hw['locked'] = (int)($hw['locked'] ?? 0);

        if ($hw['time_per_unit'] <= 0 || $hw['total_amount'] <= 0) continue;
        if ($hw['locked'] === 1) continue; // 锁定项不参与重新分配

        $processed[] = $hw;
    }

    if (empty($processed)) {
        $daily = array_map(fn($d) => [
            'date' => $d['date'],
            'totalMinutes' => 0,
            'overLimit' => false,
            'tasks' => [],
        ], $dates);
        return [
            'daily' => $daily,
            'allocatedRanges' => [],
            'targetEndDate' => $dates[0]['date'],
            'warnings' => ['没有可分配的作业'],
            'stats' => ['availableDays' => $N, 'maxDailyMinutes' => $maxDailyMinutes, 'rhythm' => $rhythm],
        ];
    }

    // 按 priority ASC 排序 (priority 小优先)
    usort($processed, fn($a, $b) => $a['priority'] <=> $b['priority']);

    // === 生成权重曲线 ===
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

    // === 阶段 1-3：每个作业的有效天数计算 ===
    $effectiveDaysPerHw = [];
    $planStartDate = $dates[0]['date'];
    $planEndDate = $dates[count($dates) - 1]['date'];

    // 如果指定了 targetEndDate，限制可用日期范围
    $filteredDates = $dates;
    if ($targetEndDate !== null && $targetEndDate !== '') {
        $targetTs = strtotime($targetEndDate);
        $filteredDates = [];
        foreach ($dates as $d) {
            if (strtotime($d['date']) <= $targetTs) {
                $filteredDates[] = $d;
            }
        }
        if (empty($filteredDates)) {
            $filteredDates = [$dates[0]];
        }
    }

    foreach ($processed as $hwIdx => $hw) {
        $winStart = $hw['window_start'] ?? $planStartDate;
        $winEnd = $hw['window_end'] ?? $planEndDate;
        $totalUnits = (int)$hw['total_amount'];

        // 窗口内所有天 (含 targetEndDate 过滤)
        $windowDays = [];
        foreach ($filteredDates as $d) {
            if ($d['date'] >= $winStart && $d['date'] <= $winEnd) {
                $windowDays[] = $d['idx'];
            }
        }
        if (empty($windowDays)) {
            $effectiveDaysPerHw[$hwIdx] = [];
            continue;
        }

        $M = count($windowDays);

        // 阶段 2：自动间隔推荐
        $intervalDays = $hw['interval_days'];
        if ($intervalDays === 0 && $totalUnits > 0) {
            // 自动计算：保证总单位能均匀覆盖
            // floor(M / totalUnits) - 1，至少 0
            $autoInterval = max(0, (int)($M / $totalUnits) - 1);
            $intervalDays = $autoInterval;
        }

        // 阶段 1：间隔过滤
        if ($intervalDays > 0) {
            $effective = [];
            $step = $intervalDays + 1; // 隔 intervalDays 天 = 每 step 天一次
            for ($i = 0; $i < count($windowDays); $i += $step) {
                $effective[] = $windowDays[$i];
            }
            // 如果结果为空（比如间隔太大导致没取到），退化为窗口内所有天
            if (empty($effective)) {
                $effective = $windowDays;
            }
            $effectiveDaysPerHw[$hwIdx] = $effective;
        } else {
            $effectiveDaysPerHw[$hwIdx] = $windowDays;
        }
    }

    // === 阶段 5-6：按优先级顺序分配 ===
    $dailyLoad = array_fill(0, $N, 0); // 每天已分配分钟数
    $dailyAlloc = []; // [dayIdx][hwIdx] => units
    for ($i = 0; $i < $N; $i++) $dailyAlloc[$i] = [];

    foreach ($processed as $hwIdx => $hw) {
        $totalUnits = (int)$hw['total_amount'];
        if ($totalUnits <= 0) continue;

        $effDays = $effectiveDaysPerHw[$hwIdx] ?? [];
        if (empty($effDays)) continue;

        $unitMin = $hw['time_per_unit'];

        // 只有容量足够的有效天
        $candidateDays = [];
        foreach ($effDays as $di) {
            $remaining = $maxDailyMinutes - $dailyLoad[$di];
            if ($remaining >= $unitMin) {
                $candidateDays[] = $di;
            }
        }

        if (empty($candidateDays)) {
            // 退化为有天仍有剩余容量的（哪怕不够 1 个单位）
            foreach ($effDays as $di) {
                $remaining = $maxDailyMinutes - $dailyLoad[$di];
                if ($remaining > 0) {
                    $candidateDays[] = $di;
                }
            }
        }

        if (empty($candidateDays)) {
            // 全满退路：记录警告
            $warnings[] = "{$hw['subject']}·{$hw['task_type']} 无可用容量，跳过";
            continue;
        }

        // 计算候选天的权重
        $totalWeight = 0;
        $dayWeights = [];
        foreach ($candidateDays as $di) {
            $w = $weights[$di];
            $dayWeights[$di] = $w;
            $totalWeight += $w;
        }
        if ($totalWeight <= 0) continue;

        // 按权重比例分配
        $raw = [];
        $rawTotal = 0;
        foreach ($candidateDays as $di) {
            $frac = $totalUnits * $dayWeights[$di] / $totalWeight;
            $intPart = (int)$frac;
            $raw[$di] = ['int' => $intPart, 'frac' => $frac - $intPart];
            $rawTotal += $intPart;
        }

        // 余数分配
        $remainingUnits = $totalUnits - $rawTotal;
        if ($remainingUnits > 0) {
            uasort($raw, fn($a, $b) => $b['frac'] <=> $a['frac']);
            foreach ($raw as &$v) {
                if ($remainingUnits <= 0) break;
                $v['int']++;
                $remainingUnits--;
            }
            unset($v);
        }

        // 容量约束：减少超过容量的分配量
        $overflowUnits = 0;
        foreach ($raw as $di => &$v) {
            if ($v['int'] <= 0) continue;
            $capacityRemaining = $maxDailyMinutes - $dailyLoad[$di];
            $maxFit = (int)($capacityRemaining / $unitMin);
            if ($maxFit < 0) $maxFit = 0;
            if ($v['int'] > $maxFit) {
                $overflowUnits += ($v['int'] - $maxFit);
                $v['int'] = $maxFit;
            }
        }
        unset($v);

        // 溢出的单位尝试放到其他有效天
        if ($overflowUnits > 0) {
            // 收集还有容量的天
            $spareDays = [];
            foreach ($effDays as $di) {
                if (!isset($raw[$di]) || $raw[$di]['int'] === 0) {
                    $remaining = $maxDailyMinutes - $dailyLoad[$di];
                    $maxFit = (int)($remaining / $unitMin);
                    if ($maxFit > 0) {
                        // 用权重排序
                        $spareDays[] = ['di' => $di, 'w' => $weights[$di], 'maxFit' => $maxFit];
                    }
                } else {
                    // 已有分配量，看还能不能加
                    $existing = $raw[$di]['int'];
                    $usedMin = $existing * $unitMin;
                    $remaining = $maxDailyMinutes - $dailyLoad[$di] - $usedMin;
                    $maxFit = (int)($remaining / $unitMin);
                    if ($maxFit > 0) {
                        $spareDays[] = ['di' => $di, 'w' => $weights[$di], 'maxFit' => $maxFit];
                    }
                }
            }

            if (!empty($spareDays)) {
                usort($spareDays, fn($a, $b) => $b['w'] <=> $a['w']); // 高权重优先
                foreach ($spareDays as $sd) {
                    if ($overflowUnits <= 0) break;
                    $assign = min($overflowUnits, $sd['maxFit']);
                    if ($assign > 0) {
                        if (!isset($raw[$sd['di']])) {
                            $raw[$sd['di']] = ['int' => 0, 'frac' => 0];
                        }
                        $raw[$sd['di']]['int'] += $assign;
                        $overflowUnits -= $assign;
                    }
                }
            }
        }

        if ($overflowUnits > 0) {
            $warnings[] = "{$hw['subject']}·{$hw['task_type']} 有 {$overflowUnits}{$hw['unit']} 无法安排（容量不足）";
        }

        // 写入 dailyAlloc 和 dailyLoad
        foreach ($raw as $di => $v) {
            if ($v['int'] > 0) {
                $dailyAlloc[$di][$hwIdx] = $v['int'];
                $dailyLoad[$di] += $v['int'] * $unitMin;
            }
        }
    }

    // === 溢出处理 (阶段 6)：低优先级溢出 ===
    // 对超出上限的天，按优先级从低到高尝试溢出
    for ($di = 0; $di < $N; $di++) {
        $dayMinutes = $dailyLoad[$di];
        if ($dayMinutes <= $maxDailyMinutes) continue;

        $hwIndices = array_keys($dailyAlloc[$di]);
        if (empty($hwIndices)) continue;

        // 按优先级排序 (低优先在前，先被挪)
        usort($hwIndices, function ($a, $b) use ($processed) {
            $pa = $processed[$a]['priority'] ?? 999;
            $pb = $processed[$b]['priority'] ?? 999;
            return $pb <=> $pa; // 高 priority 数值 = 低优先级优先溢出
        });

        foreach ($hwIndices as $hwIdx) {
            if ($dayMinutes <= $maxDailyMinutes) break;
            $uMin = $processed[$hwIdx]['time_per_unit'];
            while (($dailyAlloc[$di][$hwIdx] ?? 0) > 0 && $dayMinutes > $maxDailyMinutes) {
                $moved = false;
                foreach ([$di - 1, $di + 1] as $td) {
                    if ($td < 0 || $td >= $N) continue;
                    $tMin = $dailyLoad[$td];
                    if ($tMin + $uMin <= $maxDailyMinutes) {
                        $dailyAlloc[$di][$hwIdx]--;
                        $dailyAlloc[$td][$hwIdx] = ($dailyAlloc[$td][$hwIdx] ?? 0) + 1;
                        $dayMinutes -= $uMin;
                        $dailyLoad[$di] -= $uMin;
                        $dailyLoad[$td] += $uMin;
                        $moved = true;
                        break;
                    }
                }
                if (!$moved) break;
            }
        }

        if ($dayMinutes > $maxDailyMinutes) {
            $warnings[] = $dates[$di]['date'] . " 超出上限 {$maxDailyMinutes} 分钟（实际 {$dayMinutes} 分钟）";
        }
    }

    // === 阶段 7：计算 allocatedRanges ===
    $allocatedRanges = [];
    foreach ($processed as $hwIdx => $hw) {
        $minDay = null;
        $maxDay = null;
        foreach ($dailyAlloc as $di => $alloc) {
            if (isset($alloc[$hwIdx]) && $alloc[$hwIdx] > 0) {
                $dayDate = $dates[$di]['date'];
                if ($minDay === null || $dayDate < $minDay) $minDay = $dayDate;
                if ($maxDay === null || $dayDate > $maxDay) $maxDay = $dayDate;
            }
        }
        if ($minDay !== null && $maxDay !== null) {
            $allocatedRanges[] = [
                'homeworkId' => $hw['id'],
                'range' => "{$minDay} ~ {$maxDay}",
            ];
        }
    }

    // 计算实际最后有作业日期
    $lastActiveIdx = -1;
    foreach ($dailyAlloc as $di => $alloc) {
        foreach ($alloc as $hwIdx => $units) {
            if ($units > 0 && $di > $lastActiveIdx) $lastActiveIdx = $di;
        }
    }
    $actualEndDate = ($lastActiveIdx >= 0) ? $dates[$lastActiveIdx]['date'] : $dates[0]['date'];

    // 构建 daily 输出
    $daily = [];
    foreach ($dates as $di => $day) {
        $tasks = [];
        $totalMin = 0;
        foreach ($dailyAlloc[$di] ?? [] as $hwIdx => $units) {
            if ($units <= 0) continue;
            $hw = $processed[$hwIdx];
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

    return [
        'daily' => $daily,
        'allocatedRanges' => $allocatedRanges,
        'targetEndDate' => $actualEndDate,
        'warnings' => $warnings,
        'stats' => [
            'availableDays' => $N,
            'maxDailyMinutes' => $maxDailyMinutes,
            'rhythm' => $rhythm,
        ],
    ];
}
