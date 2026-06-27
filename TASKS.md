# TASKS.md — Coder 任务清单

> 创建：2026-06-27 · Designer → Coder · BitHabit 项目  
> 任务：TASK-011 — 分配策略 v3 升级

---

## TASK-011：分配策略 v3 升级

**概述**：将分配策略从 v2（两步）升级到 v3（三步），新增优先级排序、自动间隔推荐、预计完成日期调整、逐项调整滑块+间隔控件。

**涉及文件**：
- 数据库：`homework` 表新增 3 字段
- 后端：`preview.php`、`generate.php`、`homework-adjust.php`，**新增** `homework-priority.php`、`homework-ranges.php`
- 前端：`PlanAllocateView.vue` 重构为三步流程，**新增** `PlanAllocateStep1.vue`（或内联）
- API TypeScript 类型：`api/index.ts`

---

### Phase 1：数据库变更

```sql
ALTER TABLE homework ADD COLUMN priority INT DEFAULT 0 AFTER locked;
ALTER TABLE homework ADD COLUMN interval_days INT DEFAULT 0 AFTER priority;
ALTER TABLE homework ADD COLUMN allocated_range VARCHAR(50) DEFAULT NULL AFTER interval_days;
```

- `priority`：拖拽排序的优先级（0 最高，1 次之...）
- `interval_days`：间隔天数，0=每天分配，1=隔天，2=隔两天...
- `allocated_range`：上次生成时写入的预估日期范围（如 "2026-07-10 ~ 2026-07-20"），只读快照

---

### Phase 2：后端重写 `preview.php`

**文件**：`Web/api/plans/preview.php`

#### 改动点

**a) Request 新增字段**
```json
{
  "planId": 1,
  "rhythm": 0,
  "maxDailyMinutes": 300,
  "targetEndDate": null,      // 新增：null=不调整，传 "2026-08-20" = 压缩/拉伸
  "homeworkOverrides": [...]
}
```
`homeworkOverrides` 每项新增 `intervalDays`：
```json
{
  "homeworkId": 5,
  "windowStart": "2026-07-10",
  "windowEnd": "2026-08-15",
  "intervalDays": 1,          // 新增
  "locked": false
}
```

**b) 查询 homework 时新增字段**
```sql
SELECT id, subject, task_type, total_amount, unit, time_per_unit,
       window_start, window_end, locked, priority, interval_days
FROM homework WHERE plan_id = ? AND user_id = ?
ORDER BY priority ASC   -- 新增：按优先级排序
```

**c) 分配算法重写（v3 多阶段）**

伪代码：
```
阶段 0：预处理
  - 仅处理 locked=0 的作业
  - homework 列表已按 priority ASC 排序

阶段 1：生成可用日期列表 dates[]（同 v2）

阶段 2：自动间隔推荐
  for each hw in homework:
    - windowStart = hw.window_start ?? plan.start_date
    - windowEnd = hw.window_end ?? plan.end_date
    - M = count of dates[] in [windowStart, windowEnd]
    - 如果 hw.interval_days = 0（未手动设置）：
        autoInterval = max(0, floor(M / hw.total_amount) - 1)
        例：5篇/60天 → floor(60/5)-1 = 11 → 每 12 天一篇
    - 否则用 hw.interval_days（用户设定）
    - 过滤：从 windowStart 开始，每隔 interval_days 天取一天
      得到 hw.effective_days[]
    - 如果 effective_days[] 为空 → 退化为 window 内所有天

阶段 3：目标完成日期缩放（如传了 targetEndDate）
  - currentEndDate = dates[last]（可用日期的最后一天）
  - 如果 targetEndDate != null && targetEndDate != currentEndDate：
      scaleFactor = (targetEndDate天数 - 起始日天数) / (currentEndDate天数 - 起始日天数)
      对每个 hw：effective_days 按 scaleFactor 收缩/扩展
      收缩时可能减少有效天数 → 减少总分配量 → 每日量增加（压缩）
      扩展时可能增加有效天数 → 每日量减少（拉伸）

阶段 4：生成节奏权重 w[0..N-1]（同 v2，rhythm -2..2）

阶段 5：按 priority 顺序分配
  daily_load[0..N-1] = 0  // 每天已分配分钟数
  daily_alloc[0..N-1] = {}  // 每天分配的单位 map[hwIdx] = units

  for each hw (已按 priority ASC 排序):
    - totalUnits = (int)hw.total_amount
    - 取 hw.effective_days 对应的权重
    - totalWeight = sum(weights[i] for i in effective_days)
    - 按权重比例分配整数单位：
      for d in effective_days:
        frac = totalUnits * weights[d] / totalWeight
        intPart = floor(frac)
        raw[d] = {int: intPart, frac: frac - intPart}
      rawTotal = sum(intPart)
      remaining = totalUnits - rawTotal
      按 frac 降序分配余数
    - 检查容量约束：
      for d 按 raw 结果：
        所需分钟 = raw[d].int * hw.time_per_unit
        如果 daily_load[d] + 所需分钟 > maxDailyMinutes：
          减少此天的分配量，对减掉的单位找下一个容量够的 effective_day
          找不到 → 记录 warning
        否则：
          daily_alloc[d][hwIdx] = raw[d].int
          daily_load[d] += 所需分钟

阶段 6：溢出处理（同 v2，但低优先先挪）

阶段 7：计算 allocatedRanges
  for each hw:
    找出 daily_alloc 中该 hw 分配到的最早和最晚日期
    range = "最早日 ~ 最晚日"
```

