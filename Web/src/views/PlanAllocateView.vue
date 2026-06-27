<template>
  <div class="page">
    <header class="page-header">
      <button class="btn-back" @click="handleBack">← 返回</button>
      <h2>{{ stepTitle }}</h2>
    </header>

    <!-- ========== Step 1: 分配策略 (合并折线图+节奏+排序) ========== -->
    <template v-if="step === 1">
      <!-- 折线图 -->
      <section class="chart-card">
        <h3>📊 工作量预览</h3>
        <div class="chart-wrap">
          <svg :width="chartW" :height="chartH" class="chart-svg">
            <!-- 预计完成线竖虚线 -->
            <line v-if="targetEndLineX >= 0"
              :x1="targetEndLineX" :y1="0" :x2="targetEndLineX" :y2="chartH"
              stroke="#10b981" stroke-dasharray="4,3" stroke-width="2" />
            <text v-if="targetEndLineX >= 0" :x="targetEndLineX + 4" :y="12" font-size="10" fill="#10b981">
              预计 {{ formatDate(targetEndDateActual) }}
            </text>
            <!-- 上限虚线 -->
            <line v-for="y in capLines" :key="y"
              :x1="0" :y1="y" :x2="chartW" :y2="y"
              stroke="#ef4444" stroke-dasharray="4,3" stroke-width="1" opacity="0.5"/>
            <!-- 柱子 -->
            <rect v-for="(bar, i) in stagedBars" :key="i"
              :x="bar.x" :y="bar.y" :width="bar.w" :height="bar.h"
              :fill="bar.over ? '#ef4444' : '#4F46E5'" rx="2"
              opacity="0.8" class="bar-rect">
              <title>{{ bar.label }}</title>
            </rect>
          </svg>
        </div>
        <div class="chart-labels">
          <span>{{ dates[0] || '' }}</span>
          <span>{{ dates[Math.floor(dates.length / 2)] || '' }}</span>
          <span>{{ dates[dates.length - 1] || '' }}</span>
        </div>
      </section>

      <!-- 预计完成日期 + 每日上限 同栏 -->
      <section class="combined-card" v-if="planDates.length > 0">
        <div class="combined-row">
          <div class="combined-field">
            <span class="combined-label">📅 预计完成</span>
            <div class="combined-slider-wrap">
              <input type="range" :min="0" :max="planDates.length - 1" v-model.number="targetEndIdx"
                     @input="debouncedPreview" class="completion-range-input" />
              <span class="combined-val">{{ formatDate(targetEndDateActual) }}</span>
            </div>
          </div>
          <div class="combined-divider"></div>
          <div class="combined-field combined-cap">
            <span class="combined-label">⏱ 每日上限</span>
            <div class="cap-inline-wrap">
              <input type="number" v-model.number="maxDailyMinutes" min="30" max="720" class="cap-input-sm" @input="debouncedPreview" />
              <span class="combined-val-small">分钟</span>
            </div>
          </div>
        </div>
      </section>

      <!-- 节奏 + 预设 -->
      <section class="slider-card">
        <h3>🎚 节奏偏好</h3>
        <div class="slider-row">
          <span class="slider-label">前紧后松</span>
          <input type="range" min="-2" max="2" step="1" v-model.number="rhythm" class="rhythm-slider" @input="debouncedPreview" />
          <span class="slider-label">前松后紧</span>
        </div>
        <div class="preset-row">
          <button v-for="p in presets" :key="p.val" class="preset-btn" :class="{ active: rhythm === p.val }" @click="rhythm = p.val; debouncedPreview()">{{ p.label }}</button>
        </div>
      </section>

      <!-- 预览统计 -->
      <section class="stats-card" v-if="preview">
        <div class="stat-row">
          <span>可用 {{ preview.stats.availableDays }} 天</span>
          <span>·</span>
          <span v-if="preview.warnings.length" class="warn">⚠️ {{ preview.warnings.length }} 个警告</span>
          <span v-else>✅ 无超限</span>
        </div>
      </section>

      <!-- 拖拽排序列表 -->
      <section class="sort-section">
        <h3>📋 作业优先级 <span class="hint">（排上方先安排）</span></h3>
        <div class="sort-list">
          <div v-for="(hw, i) in sortedHomework" :key="hw.id"
               class="sort-item sort-item-compact"
               :class="{ dragging: dragIndex === i, over: dragOverIndex === i }"
               draggable="true"
               @dragstart="onDragStart(i, $event)"
               @dragover.prevent="onDragOver(i)"
               @dragend="onDragEnd">
            <span class="drag-handle">☰</span>
            <div class="sort-info">
              <span class="sort-name">{{ subjectIcon(hw.subject) }} {{ hw.subject }} · {{ hw.taskType }}</span>
              <span class="sort-amount">{{ fmt(hw.totalAmount) }}{{ hw.unit }} · {{ fmtHours((hw.totalAmount) * (hw.timePerUnit || 60)) }}</span>
            </div>
            <div class="sort-meta">
              {{ hw.rangeStr ? '📅 ' + dateRangeShort(hw.rangeStr) : '⏳ 拖拽后预览…' }}
              <span v-if="hw.autoInterval > 0"> · 隔{{ hw.autoInterval + 1 }}天一次</span>
            </div>
          </div>
          <div v-if="sortedHomework.length === 0" class="sort-empty">暂无作业数据</div>
        </div>
      </section>

      <button class="btn-primary" :disabled="sortedHomework.length === 0" @click="step = 2">
        下一步：逐项调整 →
      </button>
    </template>

    <!-- ========== Step 2: 逐项调整 ========== -->
    <template v-if="step === 2">
      <!-- 缩小版折线图 -->
      <section class="chart-card chart-mini" @click="step = 1" style="cursor:pointer">
        <h3>📊 预览 <span class="hint">（点击返回调整节奏）</span></h3>
        <svg :width="200" :height="60" class="chart-svg">
          <rect v-for="(bar, i) in miniBars" :key="i"
            :x="bar.x" :y="bar.y" :width="bar.w" :height="bar.h"
            :fill="bar.over ? '#ef4444' : '#4F46E5'" rx="1" opacity="0.7" class="bar-rect"/>
        </svg>
      </section>

      <!-- 作业列表 -->
      <section class="adjust-list">
        <div class="adjust-card" v-for="hw in adjustHomework" :key="hw.id">
          <div class="adjust-header">
            <span class="adjust-subject">{{ subjectIcon(hw.subject) }} {{ hw.subject }} · {{ hw.taskType }}</span>
            <span class="adjust-amount">{{ fmt(hw.totalAmount) }}{{ hw.unit }} · {{ hw.totalAmount * (hw.timePerUnit || 60) }}分</span>
          </div>
          <div class="adjust-rows">
            <!-- 起始日滑块 + 输入框 -->
            <div class="adjust-row">
              <label>起始</label>
              <input type="range" :min="planStartTs" :max="tsMaxStart" :step="86400"
                     v-model.number="hw.editStartTs" @input="syncDateInput(hw, 'start'); onAdjustChange()" />
              <input type="date" v-model="hw.editWinStart"
                     @change="syncDateSlider(hw, 'start'); onAdjustChange()"
                     class="date-input" />
            </div>
            <!-- 截止日滑块 + 输入框 -->
            <div class="adjust-row">
              <label>截止</label>
              <input type="range" :min="tsMinEnd" :max="planEndTs" :step="86400"
                     v-model.number="hw.editEndTs" @input="syncDateInput(hw, 'end'); onAdjustChange()" />
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
              <label class="toggle">
                <input type="checkbox" v-model="hw.editLocked" @change="onAdjustChange()" />
                <span class="toggle-slider"></span>
              </label>
            </div>
          </div>
        </div>
      </section>

      <div class="action-row">
        <button class="btn-outline" @click="step = 1">← 返回策略</button>
        <button class="btn-primary" @click="handleConfirm" :disabled="confirming">
          {{ confirming ? '生成中...' : '✅ 确认生成' }}
        </button>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { planApi, type PreviewResult, type HomeworkAdjustItem, type PlanDetail } from '../api'

