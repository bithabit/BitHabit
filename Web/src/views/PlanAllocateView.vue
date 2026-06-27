<template>
  <div class="page">
    <header class="page-header">
      <button class="btn-back" @click="handleBack">← 返回</button>
      <h2>{{ step === 1 ? '分配策略' : '逐项调整' }}</h2>
    </header>

    <!-- Step 1: 总体策略 -->
    <template v-if="step === 1">
      <!-- 折线图预览 -->
      <section class="chart-card">
        <h3>📊 工作量预览</h3>
        <div class="chart-wrap" ref="chartRef">
          <svg :width="chartW" :height="chartH" class="chart-svg">
            <!-- 上限虚线 -->
            <line v-for="y in capLines" :key="y"
              :x1="0" :y1="y" :x2="chartW" :y2="y"
              stroke="#ef4444" stroke-dasharray="4,3" stroke-width="1" opacity="0.5"/>
            <!-- 柱子 -->
            <rect v-for="(bar, i) in bars" :key="i"
              :x="bar.x" :y="bar.y" :width="bar.w" :height="bar.h"
              :fill="bar.over ? '#ef4444' : '#4F46E5'" rx="2"
              opacity="0.8">
              <title>{{ bar.label }}</title>
            </rect>
          </svg>
        </div>
        <div class="chart-labels">
          <span>{{ dates[0] }}</span>
          <span>{{ dates[Math.floor(dates.length / 2)] }}</span>
          <span>{{ dates[dates.length - 1] }}</span>
        </div>
      </section>

      <!-- 节奏滑块 -->
      <section class="slider-card">
        <h3>🎚 节奏偏好</h3>
        <div class="slider-row">
          <span class="slider-label">前紧后松</span>
          <input type="range" min="-2" max="2" step="1" v-model.number="rhythm" class="rhythm-slider" @input="refreshPreview" />
          <span class="slider-label">前松后紧</span>
        </div>
        <div class="preset-row">
          <button v-for="p in presets" :key="p.val" class="preset-btn" :class="{ active: rhythm === p.val }" @click="rhythm = p.val; refreshPreview()">{{ p.label }}</button>
        </div>
      </section>

      <!-- 每日上限 -->
      <section class="cap-card">
        <h3>⏱ 每日作业上限</h3>
        <div class="cap-input-row">
          <input type="number" v-model.number="maxDailyMinutes" min="30" max="720" class="cap-input" @input="refreshPreview" />
          <span class="cap-unit">分钟</span>
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

      <button class="btn-primary btn-press" :disabled="!preview" @click="step = 2">
        下一步：逐项调整 →
      </button>
    </template>

    <!-- Step 2: 逐项调整 -->
    <template v-if="step === 2">
      <!-- 缩小版折线图 -->
      <section class="chart-card chart-mini" @click="step = 1" style="cursor:pointer">
        <h3>📊 预览 <span class="hint">（点击返回调整节奏）</span></h3>
        <svg :width="200" :height="60" class="chart-svg">
          <rect v-for="(bar, i) in miniBars" :key="i"
            :x="bar.x" :y="bar.y" :width="bar.w" :height="bar.h"
            :fill="bar.over ? '#ef4444' : '#4F46E5'" rx="1" opacity="0.7"/>
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
            <div class="adjust-row">
              <label>起始日</label>
              <input type="date" v-model="hw.editWinStart" class="date-input" @change="onAdjustChange" />
            </div>
            <div class="adjust-row">
              <label>截止日</label>
              <input type="date" v-model="hw.editWinEnd" class="date-input" @change="onAdjustChange" />
            </div>
            <div class="adjust-row">
              <label>锁定</label>
              <label class="toggle">
                <input type="checkbox" v-model="hw.editLocked" @change="onAdjustChange" />
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
import { planApi, type PreviewResult, type HomeworkAdjustItem } from '../api'

const route = useRoute()
const router = useRouter()
const planId = computed(() => Number(route.params.id))

const step = ref(1)

// Step 1
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