**d) Response 新增字段**
```json
{
  "daily": [...],           // 同 v2
  "allocatedRanges": [      // 新增
    { "homeworkId": 1, "range": "2026-07-01 ~ 2026-07-25" },
    { "homeworkId": 5, "range": "2026-08-10 ~ 2026-08-25" }
  ],
  "targetEndDate": "2026-08-20",  // 新增：实际最后有作业的日期
  "warnings": [...]
}
```

---

### Phase 3：后端重写 `generate.php`

**文件**：`Web/api/plans/generate.php`

#### 改动点

- **复用 preview.php 的完整分配算法**（建议提取公共函数到 `scheduler.php` 或 require preview 逻辑）
- 生成后批量 UPDATE `homework.allocated_range`：
  ```sql
  UPDATE homework SET allocated_range = ? WHERE id = ? AND plan_id = ?
  ```
- Response 新增 `targetEndDate` 和 `allocatedRanges`（同 preview）

---

### Phase 4：新增 `homework-priority.php`

**文件**：`Web/api/plans/homework-priority.php`（新建）

```
PUT /api/plans/:planId/homework-priority.php
Authorization: Bearer <token>

Request:
{
  "order": [5, 3, 1, 4, 2]   // homeworkId 数组，按新顺序排列
}

Response (200):
{
  "updated": 5                // 更新数量
}
```

逻辑：
```php
foreach ($order as $index => $homeworkId) {
    UPDATE homework SET priority = $index WHERE id = $homeworkId AND plan_id = $planId AND user_id = $userId;
}
```

---

### Phase 5：新增 `homework-ranges.php`

**文件**：`Web/api/plans/homework-ranges.php`（新建）

```
POST /api/plans/:planId/homework-ranges.php
Authorization: Bearer <token>

Request:
{
  "rhythm": 0,
  "maxDailyMinutes": 300
  // 直接用 homework 表的当前 priority/interval/window 值，不需要 overrides
}

Response (200):
{
  "ranges": [
    { "homeworkId": 1, "subject": "物理", "taskType": "模拟卷", "range": "2026-07-01 ~ 2026-07-25" },
    { "homeworkId": 5, "subject": "语文", "taskType": "作文", "range": "2026-08-15 ~ 2026-08-25" }
  ]
}
```

- 轻量预览，仅返回每项的预估日期范围 + 自动推荐间隔
- 内部复用 preview 算法的阶段 0-4+7，但不返回每日明细

---

### Phase 6：扩展 `homework-adjust.php`

**文件**：`Web/api/plans/homework-adjust.php`

#### GET 改动

新增返回字段：`priority`、`intervalDays`、`allocatedRange`

```json
{
  "homework": [
    {
      "id": 5,
      "subject": "语文",
      "taskType": "作文",
      "totalAmount": 5,
      "unit": "篇",
      "timePerUnit": 60,
      "windowStart": null,
      "windowEnd": null,
      "locked": false,
      "priority": 3,              // 新增
      "intervalDays": 0,          // 新增
      "allocatedRange": null      // 新增（上次生成的快照）
    }
  ]
}
```

SQL 增加字段：
```sql
SELECT id, subject, task_type, total_amount, unit, time_per_unit,
       window_start, window_end, locked, priority, interval_days, allocated_range
FROM homework WHERE plan_id = ? AND user_id = ?
ORDER BY priority ASC
```

#### PUT 改动

`adjustments` 每项新增 `intervalDays`：
```json
{
  "adjustments": [
    {
      "homeworkId": 5,
      "windowStart": "2026-08-01",
      "windowEnd": null,
      "intervalDays": 2,     // 新增
      "locked": true
    }
  ]
}
```