const route = useRoute()
const router = useRouter()
const planId = computed(() => Number(route.params.id))

const step = ref(1)
const stepTitle = computed(() =>
  step.value === 1 ? '分配策略' :
  '分配策略 · 逐项调整'
)

// ======== Plan info ========
const planDetail = ref<PlanDetail | null>(null)
const planStartTs = ref(0)
const planEndTs = ref(0)
const planDates = computed(() => {
  if (!planDetail.value) return []
  const dates: string[] = []
  const s = new Date(planDetail.value.start_date)
  const e = new Date(planDetail.value.end_date)
  for (let d = new Date(s); d <= e; d.setDate(d.getDate() + 1)) {
    dates.push(d.toISOString().slice(0, 10))
  }
  return dates
})
const planMinDate = computed(() => planDates.value[0] || '')
const planMaxDate = computed(() => planDates.value[planDates.value.length - 1] || '')

// ======== Step 1: 拖拽排序 ========
interface SortHomeworkItem {
  id: number
  subject: string
  taskType: string
  totalAmount: number
  unit: string
  timePerUnit: number | null
  rangeStr: string | null  // allocatedRange display
  autoInterval: number      // recommended interval
}

const sortedHomework = ref<SortHomeworkItem[]>([])
const dragIndex = ref(-1)
const dragOverIndex = ref(-1)