// Step 2
const adjustHomework = ref<(HomeworkAdjustItem & { editWinStart: string | null; editWinEnd: string | null; editLocked: boolean })[]>([])
const confirming = ref(false)

function subjectIcon(s: string): string {
  const m: Record<string,string> = {'数学':'📐','英语':'🇬🇧','语文':'📖','物理':'⚛️','化学':'🧪','生物':'🧬','历史':'📜','地理':'🌍','政治':'⚖️'}
  return m[s] || '📝'
}
function fmt(n: number): string { return Number.isInteger(n) ? n.toString() : n.toFixed(2).replace(/\.?0+$/, '') }

let previewTimer = 0
function refreshPreview() {
  clearTimeout(previewTimer)
  previewTimer = window.setTimeout(async () => {
    const overrides = adjustHomework.value.map(hw => ({
      homeworkId: hw.id,
      windowStart: hw.editWinStart || null,
      windowEnd: hw.editWinEnd || null,
      locked: hw.editLocked,
    }))
    const res = await planApi.preview({ planId: planId.value, rhythm: rhythm.value, maxDailyMinutes: maxDailyMinutes.value, homeworkOverrides: overrides.length > 0 ? overrides : undefined })
    if (res.ok) preview.value = res.data
  }, 200)
}

async function onAdjustChange() {
  // Save to backend
  const adjustments = adjustHomework.value.map(hw => ({
    homeworkId: hw.id,
    windowStart: hw.editWinStart || null,
    windowEnd: hw.editWinEnd || null,
    locked: hw.editLocked,
  }))
  await planApi.saveHomeworkAdjust(planId.value, adjustments)
  // Refresh preview
  refreshPreview()
}

async function handleConfirm() {
  confirming.value = true
  const overrides = adjustHomework.value.map(hw => ({
    homeworkId: hw.id,
    windowStart: hw.editWinStart || null,
    windowEnd: hw.editWinEnd || null,
    locked: hw.editLocked,
  }))
  const res = await planApi.generate({ planId: planId.value, rhythm: rhythm.value, maxDailyMinutes: maxDailyMinutes.value, homeworkOverrides: overrides } as any)
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

onMounted(async () => {
  const [adjRes] = await Promise.all([
    planApi.getHomeworkAdjust(planId.value),
  ])
  if (adjRes.ok) {
    adjustHomework.value = adjRes.data.homework.map(h => ({
      ...h,
      editWinStart: h.windowStart,
      editWinEnd: h.windowEnd,
      editLocked: h.locked,
    }))
  }
  refreshPreview()
})
</script>

<style scoped>
.page { padding: 16px; padding-bottom: 100px; max-width: 600px; margin: 0 auto; }
.page-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.page-header h2 { font-size: 1.125rem; font-weight: 700; color: var(--color-text); }
.btn-back { padding: 6px 12px; border: 1.5px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-card); color: var(--color-text-secondary); font-size: 0.875rem; cursor: pointer; font-family: var(--font-family); }

.chart-card, .slider-card, .cap-card, .stats-card { background: var(--color-card); border-radius: var(--radius); padding: 16px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
h3 { font-size: 0.9375rem; font-weight: 600; margin-bottom: 8px; color: var(--color-text); }

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
.date-input { flex: 1; padding: 6px 8px; border: 1.5px solid var(--color-border); border-radius: 6px; font-size: 0.8125rem; font-family: var(--font-family); background: var(--color-input-bg); color: var(--color-text); }

.toggle { position: relative; display: inline-block; width: 40px; height: 22px; }
.toggle input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; cursor: pointer; inset: 0; background: var(--color-border); border-radius: 22px; transition: 0.2s; }
.toggle-slider::before { content: ''; position: absolute; height: 18px; width: 18px; left: 2px; bottom: 2px; background: white; border-radius: 50%; transition: 0.2s; }
.toggle input:checked + .toggle-slider { background: var(--color-primary); }
.toggle input:checked + .toggle-slider::before { transform: translateX(18px); }

.action-row { display: flex; gap: 8px; margin-top: 8px; }
</style>
