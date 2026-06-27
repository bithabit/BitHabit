# TASKS.md — Coder 任务清单

> 创建：2026-06-27 · Designer → Coder · BitHabit 项目  
> 任务：TASK-013 — 堆叠柱状图 + 预览本地计算

---

## TASK-013：堆叠柱状图 + 预览本地计算

**概述**：两个改动——①折线图改为堆叠柱状图（不同科目不同颜色）；②把 preview 的计算从后端移到前端本地，仅在「确认生成」时调一次 generate.php。

**涉及文件**：
- 新建：`Web/src/scheduler.ts`（前端分配算法，约 200 行 TS）
- 修改：`Web/src/views/PlanAllocateView.vue`（图表渲染 + 调用逻辑）
- 修改：`Web/api/plans/preview.php`（可保持兼容，不再被前端调用）
- 修改：`Web/api/plans/homework-ranges.php`（可保持兼容，不再被前端调用）

---

### Phase 1：前端调度算法模块 `scheduler.ts`

**文件**：`Web/src/scheduler.ts`（新建）

将 `api/plans/scheduler.php` 中的 `allocatePlan()` 翻译为 TypeScript 版本。

**接口**：

```ts
export interface HomeworkInput {
  id: number
  subject: string
  task_type: string
  total_amount: number
  unit: string
  time_per_unit: number
  window_start: string | null
  window_end: string | null
  locked: number        // 0 or 1
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

export interface AllocateResult {
  daily: Array<{
    date: string
    totalMinutes: number
    overLimit: boolean
    tasks: Array<{
      homeworkId: number
      subject: string
      taskType: string
      amount: number
      unit: string
      estimatedMinutes: number
    }>
  }>
  allocatedRanges: Array<{ homeworkId: number; range: string }>
  targetEndDate: string
  warnings: string[]
  stats: { availableDays: number; maxDailyMinutes: number; rhythm: number }
}

export function allocatePlan(
  homework: HomeworkInput[],
  dates: DateItem[],
  rhythm: number,          // -2..2
  maxDailyMinutes: number,
  targetEndDate: string | null,
  overridesMap: Map<number, HomeworkOverride>
): AllocateResult
```

**核心算法**（从 PHP 翻译，保持逻辑一致）：
1. 过滤 locked 的作业、time_per_unit≤0 的作业
2. 按 priority ASC 排序
3. 生成节奏权重曲线（rhythm → w[i]）
4. 如果 targetEndDate 不为 null，截断日期范围
5. 对每项作业：确定窗口 → 自动间隔（`floor(M/totalAmount)-1`） → 间隔过滤有效天
6. 按优先级顺序：权重比例分配 → 容量约束 → 溢出
7. 最终溢出处理（低优先级优先被挤）
8. 计算 allocatedRanges + targetEndDate

**⚠️ 关键**：算法逻辑必须与 PHP scheduler.php 完全一致，否则预览和实际生成结果不匹配。

---

### Phase 2：PlanAllocateView.vue 改动

**文件**：`Web/src/views/PlanAllocateView.vue`

#### 2.1 导入本地调度器

```ts
import { allocatePlan, type HomeworkInput as SchedulerHomework, type DateItem, type HomeworkOverride, type AllocateResult } from '../scheduler'
```

#### 2.2 onMounted 一次性加载数据

现在需要一次性加载两样东西以支持本地计算：
1. 作业列表（含 priority/interval/windowStart/windowEnd）
2. 日程约束（每周固定 + 特殊日期）

```ts
onMounted(async () => {
  // 1. 获取计划详情
  const planRes = await planApi.detail(planId.value)
  if (planRes.ok) {
    planDetail.value = planRes.data
    const s = new Date(planRes.data.start_date)
    const e = new Date(planRes.data.end_date)
    planStartTs.value = s.getTime()
    planEndTs.value = e.getTime()
    targetEndIdx.value = planDates.value.length - 1
  }

  // 2. 获取作业列表（一次性）
  const [adjRes, schedRes] = await Promise.all([
    planApi.getHomeworkAdjust(planId.value),
    scheduleApi.list(),   // ← 需要引入 schedule store 或 API
  ])

  if (adjRes.ok) {
    fullHomeworkList.value = adjRes.data.homework  // 存储完整列表
    // ... 填充 sortedHomework 和 adjustHomework
  }

  if (schedRes.ok) {
    scheduleData.value = schedRes.data  // 存储日程数据
  }

  // 3. 首次本地计算
  localCompute()
})
```

#### 2.3 本地计算替代 refreshPreview