function onDragStart(i: number, e: DragEvent) {
  dragIndex.value = i
  e.dataTransfer!.effectAllowed = 'move'
}

function onDragOver(i: number) {
  dragOverIndex.value = i
}

async function onDragEnd() {
  if (dragIndex.value !== dragOverIndex.value && dragOverIndex.value >= 0) {
    const item = sortedHomework.value.splice(dragIndex.value, 1)[0]
    sortedHomework.value.splice(dragOverIndex.value, 0, item)
    // Save priority to backend
    await savePriority()
    // Debounced refresh ranges
    debouncedRefreshRanges()
  }
  dragIndex.value = -1
  dragOverIndex.value = -1
}

async function savePriority() {
  const order = sortedHomework.value.map(hw => hw.id)
  await planApi.saveHomeworkPriority(planId.value, order)
}

// ======== Step 1: target end date ========
const targetEndIdx = ref(0)
const targetEndDateActual = computed(() => {
  const idx = targetEndIdx.value
  return idx >= 0 && idx < planDates.value.length ? planDates.value[idx] : planMaxDate.value
})

// ======== Step 1: ranges refresh ========
let rangesTimer = 0
function debouncedRefreshRanges() {
  clearTimeout(rangesTimer)
  rangesTimer = window.setTimeout(fetchRanges, 1000)
}

async function fetchRanges() {
  const res = await planApi.getHomeworkRanges(planId.value, rhythm.value, maxDailyMinutes.value)
  if (!res.ok) return
  const rangeMap: Record<number, { range: string; subject: string; taskType: string }> = {}
  for (const r of res.data.ranges) {
    rangeMap[r.homeworkId] = r
  }
  // Also calculate auto-interval for each homework
  for (const hw of sortedHomework.value) {
    const match = rangeMap[hw.id]
    hw.rangeStr = match ? match.range : null
  }
}

// ======== Step 2: Chart (same as v2 with targetEndLine) ========
const rhythm = ref(0)
const maxDailyMinutes = ref(300)
const preview = ref<PreviewResult | null>(null)
const chartW = 280
const chartH = 140

const presets = [
  { val: -2, label: '前紧后松' },
  { val: -1, label: '偏紧' },
  { val: 0, label: '均匀' },
  { val: 1, label: '偏松' },
  { val: 2, label: '前松后紧' },
]

const dates = computed(() => preview.value?.daily.map(d => d.date.slice(5)) ?? [])
const maxMin = computed(() => Math.max(...preview.value?.daily.map(d => d.totalMinutes) ?? [1], maxDailyMinutes.value))

const capLines = computed(() => {
  if (maxMin.value <= 0) return []
  const capY = chartH - (maxDailyMinutes.value / maxMin.value) * chartH
  return [Math.max(0, Math.min(chartH, capY))]
})