SQL UPDATE 增加：
```sql
UPDATE homework SET window_start = ?, window_end = ?, interval_days = ?, locked = ?
WHERE id = ? AND plan_id = ? AND user_id = ?
```

---

### Phase 7：前端重构 PlanAllocateView（三步流程）

**文件**：`Web/src/views/PlanAllocateView.vue`（重构）

#### 7.1 三步流程骨架

```
<template>
  <!-- Step 1: 总览 & 优先级排序 -->
  <template v-if="step === 1">
    <header> ← 返回 | 分配策略 · 总览</header>
    
    <!-- 预计完成日期行 -->
    <section class="completion-card">
      预计 {{ formatDate(targetEndDate) }} 完成全部作业
      <input type="range" :min="dateIdx(planStart)" :max="dateIdx(planEnd)" 
             v-model.number="targetEndIdx" @input="onTargetEndChange" />
      <span>{{ formatDate(idxToDate(targetEndIdx)) }}</span>
    </section>

    <!-- 拖拽排序列表 -->
    <section class="sort-list">
      <div v-for="(hw, i) in sortedHomework" :key="hw.id"
           class="sort-item" draggable="true"
           @dragstart="onDragStart($event, i)"
           @dragover.prevent="onDragOver($event, i)"
           @dragend="onDragEnd"
           :class="{ dragging: dragIndex === i, over: dragOverIndex === i }">
        <span class="drag-handle">☰</span>
        <div class="sort-info">
          <span class="sort-name">{{ icon(hw.subject) }} {{ hw.subject }} · {{ hw.taskType }}</span>
          <span class="sort-amount">{{ fmt(hw.totalAmount) }}{{ hw.unit }} · {{ hw.totalAmount * (hw.timePerUnit || 60) }}分</span>
        </div>
        <div class="sort-meta" v-if="hw.allocatedRange">
          📅 {{ hw.allocatedRange }}
          <span v-if="hw.autoInterval > 0"> · 隔{{ hw.autoInterval }}天一篇</span>
        </div>
        <div class="sort-meta" v-else>
          ⏳ 拖拽排序后预览
        </div>
      </div>
    </section>

    <button @click="step = 2">下一步：节奏与约束 →</button>
  </template>

  <!-- Step 2: 节奏 & 约束（原 Step 1） -->
  <template v-if="step === 2">
    <!-- 折线图 + 预计完成线竖虚线 -->
    <section class="chart-card">
      <h3>📊 工作量预览</h3>
      <svg>
        <!-- 预计完成线竖虚线 -->
        <line :x1="targetEndLineX" :y1="0" :x2="targetEndLineX" :y2="chartH"
              stroke="#10b981" stroke-dasharray="4,3" stroke-width="2" />
        <text :x="targetEndLineX + 4" :y="12" font-size="10" fill="#10b981">
          预计 {{ formatDate(targetEndDate) }}
        </text>
        <!-- 柱状图（同 v2） -->
        ...
      </svg>
    </section>
    <!-- 节奏滑块、上限、预设（同 v2） -->
    ...
    <button @click="step = 1">← 返回</button>
    <button @click="step = 3">下一步：逐项调整 →</button>
  </template>

  <!-- Step 3: 逐项调整（v3 扩展） -->
  <template v-if="step === 3">
    <!-- 缩小版折线图（同 v2） -->
    
    <!-- 作业列表 -->
    <div class="adjust-card" v-for="hw in adjustHomework">
      <div class="adjust-header">{{ hw.subject }} · {{ hw.taskType }}</div>
      
      <!-- 起始日滑块 + 输入框 -->
      <div class="adjust-row">
        <label>起始日</label>
        <input type="range" :min="planStartTs" :max="hw.endDateTs - 86400"
               v-model.number="hw.startDateTs" step="86400"
               @input="syncDateInput(hw, 'start'); onAdjustChange()" />
        <input type="date" v-model="hw.editWinStart"
               @change="syncDateSlider(hw, 'start'); onAdjustChange()"
               class="date-input" />
      </div>

      <!-- 截止日滑块 + 输入框 -->
      <div class="adjust-row">
        <label>截止日</label>
        <input type="range" :min="hw.startDateTs + 86400" :max="planEndTs"
               v-model.number="hw.endDateTs" step="86400"
               @input="syncDateInput(hw, 'end'); onAdjustChange()" />
        <input type="date" v-model="hw.editWinEnd"
               @change="syncDateSlider(hw, 'end'); onAdjustChange()"
               class="date-input" />
      </div>

      <!-- 间隔天数滑块 -->
      <div class="adjust-row">
        <label>间隔</label>
        <input type="range" :min="0" :max="hw.maxInterval" v-model.number="hw.editInterval"
               @input="onAdjustChange()" />
        <span class="interval-label">每 {{ hw.editInterval + 1 }} 天一次</span>
      </div>

      <!-- 锁定 -->
      <div class="adjust-row">
        <label>锁定</label>
        <label class="toggle">...</label>
      </div>
    </div>

    <button @click="step = 2">← 返回策略</button>
    <button @click="handleConfirm">✅ 确认生成</button>
  </template>
</template>
```

