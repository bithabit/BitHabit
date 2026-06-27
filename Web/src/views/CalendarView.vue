<template>
  <div class="calendar-page">
    <!-- 顶部导航 -->
    <header class="cal-header">
      <button class="nav-arrow" @click="prevMonth" :disabled="isFirstMonth">‹</button>
      <div class="cal-title">
        <h2>{{ currentYear }}年 {{ currentMonth }}月</h2>
        <span class="plan-name" v-if="planName">{{ planName }}</span>
      </div>
      <button class="nav-arrow" @click="nextMonth" :disabled="isLastMonth">›</button>
    </header>

    <!-- 加载 -->
    <div class="loading-state" v-if="loading">
      <div class="spinner"></div>
    </div>

    <!-- 日历网格 -->
    <div class="calendar" v-else-if="cells.length > 0">
      <!-- 星期头 -->
      <div class="weekday-row">
        <div class="weekday" v-for="w in weekDays" :key="w">{{ w }}</div>
      </div>

      <!-- 周行 -->
      <div class="week-row" v-for="(week, wi) in weeks" :key="wi">
        <div
          class="day-cell"
          v-for="(cell, ci) in week"
          :key="ci"
          :class="dayCellClass(cell)"
          @click="cell && cell.isInRange ? selectDay(cell) : undefined"
        >
          <template v-if="cell">
            <span class="day-number">{{ cell.dayNumber }}</span>
            <div class="day-meta" v-if="cell.isInRange">
              <span v-if="cell.taskCount > 0 && cell.completedCount === cell.taskCount" class="day-done">✅</span>
              <span v-else-if="cell.taskCount > 0" class="day-count">{{ cell.completedCount }}/{{ cell.taskCount }}</span>
              <span v-else class="day-rest">休</span>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- 图例 -->
    <div class="legend" v-if="cells.length > 0">
      <span class="legend-item"><span class="legend-dot demo-done"></span> 全部完成</span>
      <span class="legend-item"><span class="legend-dot demo-partial"></span> 部分完成</span>
      <span class="legend-item"><span class="legend-dot demo-rest"></span> 休息日</span>
      <span class="legend-item"><span class="legend-dot demo-out"></span> 范围外</span>
    </div>

    <!-- 底部任务面板 (Bottom Sheet) -->
    <Transition name="sheet">
      <div class="sheet-overlay" v-if="selectedDay" @click.self="selectedDay = null">
        <div class="sheet-panel">
          <div class="sheet-header">
            <h3>{{ formatSheetDate(selectedDay.date) }}</h3>
            <button class="sheet-close" @click="selectedDay = null">✕</button>
          </div>

          <!-- 当天任务列表 -->
          <div class="sheet-tasks" v-if="dayTasks.length > 0">
            <div
              class="sheet-task card-press"
              :class="{ completed: t.completed }"
              v-for="t in dayTasks"
              :key="t.id"
              @click="handleToggle(t)"
            >
              <span class="task-check-sheet" :class="{ 'task-check-bounce': bouncingId === t.id }">
                {{ t.completed ? '✅' : '▢' }}
              </span>
              <div class="task-info-sheet">
                <div class="task-title-sheet">{{ getSubjectEmoji(t.subject) }} {{ t.subject }} · {{ t.taskType }}</div>
                <div class="task-meta-sheet">{{ formatAmount(t.amount) }} {{ t.unit }} · 约 {{ t.estimatedMinutes }} 分钟</div>
              </div>
            </div>
          </div>
          <div class="sheet-empty" v-else>暂无任务</div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { planApi, type CalendarData, type TodayTask } from '../api'

const route = useRoute()
const router = useRouter()

const planId = computed(() => Number(route.params.planId))

const weekDays = ['一', '二', '三', '四', '五', '六', '日']