const targetEndLineX = computed(() => {
  const ds = dates.value
  if (!preview.value?.targetEndDate || ds.length === 0) return -1
  // Find the chart x position for targetEndDate (using bar positions)
  const targetDateShort = preview.value.targetEndDate.slice(5)
  const idx = ds.indexOf(targetDateShort)
  if (idx < 0) return -1
  const barW = Math.max(2, chartW / ds.length - 1)
  return idx * (barW + 1) + barW / 2
})

const stagedBars = ref<Array<{x:number;y:number;w:number;h:number;over:boolean;label:string}>>([])
let firstLoad = true
function updateStagedBars() {
  const target = bars.value
  if (firstLoad && target.length > 0) {
    stagedBars.value = target.map(b => ({ ...b, y: chartH, h: 0 }))
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        stagedBars.value = [...target]
        firstLoad = false
      })
    })
  } else {
    stagedBars.value = [...target]
  }
}

const bars = computed(() => {
  if (!preview.value) return []
  return preview.value.daily.map((d, i) => {
    const barW = Math.max(2, chartW / preview.value!.daily.length - 1)
    const h = (d.totalMinutes / maxMin.value) * chartH
    return {
      x: i * (barW + 1),
      y: chartH - h,
      w: barW,
      h,
      over: d.overLimit,
      label: `${d.date}: ${d.totalMinutes}min ${d.tasks.map(t => `${t.subject} ${t.amount}${t.unit}`).join(', ')}`,
    }
  })
})

const miniBars = computed(() => {
  if (!preview.value) return []
  const mw = 200, mh = 60
  const maxM = Math.max(...preview.value.daily.map(d => d.totalMinutes), 1)
  return preview.value.daily.map((d, i) => {
    const bw = Math.max(1.5, mw / preview.value!.daily.length - 1)
    const h = (d.totalMinutes / maxM) * mh
    return { x: i * (bw + 1), y: mh - h, w: bw, h, over: d.overLimit }
  })
})

// ======== Step 3: Adjust ========
interface AdjustItem extends HomeworkAdjustItem {
  editWinStart: string | null
  editWinEnd: string | null
  editLocked: boolean
  editInterval: number
  editStartTs: number  // for slider
  editEndTs: number    // for slider
  maxInterval: number
}

const adjustHomework = ref<AdjustItem[]>([])
const confirming = ref(false)

// Computed sliders constraints
const tsMaxStart = computed(() => {
  // max start ts = plan end ts - 86400 (at least 1 day before end)
  return planEndTs.value - 86400
})
const tsMinEnd = computed(() => {
  return planStartTs.value + 86400
})

// ======== Helpers ========
function fmtHours(minutes: number): string {
  const h = minutes / 60
  return Number.isInteger(h) ? `${h}h` : `${h.toFixed(1)}h`
}
function dateRangeShort(range: string): string {
  if (!range) return ''
  return range.split(' ~ ').map(d => {
    const m = d.split('-')
    return `${parseInt(m[1])}/${parseInt(m[2])}`
  }).join(' - ')
}
function subjectIcon(s: string): string {
  const m: Record<string,string> = {'数学':'📐','英语':'🇬🇧','语文':'📖','物理':'⚛️','化学':'🧪','生物':'🧬','历史':'📜','地理':'🌍','政治':'⚖️'}
  return m[s] || '📝'
}
function fmt(n: number): string { return Number.isInteger(n) ? n.toString() : n.toFixed(2).replace(/\.?0+$/, '') }
function formatDate(iso: string): string {
  if (!iso) return ''
  const parts = iso.split('-')
  return `${parseInt(parts[1])}/${parseInt(parts[2])}`
}
function dateStrToTs(iso: string): number {
  return new Date(iso + 'T00:00:00').getTime()
}
function tsToDateStr(ts: number): string {
  return new Date(ts).toISOString().slice(0, 10)
}

function syncDateInput(hw: AdjustItem, field: 'start' | 'end') {
  if (field === 'start') hw.editWinStart = tsToDateStr(hw.editStartTs)
  else hw.editWinEnd = tsToDateStr(hw.editEndTs)
}
function syncDateSlider(hw: AdjustItem, field: 'start' | 'end') {
  if (field === 'start') {
    hw.editStartTs = dateStrToTs(hw.editWinStart || planMinDate.value)
  } else {
    hw.editEndTs = dateStrToTs(hw.editWinEnd || planMaxDate.value)
  }
}