#### 7.2 拖拽排序逻辑

```ts
const dragIndex = ref(-1)
const dragOverIndex = ref(-1)

function onDragStart(e: DragEvent, i: number) {
  dragIndex.value = i
  e.dataTransfer!.effectAllowed = 'move'
}
function onDragOver(e: DragEvent, i: number) {
  dragOverIndex.value = i
}
function onDragEnd() {
  if (dragIndex.value !== dragOverIndex.value && dragOverIndex.value >= 0) {
    const item = sortedHomework.value.splice(dragIndex.value, 1)[0]
    sortedHomework.value.splice(dragOverIndex.value, 0, item)
    // 更新 priority 到后端
    savePriority()
    // 1 秒防抖刷新 ranges
    refreshRangesDebounced()
  }
  dragIndex.value = -1
  dragOverIndex.value = -1
}

// 1 秒防抖
let rangesTimer = 0
function refreshRangesDebounced() {
  clearTimeout(rangesTimer)
  rangesTimer = window.setTimeout(refreshRanges, 1000)
}
```

#### 7.3 预计完成日期滑块

```ts
const targetEndIdx = ref(0)
const targetEndDate = computed(() => idxToDate(targetEndIdx.value))

function onTargetEndChange() {
  // 带防抖调用 preview，传 targetEndDate
  clearTimeout(targetTimer)
  targetTimer = window.setTimeout(async () => {
    const res = await planApi.preview({
      planId: planId.value,
      rhythm: rhythm.value,
      maxDailyMinutes: maxDailyMinutes.value,
      targetEndDate: idxToDate(targetEndIdx.value),
    })
    if (res.ok) {
      preview.value = res.data
      targetEndDate.value = res.data.targetEndDate  // 实际计算结果
      updateStagedBars()
    }
  }, 300)
}
```

#### 7.4 滑动手动输入双向绑定

```ts
// hw 对象扩展：
// hw.startDateTs: Unix timestamp（毫秒或秒），滑块用
// hw.endDateTs: Unix timestamp
// hw.editWinStart: "YYYY-MM-DD" 字符串，输入框用
// hw.editWinEnd: "YYYY-MM-DD" 字符串
// hw.editInterval: number，间隔天数
// hw.maxInterval: 计算出的最大间隔 = floor(窗口天数 / 总单位数)（至少为窗口天数/2）

function syncDateInput(hw, field) {
  if (field === 'start') hw.editWinStart = tsToDateStr(hw.startDateTs)
  else hw.editWinEnd = tsToDateStr(hw.endDateTs)
}
function syncDateSlider(hw, field) {
  if (field === 'start') hw.startDateTs = dateStrToTs(hw.editWinStart)
  else hw.endDateTs = dateStrToTs(hw.editWinEnd)
}
```

#### 7.5 TypeScript 类型更新

在 `api/index.ts` 中新增/修改：

```ts
// PreviewResult 新增
export interface PreviewResult {
  daily: PreviewDay[]
  allocatedRanges: { homeworkId: number; range: string }[]  // 新增
  targetEndDate?: string  // 新增
  warnings: string[]
  stats: ...
}

// preview() input 新增
preview(input: { 
  planId: number; rhythm: number; maxDailyMinutes: number; 
  targetEndDate?: string | null;  // 新增
  homeworkOverrides?: { homeworkId: number; windowStart?: string | null; windowEnd?: string | null; intervalDays?: number; locked?: boolean }[] 
})

// HomeworkAdjustItem 新增
export interface HomeworkAdjustItem {
  ...
  priority: number        // 新增
  intervalDays: number    // 新增
  allocatedRange: string | null  // 新增
}

// planApi 新增
planApi = {
  ...
  /** 保存优先级排序 */
  saveHomeworkPriority(planId: number, order: number[]) {
    return request<{ updated: number }>('PUT', `/plans/homework-priority.php?planId=${planId}`, { order })
  },
  /** 获取作业预估时间范围 */
  getHomeworkRanges(planId: number, rhythm: number, maxDailyMinutes: number) {
    return request<{ ranges: ...[] }>('POST', `/plans/homework-ranges.php?planId=${planId}`, { rhythm, maxDailyMinutes })
  },
}
```

