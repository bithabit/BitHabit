/**
 * BitHabit - 前端分配算法 (v3)
 *
 * PHP scheduler.php 的 TypeScript 翻译版。
 * 算法逻辑必须严格一致。
 */

// ─── Types ───

export interface HomeworkInput {
  id: number
  subject: string
  task_type: string
  total_amount: number
  unit: string
  time_per_unit: number
  window_start: string | null
  window_end: string | null
  locked: number
  priority: number
  interval_days: number
}

export interface DateItem {
  date: string
  idx: number
}

export interface HomeworkOverride {
  homeworkId: number
  windowStart?: string | null
  windowEnd?: string | null
  intervalDays?: number
  locked?: boolean | number
}

export interface AllocateTask {
  homeworkId: number
  subject: string
  taskType: string
  amount: number
  unit: string
  estimatedMinutes: number
}

export interface AllocateDay {
  date: string
  totalMinutes: number
  overLimit: boolean
  tasks: AllocateTask[]
}

export interface AllocateRange {
  homeworkId: number
  range: string
}

export interface AllocateResult {
  daily: AllocateDay[]
  allocatedRanges: AllocateRange[]
  targetEndDate: string
  warnings: string[]
  stats: {
    availableDays: number
    maxDailyMinutes: number
    rhythm: number
  }
}

// ─── Subject colors (reusable) ───

export const SUBJECT_COLORS: Record<string, string> = {
  '数学': '#4F46E5',   // indigo
  '语文': '#DC2626',   // red
  '英语': '#059669',   // emerald
  '物理': '#D97706',   // amber
  '化学': '#7C3AED',   // violet
  '生物': '#16A34A',   // green
  '历史': '#B45309',   // brown
  '地理': '#0891B2',   // cyan
  '政治': '#DB2777',   // pink
}
export const DEFAULT_SUBJECT_COLOR = '#6B7280' // gray

export function getSubjectColor(subject: string): string {
  return SUBJECT_COLORS[subject] || DEFAULT_SUBJECT_COLOR
}

export interface BarSegment {
  subject: string
  color: string
  y: number
  h: number
  x: number
  w: number
  amount: number
  label: string
}

// ─── Allocate Plan ───