// ======== Preview ========
let previewTimer = 0
function debouncedPreview() {
  clearTimeout(previewTimer)
  previewTimer = window.setTimeout(async () => {
    await refreshPreview()
  }, 200)
}

async function refreshPreview() {
  const overrides = buildOverrides()
  const res = await planApi.preview({
    planId: planId.value,
    rhythm: rhythm.value,
    maxDailyMinutes: maxDailyMinutes.value,
    targetEndDate: targetEndDateActual.value,
    homeworkOverrides: overrides.length > 0 ? overrides : undefined,
  })
  if (res.ok) {
    preview.value = res.data
    updateStagedBars()
  }
}

function buildOverrides(): Record<string, unknown>[] {
  if (adjustHomework.value.length === 0) return []
  return adjustHomework.value.map(hw => ({
    homeworkId: hw.id,
    windowStart: hw.editWinStart || null,
    windowEnd: hw.editWinEnd || null,
    intervalDays: hw.editInterval,
    locked: hw.editLocked,
  }))
}

// ======== Adjust change ========
async function onAdjustChange() {
  // Save to backend
  const adjustments = adjustHomework.value.map(hw => ({
    homeworkId: hw.id,
    windowStart: hw.editWinStart || null,
    windowEnd: hw.editWinEnd || null,
    intervalDays: hw.editInterval,
    locked: hw.editLocked,
  }))
  await planApi.saveHomeworkAdjust(planId.value, adjustments as any)
  debouncedPreview()
}

// ======== Confirm generate ========
async function handleConfirm() {
  confirming.value = true
  const overrides = adjustHomework.value.map(hw => ({
    homeworkId: hw.id,
    windowStart: hw.editWinStart || null,
    windowEnd: hw.editWinEnd || null,
    intervalDays: hw.editInterval,
    locked: hw.editLocked,
  }))
  const res = await planApi.generate({
    planId: planId.value,
    rhythm: rhythm.value,
    maxDailyMinutes: maxDailyMinutes.value,
    targetEndDate: targetEndDateActual.value,
    homeworkOverrides: overrides as any,
  } as any)
  confirming.value = false
  if (res.ok) {
    router.push('/plan/' + planId.value)
  } else {
    alert('生成失败: ' + (res.error || ''))
  }
}

function handleBack() {
  if (step.value === 1) router.push('/plan/' + planId.value)
  else step.value = 1
}

// ======== Init ========
onMounted(async () => {
  // Fetch plan detail
  const planRes = await planApi.detail(planId.value)
  if (planRes.ok) {
    planDetail.value = planRes.data
    const s = new Date(planRes.data.start_date)
    const e = new Date(planRes.data.end_date)
    planStartTs.value = s.getTime()
    planEndTs.value = e.getTime()
    targetEndIdx.value = planDates.value.length - 1
  }

  // Fetch homework adjust list (v3: contains priority, intervalDays, allocatedRange)
  const [adjRes] = await Promise.all([
    planApi.getHomeworkAdjust(planId.value),
  ])

  if (adjRes.ok) {
    // Build sortedHomework from adjust data (sorted by priority already)
    sortedHomework.value = adjRes.data.homework.map(h => ({
      id: h.id,
      subject: h.subject,
      taskType: h.taskType,
      totalAmount: h.totalAmount,
      unit: h.unit,
      timePerUnit: h.timePerUnit,
      rangeStr: h.allocatedRange,
      autoInterval: 0,
    }))

    // Build adjustHomework with editable fields
    adjustHomework.value = adjRes.data.homework.map(h => {
      const startTs = dateStrToTs(h.windowStart || planMinDate.value)
      const endTs = dateStrToTs(h.windowEnd || planMaxDate.value)
      const winDays = ((endTs - startTs) / 86400) + 1
      const maxInterval = Math.max(0, Math.floor(winDays / Math.max(1, h.totalAmount)))
      return {
        ...h,
        editWinStart: h.windowStart,
        editWinEnd: h.windowEnd,
        editLocked: h.locked,
        editInterval: h.intervalDays,
        editStartTs: startTs,
        editEndTs: endTs,
        maxInterval: Math.max(1, Math.min(maxInterval, Math.floor(winDays / 2))),
      }
    })
  }

  // Fetch ranges for step 1 display
  await fetchRanges()
})
</script>