```ts
function localCompute(): AllocateResult | null {
  // 构建 SchedulerHomework[]
  const hwList: SchedulerHomework[] = adjustHomework.value.map(hw => ({
    id: hw.id,
    subject: hw.subject,
    task_type: hw.taskType,
    total_amount: hw.totalAmount,
    unit: hw.unit,
    time_per_unit: hw.timePerUnit || 60,
    window_start: hw.editWinStart,
    window_end: hw.editWinEnd,
    locked: hw.editLocked ? 1 : 0,
    priority: sortedHomework.value.findIndex(sh => sh.id === hw.id),
    interval_days: hw.editInterval,
  }))

  // 构建可用日期列表（排除日程约束后）
  const dates: DateItem[] = buildAvailableDates(
    planDates.value,       // 所有日期
    scheduleData.value,    // 日程约束
  )

  // 构建 overrides
  const overridesMap = new Map<number, HomeworkOverride>()
  for (const hw of adjustHomework.value) {
    overridesMap.set(hw.id, {
      homeworkId: hw.id,
      windowStart: hw.editWinStart || undefined,
      windowEnd: hw.editWinEnd || undefined,
      intervalDays: hw.editInterval,
      locked: hw.editLocked,
    })
  }

  const result = allocatePlan(
    hwList, dates,
    rhythm.value,
    maxDailyMinutes.value,
    targetEndDateActual.value,
    overridesMap,
  )

  // 更新 preview (用于折线图渲染)
  preview.value = result as any
  updateStagedBars()

  // 更新 ranges (用于排序列表)
  for (const hw of sortedHomework.value) {
    const match = result.allocatedRanges.find(r => r.homeworkId === hw.id)
    hw.rangeStr = match ? match.range : null
  }

  return result
}

// 日程过滤辅助
function buildAvailableDates(allDates: string[], schedule: ScheduleData | null): DateItem[] {
  // 与 PHP preview.php 的日程过滤逻辑一致
  // 过滤全天休息日
  // 返回 DateItem[]
}
```

#### 2.4 debouncedPreview 改为同步

```ts
// 旧：发 HTTP
async function refreshPreview() { ... }

// 新：本地计算
function debouncedPreview() {
  clearTimeout(previewTimer)
  previewTimer = window.setTimeout(localCompute, 200)  // 本地 200ms 防抖
}
```

#### 2.5 fetchRanges 不再需要远程调用

```ts
// 旧：调 homework-ranges.php
async function fetchRanges() { ... }

// 新：直接用 localCompute 结果
// 拖拽后 triggers：
async function onDragEnd() { ... localCompute() }
```

#### 2.6 onAdjustChange 不再保存远程后刷预览

```ts
// 旧：先 saveHomeworkAdjust 再 refreshPreview
async function onAdjustChange() {
  await planApi.saveHomeworkAdjust(...)
  debouncedPreview()
}

// 新：本地计算预览，仅保存时调用后端
function onAdjustChange() {
  debouncedPreview()  // 纯本地，瞬时响应
}

// 但需在离开 Step 1 → Step 2 时保存一次 adjust 到后端
// 或改为 handleConfirm 时才保存所有 adjust + generate
```

#### 2.7 handleConfirm 一次性保存 + 生成

```ts
async function handleConfirm() {
  confirming.value = true
  // 1. 保存优先级
  const order = sortedHomework.value.map(hw => hw.id)
  await planApi.saveHomeworkPriority(planId.value, order)

  // 2. 保存逐项调整
  const adjustments = adjustHomework.value.map(hw => ({
    homeworkId: hw.id,
    windowStart: hw.editWinStart || null,
    windowEnd: hw.editWinEnd || null,
    intervalDays: hw.editInterval,
    locked: hw.editLocked,
  }))
  await planApi.saveHomeworkAdjust(planId.value, adjustments as any)

  // 3. 调用 generate (仅此一次写入)
  const res = await planApi.generate({
    planId: planId.value,
    rhythm: rhythm.value,
    maxDailyMinutes: maxDailyMinutes.value,
    targetEndDate: targetEndDateActual.value,
  } as any)

  confirming.value = false
  if (res.ok) router.push('/plan/' + planId.value)
}
```

---

### Phase 3：堆叠柱状图渲染

#### 3.1 科目颜色映射

```ts
const subjectColors: Record<string, string> = {
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
const defaultSubjectColor = '#6B7280'  // gray
```

#### 3.2 每天柱子拆分为多段

当日 tasks 数组按科目分组，每个科目画一个 rect 段：