export function allocatePlan(
  homework: HomeworkInput[],
  dates: DateItem[],
  rhythm: number,
  maxDailyMinutes: number,
  targetEndDate: string | null,
  overridesMap: Record<number, HomeworkOverride>,
): AllocateResult {
  const N = dates.length
  const warnings: string[] = []

  // === Phase 0: Preprocess ===
  const processed: Array<HomeworkInput & { processed?: boolean }> = []

  for (const hw of homework) {
    const hid = hw.id
    const ov = overridesMap[hid]
    let wStart = hw.window_start
    let wEnd = hw.window_end
    let interval = hw.interval_days
    let locked = hw.locked

    if (ov) {
      if (ov.windowStart !== undefined) wStart = ov.windowStart ?? null
      if (ov.windowEnd !== undefined) wEnd = ov.windowEnd ?? null
      if (ov.intervalDays !== undefined) interval = ov.intervalDays
      if (ov.locked !== undefined) locked = Number(ov.locked)
    }

    const amount = hw.total_amount
    const tpu = hw.time_per_unit

    if (tpu <= 0 || amount <= 0) continue
    if (locked === 1) continue

    processed.push({
      ...hw,
      window_start: wStart,
      window_end: wEnd,
      interval_days: interval,
      locked,
      total_amount: amount,
      time_per_unit: tpu,
    })
  }

  if (processed.length === 0) {
    return {
      daily: dates.map(d => ({ date: d.date, totalMinutes: 0, overLimit: false, tasks: [] })),
      allocatedRanges: [],
      targetEndDate: dates[0]?.date ?? '',
      warnings: ['没有可分配的作业'],
      stats: { availableDays: N, maxDailyMinutes, rhythm },
    }
  }

  // Sort by priority ASC
  processed.sort((a, b) => a.priority - b.priority)

  // === Generate weight curve ===
  const weights: number[] = []
  for (let i = 0; i < N; i++) {
    const t = N > 1 ? i / (N - 1) : 0.5
    let w: number
    switch (rhythm) {
      case -2: w = 1 - 0.7 * t; break
      case -1: w = 1 - 0.35 * t; break
      case 1:  w = 0.65 + 0.35 * t; break
      case 2:  w = 0.3 + 0.7 * t; break
      default: w = 1.0
    }
    weights.push(Math.max(0.01, w))
  }

  // === Phase 1-3: Effective days per homework ===
  const effectiveDaysPerHw: number[][] = []
  const planStartDate = dates[0]?.date ?? ''
  const planEndDate = dates[dates.length - 1]?.date ?? ''

  // Filter dates by targetEndDate
  let filteredDates = dates
  if (targetEndDate) {
    const targetTs = new Date(targetEndDate).getTime()
    filteredDates = dates.filter(d => new Date(d.date).getTime() <= targetTs)
    if (filteredDates.length === 0) filteredDates = [dates[0]]
  }

  for (let hwIdx = 0; hwIdx < processed.length; hwIdx++) {
    const hw = processed[hwIdx]
    const winStart = hw.window_start ?? planStartDate
    const winEnd = hw.window_end ?? planEndDate
    const totalUnits = Math.floor(hw.total_amount)

    const windowDays: number[] = []
    for (const d of filteredDates) {
      if (d.date >= winStart && d.date <= winEnd) {
        windowDays.push(d.idx)
      }
    }
    if (windowDays.length === 0) {
      effectiveDaysPerHw[hwIdx] = []
      continue
    }

    const M = windowDays.length

    // Phase 2: Auto interval recommendation
    let intervalDays = hw.interval_days
    if (intervalDays === 0 && totalUnits > 0) {
      const autoInterval = Math.max(0, Math.floor(M / totalUnits) - 1)
      intervalDays = autoInterval
    }

    // Phase 1: Interval filtering
    if (intervalDays > 0) {
      const effective: number[] = []
      const step = intervalDays + 1
      for (let i = 0; i < windowDays.length; i += step) {
        effective.push(windowDays[i])
      }
      effectiveDaysPerHw[hwIdx] = effective.length > 0 ? effective : windowDays
    } else {
      effectiveDaysPerHw[hwIdx] = windowDays
    }
  }

  // === Phase 5-6: Priority order allocation ===
  const dailyLoad: number[] = new Array(N).fill(0)
  const dailyAlloc: Record<number, number>[] = []
  for (let i = 0; i < N; i++) dailyAlloc[i] = {}

  for (let hwIdx = 0; hwIdx < processed.length; hwIdx++) {
    const hw = processed[hwIdx]
    const totalUnits = Math.floor(hw.total_amount)
    if (totalUnits <= 0) continue

    const effDays = effectiveDaysPerHw[hwIdx] ?? []
    if (effDays.length === 0) continue

    const unitMin = hw.time_per_unit

    // Candidate days with remaining capacity
    let candidateDays = effDays.filter(di => {
      return maxDailyMinutes - dailyLoad[di] >= unitMin
    })

    if (candidateDays.length === 0) {
      // Fallback: days with any remaining capacity
      candidateDays = effDays.filter(di => maxDailyMinutes - dailyLoad[di] > 0)
    }

    if (candidateDays.length === 0) {
      warnings.push(`${hw.subject}·${hw.task_type} 无可用容量，跳过`)
      continue
    }

    // Calculate weights for candidate days
    let totalWeight = 0
    const dayWeights: Record<number, number> = {}
    for (const di of candidateDays) {
      const w = weights[di]
      dayWeights[di] = w
      totalWeight += w
    }
    if (totalWeight <= 0) continue

    // Proportional allocation
    const raw: Record<number, { int: number; frac: number }> = {}
    let rawTotal = 0
    for (const di of candidateDays) {
      const frac = totalUnits * dayWeights[di] / totalWeight
      const intPart = Math.floor(frac)
      raw[di] = { int: intPart, frac: frac - intPart }
      rawTotal += intPart
    }

    // Remainder distribution
    let remainingUnits = totalUnits - rawTotal
    if (remainingUnits > 0) {
      const sorted = Object.entries(raw).sort((a, b) => b[1].frac - a[1].frac)
      for (const [, v] of sorted) {
        if (remainingUnits <= 0) break
        v.int++
        remainingUnits--
      }
    }

    // Capacity constraint
    let overflowUnits = 0
    for (const [diStr, v] of Object.entries(raw)) {
      const di = Number(diStr)
      if (v.int <= 0) continue
      const capacityRemaining = maxDailyMinutes - dailyLoad[di]
      const maxFit = Math.max(0, Math.floor(capacityRemaining / unitMin))
      if (v.int > maxFit) {
        overflowUnits += v.int - maxFit
        v.int = maxFit
      }
    }

    // Overflow redistribution
    if (overflowUnits > 0) {
      const spareDays: Array<{ di: number; w: number; maxFit: number }> = []
      for (const di of effDays) {
        const existing = raw[di]?.int ?? 0
        const usedMin = existing * unitMin
        const remaining = maxDailyMinutes - dailyLoad[di] - usedMin
        const maxFit = Math.max(0, Math.floor(remaining / unitMin))
        if (maxFit > 0) {
          spareDays.push({ di, w: weights[di], maxFit })
        }
      }

      spareDays.sort((a, b) => b.w - a.w) // higher weight first
      for (const sd of spareDays) {
        if (overflowUnits <= 0) break
        const assign = Math.min(overflowUnits, sd.maxFit)
        if (assign > 0) {
          if (!raw[sd.di]) raw[sd.di] = { int: 0, frac: 0 }
          raw[sd.di].int += assign
          overflowUnits -= assign
        }
      }
    }

    if (overflowUnits > 0) {
      warnings.push(`${hw.subject}·${hw.task_type} 有 ${overflowUnits}${hw.unit} 无法安排（容量不足）`)
    }

    // Write to dailyAlloc and dailyLoad
    for (const [diStr, v] of Object.entries(raw)) {
      const di = Number(diStr)
      if (v.int > 0) {
        dailyAlloc[di][hwIdx] = (dailyAlloc[di][hwIdx] ?? 0) + v.int
        dailyLoad[di] += v.int * unitMin
      }
    }
  }

  // === Phase 6: Overflow handling (lower priority first) ===
  for (let di = 0; di < N; di++) {
    let dayMinutes = dailyLoad[di]
    if (dayMinutes <= maxDailyMinutes) continue

    const hwIndices = Object.keys(dailyAlloc[di]).map(Number)
    if (hwIndices.length === 0) continue

    // Sort by priority descending (lower priority first to be moved)
    hwIndices.sort((a, b) => (processed[b]?.priority ?? 999) - (processed[a]?.priority ?? 999))

    for (const hwIdx of hwIndices) {
      if (dayMinutes <= maxDailyMinutes) break
      const uMin = processed[hwIdx]?.time_per_unit ?? 0

      while ((dailyAlloc[di][hwIdx] ?? 0) > 0 && dayMinutes > maxDailyMinutes) {
        let moved = false

        for (const td of [di - 1, di + 1]) {
          if (td < 0 || td >= N) continue
          const tMin = dailyLoad[td]
          if (tMin + uMin <= maxDailyMinutes) {
            dailyAlloc[di][hwIdx]--
            dailyAlloc[td][hwIdx] = (dailyAlloc[td][hwIdx] ?? 0) + 1
            dayMinutes -= uMin
            dailyLoad[di] -= uMin
            dailyLoad[td] += uMin
            moved = true
            break
          }
        }
        if (!moved) break
      }
    }

    if (dayMinutes > maxDailyMinutes) {
      warnings.push(`${dates[di].date} 超出上限 ${maxDailyMinutes} 分钟（实际 ${dayMinutes} 分钟）`)
    }
  }

  // === Phase 7: Calculate allocatedRanges ===
  const allocatedRanges: AllocateRange[] = []
  for (let hwIdx = 0; hwIdx < processed.length; hwIdx++) {
    const hw = processed[hwIdx]
    let minDay: string | null = null
    let maxDay: string | null = null

    for (let di = 0; di < N; di++) {
      if (dailyAlloc[di][hwIdx] && dailyAlloc[di][hwIdx] > 0) {
        const dayDate = dates[di].date
        if (minDay === null || dayDate < minDay) minDay = dayDate
        if (maxDay === null || dayDate > maxDay) maxDay = dayDate
      }
    }

    if (minDay !== null && maxDay !== null) {
      allocatedRanges.push({
        homeworkId: hw.id,
        range: `${minDay} ~ ${maxDay}`,
      })
    }
  }

  // Calculate actual last active day
  let lastActiveIdx = -1
  for (let di = 0; di < N; di++) {
    for (const hwIdxStr of Object.keys(dailyAlloc[di])) {
      const hwIdx = Number(hwIdxStr)
      if ((dailyAlloc[di][hwIdx] ?? 0) > 0 && di > lastActiveIdx) {
        lastActiveIdx = di
      }
    }
  }
  const actualEndDate = lastActiveIdx >= 0 ? dates[lastActiveIdx].date : (dates[0]?.date ?? '')

  // Build daily output
  const daily: AllocateDay[] = dates.map((day, di) => {
    const tasks: AllocateTask[] = []
    let totalMin = 0

    for (const [hwIdxStr, units] of Object.entries(dailyAlloc[di] ?? {})) {
      const hwIdx = Number(hwIdxStr)
      if (units <= 0) continue
      const hw = processed[hwIdx]
      const estMin = units * hw.time_per_unit
      tasks.push({
        homeworkId: hw.id,
        subject: hw.subject,
        taskType: hw.task_type,
        amount: units,
        unit: hw.unit,
        estimatedMinutes: estMin,
      })
      totalMin += estMin
    }

    tasks.sort((a, b) => a.subject.localeCompare(b.subject))

    return {
      date: day.date,
      totalMinutes: totalMin,
      overLimit: totalMin > maxDailyMinutes,
      tasks,
    }
  })

  return {
    daily,
    allocatedRanges,
    targetEndDate: actualEndDate,
    warnings,
    stats: {
      availableDays: N,
      maxDailyMinutes,
      rhythm,
    },
  }
}