const currentYear = ref(0)
const currentMonth = ref(0)
const planName = ref('')
const startDate = ref('')
const endDate = ref('')
const loading = ref(true)
const cells = ref<(CalendarCell | null)[]>([])
const selectedDay = ref<CalendarCell | null>(null)
const dayTasks = ref<TodayTask[]>([])
const bouncingId = ref<number | null>(null)

interface CalendarCell {
  date: string
  dayNumber: number
  taskCount: number
  completedCount: number
  totalMinutes: number
  isToday: boolean
  isPast: boolean
  isInRange: boolean
}

// 周分组
const weeks = computed(() => {
  const w: (CalendarCell | null)[][] = []
  let row: (CalendarCell | null)[] = []
  for (const cell of cells.value) {
    row.push(cell)
    if (row.length === 7) {
      w.push(row)
      row = []
    }
  }
  if (row.length > 0) w.push(row)
  return w
})

const isFirstMonth = computed(() => {
  if (!startDate.value) return true
  const start = new Date(startDate.value + 'T00:00:00')
  return currentYear.value === start.getFullYear() && currentMonth.value === start.getMonth() + 1
})

const isLastMonth = computed(() => {
  if (!endDate.value) return true
  const end = new Date(endDate.value + 'T00:00:00')
  return currentYear.value === end.getFullYear() && currentMonth.value === end.getMonth() + 1
})

function prevMonth() {
  if (isFirstMonth.value) return
  if (currentMonth.value === 1) {
    currentYear.value--
    currentMonth.value = 12
  } else {
    currentMonth.value--
  }
  updateUrl()
  fetchCalendar()
}

function nextMonth() {
  if (isLastMonth.value) return
  if (currentMonth.value === 12) {
    currentYear.value++
    currentMonth.value = 1
  } else {
    currentMonth.value++
  }
  updateUrl()
  fetchCalendar()
}

function updateUrl() {
  router.replace(`/plan/${planId.value}/calendar?month=${currentYear.value}-${String(currentMonth.value).padStart(2, '0')}`)
}

function dayCellClass(cell: CalendarCell | null): Record<string, boolean> {
  if (!cell) return { 'empty-cell': true }
  return {
    'day-today': cell.isToday,
    'day-past': cell.isPast,
    'day-out': !cell.isInRange,
    'day-done': cell.isInRange && cell.taskCount > 0 && cell.completedCount === cell.taskCount,
    'day-rest': cell.isInRange && cell.taskCount === 0,
    'day-partial': cell.isInRange && cell.taskCount > 0 && cell.completedCount < cell.taskCount,
  }
}