```ts
interface BarSegment {
  subject: string
  color: string
  y: number       // 该段的 top
  h: number       // 该段的高度
  amount: number
  label: string
}

// bars computed 扩展
const barSegments = computed(() => {
  if (!preview.value) return [] as Array<BarSegment[]>
  return preview.value.daily.map(d => {
    const segs: BarSegment[] = []
    let accumulatedY = chartH  // 从底部开始堆叠
    // 按科目排序以保持颜色一致性
    const grouped: Record<string, number> = {}
    for (const t of d.tasks) {
      grouped[t.subject] = (grouped[t.subject] || 0) + t.estimatedMinutes
    }
    const sorted = Object.entries(grouped).sort((a, b) => a[0].localeCompare(b[0]))
    for (const [subject, minutes] of sorted) {
      const h = (minutes / maxMin.value) * chartH
      segs.push({
        subject,
        color: subjectColors[subject] || defaultSubjectColor,
        y: accumulatedY - h,
        h,
        amount: minutes,
        label: `${subject}: ${minutes}min`,
      })
      accumulatedY -= h
    }
    return segs
  })
})
```

#### 3.3 SVG 模板

```html
<!-- 每天一个柱子组 -->
<g v-for="(segs, i) in barSegments" :key="i">
  <rect v-for="(seg, j) in segs" :key="j"
    :x="i * (barW + 1)" :y="seg.y"
    :width="barW" :height="seg.h"
    :fill="seg.color"
    rx="2" opacity="0.85" class="bar-rect">
    <title>{{ seg.label }}</title>
  </rect>
</g>
```

- 超限的柱子：整柱变红（保留 `overLimit` 逻辑），用 `#ef4444` 半透明覆盖层
- 如果每天超过 3 种科目，柱宽太窄时不显示堆叠，降级为单色柱（最小宽度判断）

#### 3.4 staggedBars 适配

入场 stagger 需要扩展为 segments 数组结构：

```ts
const stagedSegments = ref<Array<BarSegment[]>>([])
let firstLoad = true

function updateStagedSegments() {
  const target = barSegments.value
  if (firstLoad && target.length > 0) {
    // 初始：所有段高度为 0
    stagedSegments.value = target.map(daySegs =>
      daySegs.map(s => ({ ...s, y: chartH, h: 0 }))
    )
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        stagedSegments.value = target.map(d => [...d])
        firstLoad = false
      })
    })
  } else {
    stagedSegments.value = target.map(d => [...d])
  }
}
```

---

### Phase 4：PlanAllocateView.vue 其他必要改动

#### 4.1 引入 schedule store

```ts
import { scheduleApi, type ScheduleData } from '../api'
const scheduleData = ref<ScheduleData | null>(null)
```

#### 4.2 fullHomeworkList

```ts
const fullHomeworkList = ref<HomeworkAdjustItem[]>([])
```

#### 4.3 清理不再需要的 API 调用

- 删除 `refreshPreview()` 的 HTTP 调用（替换为 `localCompute()`）
- 删除 `fetchRanges()` 的 HTTP 调用（合并到 `localCompute()`）
- 删除 `onAdjustChange()` 中保存到后端的逻辑（延迟到 `handleConfirm`）
- `debouncedRefreshRanges()` → 改为 `debouncedPreview()` 统一触发

---

### 验收标准

**本地计算：**
- [ ] 页面加载时只调 3 次 API：plan detail + homework adjust + schedule list
- [ ] 拖滑块/改上限/改间隔 → 本地瞬时响应，无网络请求
- [ ] 拖拽排序 → 本地重新计算，1 秒防抖
- [ ] 点击「确认生成」→ 调 savePriority + saveAdjust + generate（3 次 HTTP）
- [ ] 本地计算结果与 PHP scheduler.php 结果一致（可用测试验证）

**堆叠柱状图：**
- [ ] 每天柱子由不同科目的色块堆叠而成
- [ ] 数学(indigo)、语文(red)、英语(emerald)、物理(amber)、化学(violet)…各有颜色
- [ ] 超限柱子半透明红色覆盖
- [ ] 入场 stagger 动画正常（段级弹出）
- [ ] 值过渡动画正常（段级平滑迁移）
- [ ] 柱宽 < 4px 时降级为单色柱（避免视觉噪声）
- [ ] Mini 折线图（Step 2）可以保持单色柱（简化，或也做堆叠）
- [ ] Subject 颜色映射覆盖所有预设科目，未匹配的用灰色

**功能不退化：**
- [ ] 预计完成日期滑块正常
- [ ] 节奏预设按钮正常
- [ ] 逐项调整（Step 2）所有字段正常
- [ ] 提交生成成功写入数据库
- [ ] allocated_range 正确显示

---

## 备注

- `scheduler.ts` 算法翻译必须**严格保持与 PHP scheduler.php 逻辑一致**
- 文件名 `scheduler.ts` 放在 `Web/src/` 下，与现有 `api/index.ts`、`stores/` 平级
- 堆叠降级判断：`barW < 4` 时不堆叠，直接单色柱
- 颜色映射用一个常量 `SUBJECT_COLORS` 放在 `scheduler.ts` 或组件内

---

> 完成 TASK-013 后直接回复用户告知结果，无需经过 Designer 转发。