// ─── Build available dates (schedule filtering) ───

export interface ScheduleWeekly {
  day_of_week: number
  start_time: string | null
  end_time: string | null
}

export interface ScheduleSpecial {
  date_from: string
  date_to: string | null
  start_time: string | null
  end_time: string | null
}

export interface ScheduleData {
  weekly: ScheduleWeekly[]
  special: ScheduleSpecial[]
}

export function buildAvailableDates(
  planStartDate: string,
  planEndDate: string,
  schedule: ScheduleData | null,
): DateItem[] {
  const result: DateItem[] = []
  const start = new Date(planStartDate)
  const end = new Date(planEndDate)

  for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
    const dateStr = d.toISOString().slice(0, 10)
    const dow = d.getDay()
    let blocked = false

    // Check weekly schedule
    if (schedule?.weekly) {
      for (const ws of schedule.weekly) {
        if (ws.day_of_week === dow && ws.start_time === null && ws.end_time === null) {
          blocked = true
          break
        }
      }
    }
    if (blocked) continue

    // Check special schedule
    if (schedule?.special) {
      for (const ss of schedule.special) {
        if (dateStr >= ss.date_from && dateStr <= (ss.date_to ?? ss.date_from)) {
          if (ss.start_time === null && ss.end_time === null) {
            blocked = true
            break
          }
        }
      }
    }
    if (blocked) continue

    result.push({ date: dateStr, idx: result.length })
  }

  return result
}