function buildMonthGrid(data: CalendarData) {
  planName.value = data.planName
  startDate.value = data.startDate
  endDate.value = data.endDate

  const firstDay = new Date(data.year, data.month - 1, 1)
  const lastDay = new Date(data.year, data.month, 0)
  const startDow = firstDay.getDay() // 0=Sun
  const startCol = startDow === 0 ? 6 : startDow - 1 // Mon=0

  const daysMap = new Map(data.days.map(d => [d.date, d]))
  const today = new Date().toISOString().slice(0, 10)
  const result: (CalendarCell | null)[] = []

  // 前置空白
  for (let i = 0; i < startCol; i++) result.push(null)

  // 月内日期
  for (let d = 1; d <= lastDay.getDate(); d++) {
    const dateStr = `${data.year}-${String(data.month).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    const dayData = daysMap.get(dateStr)
    result.push({
      date: dateStr,
      dayNumber: d,
      taskCount: dayData?.taskCount ?? 0,
      completedCount: dayData?.completedCount ?? 0,
      totalMinutes: dayData?.totalMinutes ?? 0,
      isToday: dateStr === today,
      isPast: dateStr < today,
      isInRange: dateStr >= data.startDate && dateStr <= data.endDate,
    })
  }

  cells.value = result
}

async function fetchCalendar() {
  loading.value = true
  const res = await planApi.calendar(planId.value, currentYear.value, currentMonth.value)
  loading.value = false
  if (res.ok) {
    buildMonthGrid(res.data)
  }
}

async function selectDay(cell: CalendarCell) {
  selectedDay.value = cell
  dayTasks.value = []
  const res = await planApi.getDay(planId.value, cell.date)
  if (res.ok) {
    dayTasks.value = res.data.tasks
  }
}

async function handleToggle(task: TodayTask) {
  const res = await planApi.toggleTask(task.id)
  if (res.ok) {
    task.completed = res.data.completed
    bouncingId.value = task.id
    setTimeout(() => { if (bouncingId.value === task.id) bouncingId.value = null }, 400)
    // 刷新日历数据以更新统计
    fetchCalendar()
  }
}

function getSubjectEmoji(subject: string): string {
  const map: Record<string, string> = {
    '数学': '📐', '语文': '📖', '英语': '🇬🇧', '物理': '⚡',
    '化学': '🧪', '生物': '🧬', '历史': '📜', '地理': '🌍', '政治': '⚖️',
  }
  return map[subject] || '📝'
}

function formatAmount(n: number): string {
  return Number.isInteger(n) ? n.toString() : n.toFixed(2).replace(/\.?0+$/, '')
}

function formatSheetDate(dateStr: string): string {
  const d = new Date(dateStr + 'T00:00:00')
  const dayNames = ['周日', '周一', '周二', '周三', '周四', '周五', '周六']
  return `${d.getMonth() + 1}月${d.getDate()}日 ${dayNames[d.getDay()]}`
}

// 初始化
onMounted(async () => {
  // 从 URL 参数读取初始月份
  const monthParam = route.query.month as string || ''
  const now = new Date()
  if (monthParam) {
    const parts = monthParam.split('-')
    currentYear.value = parseInt(parts[0])
    currentMonth.value = parseInt(parts[1])
  } else {
    currentYear.value = now.getFullYear()
    currentMonth.value = now.getMonth() + 1
  }
  await fetchCalendar()

  // 如果当前(或查询)月份没有计划数据，跳到计划开始的月份
  if (planName.value && startDate.value) {
    const start = new Date(startDate.value + 'T00:00:00')
    const startYM = start.getFullYear() * 12 + start.getMonth()
    const curYM = currentYear.value * 12 + (currentMonth.value - 1)
    if (curYM < startYM) {
      currentYear.value = start.getFullYear()
      currentMonth.value = start.getMonth() + 1
      updateUrl()
      await fetchCalendar()
    }
  }
})
</script>

<style scoped>
.calendar-page {
  min-height: 100vh;
  min-height: 100dvh;
  padding-bottom: 20px;
  background: var(--color-bg);
}

/* 顶部导航 */
.cal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 16px 8px;
  background: var(--color-card);
  border-bottom: 1px solid var(--color-border);
  position: sticky;
  top: 0;
  z-index: 10;
}

.cal-title {
  text-align: center;
  flex: 1;
}

.cal-title h2 {
  font-size: 1.125rem;
  font-weight: 700;
  color: var(--color-text);
}

.plan-name {
  display: block;
  font-size: 0.75rem;
  color: var(--color-text-placeholder);
  margin-top: 1px;
}

.nav-arrow {
  background: none;
  border: none;
  font-size: 1.5rem;
  color: var(--color-text-secondary);
  cursor: pointer;
  padding: 8px 12px;
  border-radius: 8px;
  transition: background 0.15s;
}

.nav-arrow:active {
  background: var(--color-input-bg);
}

.nav-arrow:disabled {
  opacity: 0.3;
  cursor: default;
}

/* 日历网格 */
.calendar {
  padding: 8px 12px;
}

.weekday-row {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 2px;
  margin-bottom: 4px;
}

.weekday {
  text-align: center;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-placeholder);
  padding: 4px 0;
}

.week-row {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 2px;
  margin-bottom: 2px;
}

/* 日期格子 */
.day-cell {
  aspect-ratio: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  font-size: 0.8125rem;
  transition: background 0.15s;
  min-height: 52px;
  padding: 2px;
  cursor: default;
}

.day-cell:active {
  transform: scale(0.95);
}

.day-cell.day-inrange:not(.day-out) {
  cursor: pointer;
}

.day-number {
  font-weight: 500;
  color: var(--color-text);
  line-height: 1.3;
}

.day-meta {
  font-size: 0.625rem;
  line-height: 1.2;
  margin-top: 1px;
}

.day-count {
  color: var(--color-text-secondary);
}

.day-done .day-number {
  color: var(--color-success);
  font-weight: 700;
}

.day-rest .day-meta {
  color: var(--color-text-placeholder);
}

.day-today {
  background: rgba(79, 70, 229, 0.08);
  border: 1.5px solid var(--color-primary);
}

.day-today .day-number {
  color: var(--color-primary);
  font-weight: 700;
}

.day-past {
  opacity: 0.55;
}

.day-out .day-number {
  opacity: 0.2;
}

.day-out .day-meta {
  display: none;
}

.day-partial .day-meta {
  color: var(--color-text-secondary);
}

/* 图例 */
.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: center;
  padding: 12px 16px;
  margin: 0 12px;
  background: var(--color-card);
  border-radius: var(--radius);
  font-size: 0.75rem;
  color: var(--color-text-placeholder);
}

.legend-item {
  display: flex;
  align-items: center;
  gap: 4px;
}

.legend-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
}

.demo-done { background: var(--color-success); }
.demo-partial { background: var(--color-primary); opacity: 0.5; }
.demo-rest { background: var(--color-border); }
.demo-out { background: var(--color-border); opacity: 0.3; }

/* Bottom Sheet */
.sheet-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.3);
  z-index: 200;
  display: flex;
  align-items: flex-end;
}

.sheet-panel {
  width: 100%;
  max-height: 60vh;
  background: var(--color-card);
  border-radius: 16px 16px 0 0;
  padding: 20px 20px calc(20px + env(safe-area-inset-bottom, 0px));
  overflow-y: auto;
  animation: sheetUp 0.3s var(--ease-smooth) both;
}

@keyframes sheetUp {
  from { transform: translateY(100%); }
  to { transform: translateY(0); }
}

.sheet-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.sheet-header h3 {
  font-size: 1.0625rem;
  font-weight: 700;
  color: var(--color-text);
}

.sheet-close {
  background: none;
  border: none;
  font-size: 1.25rem;
  color: var(--color-text-placeholder);
  cursor: pointer;
  padding: 4px 8px;
}

.sheet-tasks {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.sheet-task {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px;
  background: var(--color-input-bg);
  border-radius: var(--radius-sm);
}

.sheet-task.completed {
  opacity: 0.55;
}

.sheet-task.completed .task-title-sheet {
  text-decoration: line-through;
}

.task-check-sheet {
  font-size: 1.125rem;
  flex-shrink: 0;
  width: 28px;
  text-align: center;
  color: var(--color-text-placeholder);
}

.task-info-sheet {
  flex: 1;
  min-width: 0;
}

.task-title-sheet {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-text);
  margin-bottom: 1px;
}

.task-meta-sheet {
  font-size: 0.75rem;
  color: var(--color-text-placeholder);
}

.sheet-empty {
  text-align: center;
  padding: 32px 0;
  color: var(--color-text-placeholder);
  font-size: 0.875rem;
}

/* Bottom Sheet 过渡 */
.sheet-enter-active {
  transition: opacity 0.25s ease;
}

.sheet-leave-active {
  transition: opacity 0.2s ease;
}

.sheet-enter-from,
.sheet-leave-to {
  opacity: 0;
}

/* 加载 */
.loading-state {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 80px 20px;
}

.spinner {
  display: inline-block;
  width: 28px;
  height: 28px;
  border: 3px solid var(--color-border);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