<style scoped>
.page { padding: 16px; padding-bottom: 100px; max-width: 600px; margin: 0 auto; }
.page-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.page-header h2 { font-size: 1.125rem; font-weight: 700; color: var(--color-text); }
.btn-back { padding: 6px 12px; border: 1.5px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-card); color: var(--color-text-secondary); font-size: 0.875rem; cursor: pointer; font-family: var(--font-family); }

/* Step 1: combined completion + cap card */
.combined-card { background: var(--color-card); border-radius: var(--radius); padding: 12px 16px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.combined-row { display: flex; align-items: center; gap: 12px; }
.combined-field { flex: 1; min-width: 0; }
.combined-field .combined-label { display: block; font-size: 0.8125rem; color: var(--color-text-secondary); margin-bottom: 4px; }
.combined-slider-wrap { display: flex; align-items: center; gap: 6px; }
.combined-slider-wrap input[type="range"] { flex: 1; accent-color: var(--color-primary); }
.combined-val { font-size: 0.8125rem; font-weight: 600; color: var(--color-primary); white-space: nowrap; min-width: 44px; text-align: right; }
.combined-divider { width: 1px; height: 36px; background: var(--color-border); flex-shrink: 0; }
.combined-cap .cap-inline-wrap { display: flex; align-items: center; gap: 4px; }
.cap-input-sm { width: 64px; padding: 4px 6px; border: 1.5px solid var(--color-border); border-radius: var(--radius-sm); font-size: 0.875rem; font-family: var(--font-family); background: var(--color-input-bg); color: var(--color-text); text-align: center; }
.combined-val-small { font-size: 0.75rem; color: var(--color-text-placeholder); }

/* Step 1: sort list compact */
.sort-section h3 { font-size: 0.9375rem; font-weight: 600; margin-bottom: 8px; color: var(--color-text); }
.sort-section .hint { font-weight: 400; font-size: 0.75rem; color: var(--color-text-placeholder); }
.sort-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
.sort-item-compact { padding: 10px 12px; }
.sort-item {
  display: flex; flex-wrap: wrap; align-items: flex-start; gap: 8px;
  padding: 12px 14px;
  background: var(--color-card);
  border-radius: var(--radius-sm);
  border: 1.5px solid var(--color-border);
  transition: transform 0.2s var(--ease-smooth), box-shadow 0.2s var(--ease-smooth);
  cursor: grab;
}
.sort-item.dragging { opacity: 0.4; }
.sort-item.over { border-color: var(--color-primary); box-shadow: 0 0 0 2px rgba(79,70,229,0.15); }
.drag-handle { font-size: 1.125rem; color: var(--color-text-placeholder); cursor: grab; padding-top: 2px; }
.sort-info { flex: 1; min-width: 0; }
.sort-name { font-weight: 600; font-size: 0.875rem; color: var(--color-text); display: block; }
.sort-amount { font-size: 0.75rem; color: var(--color-text-placeholder); display: block; margin-top: 2px; }
.sort-meta {
  width: 100%; font-size: 0.75rem; color: var(--color-text-placeholder);
  padding-left: 28px;
}
.sort-item-compact .sort-name { font-size: 0.8125rem; }
.sort-item-compact .sort-amount { font-size: 0.6875rem; }
.sort-item-compact .sort-meta { font-size: 0.6875rem; }
.sort-empty { text-align: center; padding: 24px; color: var(--color-text-placeholder); font-size: 0.875rem; }

/* Chart card */
.chart-card, .slider-card, .cap-card, .stats-card { background: var(--color-card); border-radius: var(--radius); padding: 16px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.chart-card h3, .slider-card h3, .cap-card h3, .stats-card h3 { font-size: 0.9375rem; font-weight: 600; margin-bottom: 8px; color: var(--color-text); }
.chart-wrap { overflow-x: auto; }
.chart-svg { display: block; }
.chart-labels { display: flex; justify-content: space-between; font-size: 0.6875rem; color: var(--color-text-placeholder); margin-top: 2px; }

.slider-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.slider-label { font-size: 0.8125rem; color: var(--color-text-secondary); white-space: nowrap; min-width: 60px; }
.slider-label:last-child { text-align: right; }
.rhythm-slider { flex: 1; accent-color: var(--color-primary); }
.preset-row { display: flex; gap: 6px; }
.preset-btn { flex: 1; padding: 8px; border: 1.5px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-card); color: var(--color-text-secondary); font-size: 0.8125rem; cursor: pointer; font-family: var(--font-family); text-align: center; }
.preset-btn.active { border-color: var(--color-primary); color: var(--color-primary); font-weight: 600; background: rgba(79,70,229,0.04); }

.cap-input-row { display: flex; align-items: center; gap: 8px; }
.cap-input { width: 100px; padding: 8px 10px; border: 1.5px solid var(--color-border); border-radius: var(--radius-sm); font-size: 1rem; font-family: var(--font-family); background: var(--color-input-bg); color: var(--color-text); text-align: center; }
.cap-unit { font-size: 0.875rem; color: var(--color-text-secondary); }

.stat-row { display: flex; gap: 6px; font-size: 0.8125rem; color: var(--color-text-secondary); align-items: center; }
.warn { color: var(--color-error); font-weight: 500; }

.btn-primary { width: 100%; padding: 14px; border: none; border-radius: var(--radius-sm); background: var(--color-primary); color: #fff; font-size: 1rem; font-weight: 600; cursor: pointer; font-family: var(--font-family); margin-top: 8px; }
.btn-primary:disabled { background: var(--color-primary-disabled); cursor: not-allowed; }
.btn-outline { flex: 1; padding: 14px; border: 1.5px solid var(--color-primary); border-radius: var(--radius-sm); background: transparent; color: var(--color-primary); font-size: 1rem; font-weight: 600; cursor: pointer; font-family: var(--font-family); }
.action-row { display: flex; gap: 8px; margin-top: 8px; }

.chart-mini { display: flex; align-items: center; gap: 12px; padding: 12px 16px; }
.chart-mini h3 { margin-bottom: 0; white-space: nowrap; }
.chart-mini .hint { font-weight: 400; font-size: 0.75rem; color: var(--color-text-placeholder); }

.adjust-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px; }
.adjust-card { background: var(--color-card); border-radius: var(--radius-sm); padding: 14px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
.adjust-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 0.875rem; }
.adjust-subject { font-weight: 600; color: var(--color-text); }
.adjust-amount { font-size: 0.75rem; color: var(--color-text-placeholder); }
.adjust-rows { display: flex; flex-direction: column; gap: 6px; }
.adjust-row { display: flex; align-items: center; gap: 8px; }
.adjust-row label { font-size: 0.75rem; color: var(--color-text-placeholder); min-width: 40px; }
.adjust-row input[type="range"] { flex: 1; accent-color: var(--color-primary); min-width: 0; }
.date-input { width: 110px; padding: 6px 8px; border: 1.5px solid var(--color-border); border-radius: 6px; font-size: 0.8125rem; font-family: var(--font-family); background: var(--color-input-bg); color: var(--color-text); flex-shrink: 0; }

.interval-label { font-size: 0.75rem; color: var(--color-text-secondary); white-space: nowrap; min-width: 80px; }

.toggle { position: relative; display: inline-block; width: 40px; height: 22px; }
.toggle input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; cursor: pointer; inset: 0; background: var(--color-border); border-radius: 22px; transition: 0.2s; }
.toggle-slider::before { content: ''; position: absolute; height: 18px; width: 18px; left: 2px; bottom: 2px; background: white; border-radius: 50%; transition: 0.2s; }
.toggle input:checked + .toggle-slider { background: var(--color-primary); }
.toggle input:checked + .toggle-slider::before { transform: translateX(18px); }

rect.bar-rect {
  transition: y 0.4s var(--ease-spring),
              height 0.4s var(--ease-spring),
              fill 0.3s var(--ease-smooth);
}
</style>