---

### Phase 8：CSS 样式新增

在 `PlanAllocateView.vue` `<style scoped>` 新增：

```css
/* 预计完成日期行 */
.completion-card {
  background: var(--color-card);
  border-radius: var(--radius);
  padding: 14px 16px;
  margin-bottom: 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 0.875rem;
  color: var(--color-text);
}
.completion-card input[type="range"] {
  width: 100%;
  accent-color: var(--color-primary);
}

/* 拖拽排序 */
.sort-list { ... }
.sort-item {
  display: flex; flex-wrap: wrap; align-items: flex-start; gap: 8px;
  padding: 12px 14px;
  background: var(--color-card);
  border-radius: var(--radius-sm);
  margin-bottom: 6px;
  border: 1.5px solid var(--color-border);
  transition: transform 0.2s var(--ease-smooth), box-shadow 0.2s var(--ease-smooth);
  cursor: grab;
}
.sort-item.dragging { opacity: 0.4; }
.sort-item.over { border-color: var(--color-primary); box-shadow: 0 0 0 2px rgba(79,70,229,0.15); }
.drag-handle { font-size: 1.125rem; color: var(--color-text-placeholder); cursor: grab; }
.sort-info { flex: 1; min-width: 0; }
.sort-name { font-weight: 600; font-size: 0.875rem; color: var(--color-text); display: block; }
.sort-amount { font-size: 0.75rem; color: var(--color-text-placeholder); display: block; margin-top: 2px; }
.sort-meta { 
  width: 100%; font-size: 0.75rem; color: var(--color-text-placeholder); 
  padding-left: 28px; 
}

/* 滑块 + 输入框组合 */
.adjust-row input[type="range"] {
  flex: 1; accent-color: var(--color-primary); min-width: 0;
}
.interval-label { 
  font-size: 0.75rem; color: var(--color-text-secondary); white-space: nowrap; min-width: 80px; 
}
```

---

## 验收标准

- [ ] homework 表新增 priority/interval_days/allocated_range 三字段
- [ ] Step 1：显示作业拖拽排序列表，带 ☰ 手柄
- [ ] Step 1：拖拽后 1 秒防抖调用 API，刷新每行的预估日期范围和推荐间隔
- [ ] Step 1：预计完成日期行，滑块可调早/调晚
- [ ] Step 2：折线图新增「预计完成线」竖虚线（绿色虚线 + 日期标签）
- [ ] Step 2：节奏滑块、上限、预设均正常工作（保持 v2 功能）
- [ ] Step 3：每项作业的起始日和截止日使用滑块 + 手动输入框，双向绑定
- [ ] Step 3：每项作业的间隔天数滑块，自动推荐值，标签「每 N+1 天一次」
- [ ] Step 3：锁定开关正常
- [ ] 确认生成：生成后 allocated_range 写回 homework 表
- [ ] preview API：支持 targetEndDate，返回 allocatedRanges
- [ ] generate API：支持 targetEndDate，返回 allocatedRanges
- [ ] homework-priority.php：PUT 接收 order 数组，更新 priority
- [ ] homework-ranges.php：POST 返回轻量范围预览
- [ ] homework-adjust.php：GET 返回 priority/intervalDays/allocatedRange；PUT 支持 intervalDays
- [ ] 作文类小型任务自动按间隔分配（如 5 篇 / 60 天 → 每 12 天一篇）
- [ ] 高优先级作业先占满容量，低优先级被挤到后期
- [ ] 无 JS 报错、无布局跳动
- [ ] 折线图动画正常（stagger 入场、值过渡、超限脉冲）

---

## 备注

- 分配算法核心逻辑建议抽取公共函数避免 preview.php 和 generate.php 重复
- 如果涉及大量 PHP 代码复用，可在 `api/scheduler.php` 中放公共分配函数
- 三步流程 UI 可以在 PlanAllocateView.vue 内用 `v-if="step === N"` 实现（不拆文件）
- 拖拽排序使用原生 HTML5 Drag & Drop API（不需要额外库）
- `allocated_range` 格式：`"2026-07-10 ~ 2026-07-25"`，前后端一致

---

> 完成 TASK-011 后直接回复用户告知结果，无需经过 Designer 转发。
