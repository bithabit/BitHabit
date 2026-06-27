<template>
  <div class="page">
    <header class="page-header">
      <button class="btn-back" @click="$router.push('/plan/create')">← 返回</button>
      <h2>{{ plan?.name || '计划详情' }}</h2>
    </header>

    <div class="loading" v-if="loading">加载中...</div>
    <div class="error-msg" v-else-if="error">{{ error }}</div>

    <template v-else-if="plan">
      <!-- 摘要 -->
      <section class="summary-card">
        <div class="summary-grid">
          <div class="sum-item">
            <span class="sum-val">{{ plan.days.length }}</span>
            <span class="sum-label">计划天数</span>
          </div>
          <div class="sum-item">
            <span class="sum-val">{{ totalTasks }}</span>
            <span class="sum-label">任务数</span>
          </div>
          <div class="sum-item">
            <span class="sum-val">{{ totalMinutes }}</span>
            <span class="sum-label">总分钟</span>
          </div>
        </div>
        <div class="sum-date">{{ plan.start_date }} ~ {{ plan.end_date }}</div>
        <div class="sum-date">每日 {{ plan.daily_start_time.slice(0,5) }} - {{ plan.daily_end_time.slice(0,5) }}</div>
      </section>

      <router-link :to="`/plan/${plan.id}/calendar`" class="btn-calendar">
        📅 日历视图
      </router-link>

      <!-- 每日任务 -->
      <section class="days-list">
        <h3>📋 每日任务</h3>
        <details v-for="day in plan.days" :key="day.date" class="day-group" :open="day.date === today">
          <summary class="day-header">
            <span class="day-date">{{ day.date }} ({{ dayOfWeek(day.date) }})</span>
            <span class="day-count">{{ day.slots.length }} 项 · {{ dayTotalMinutes(day) }} 分</span>
          </summary>
          <div class="task-list">
            <div class="task-item" v-for="slot in day.slots" :key="slot.id">
              <span class="task-check" :class="{ done: slot.completed }">{{ slot.completed ? '✅' : '⬜' }}</span>
              <span class="task-subject">{{ subjectIcon(slot.subject) }}</span>
              <span class="task-info">
                {{ slot.subject }} · {{ slot.taskType }}
                <span class="task-amount">{{ formatAmount(slot.amount) }}{{ slot.unit }}</span>
              </span>
              <span class="task-time">{{ slot.estimatedMinutes }}分</span>
            </div>
          </div>
        </details>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { planApi, type PlanDetail, type PlanDay } from '../api'

const route = useRoute()
const plan = ref<PlanDetail | null>(null)
const loading = ref(true)
const error = ref('')

const today = computed(() => {
  const d = new Date()
  return d.toISOString().slice(0, 10)
})

const totalTasks = computed(() => {
  if (!plan.value) return 0
  return plan.value.days.reduce((sum, d) => sum + d.slots.length, 0)
})

const totalMinutes = computed(() => {
  if (!plan.value) return 0
  return plan.value.days.reduce((sum, d) =>
    sum + d.slots.reduce((s, slot) => s + slot.estimatedMinutes, 0), 0
  )
})

onMounted(async () => {
  const id = Number(route.params.id)
  if (!id) {
    error.value = '无效的计划 ID'
    loading.value = false
    return
  }

  const res = await planApi.detail(id)
  loading.value = false
  if (res.ok) {
    plan.value = res.data
  } else {
    error.value = res.error || '加载失败'
  }
})

function dayOfWeek(dateStr: string): string {
  const names = ['周日', '周一', '周二', '周三', '周四', '周五', '周六']
  const d = new Date(dateStr + 'T00:00:00')
  return names[d.getDay()]
}

function dayTotalMinutes(day: PlanDay): number {
  return day.slots.reduce((s, slot) => s + slot.estimatedMinutes, 0)
}

function subjectIcon(subject: string): string {
  const icons: Record<string, string> = {
    '数学': '📐', '英语': '🇬🇧', '语文': '📖', '物理': '⚛️',
    '化学': '🧪', '生物': '🧬', '历史': '📜', '地理': '🌍', '政治': '⚖️',
  }
  return icons[subject] || '📝'
}

function formatAmount(n: number): string {
  return Number.isInteger(n) ? n.toString() : n.toFixed(2).replace(/\.?0+$/, '')
}
</script>

<style scoped>
.page {
  padding: 16px;
  padding-bottom: 100px;
  max-width: 600px;
  margin: 0 auto;
}

.page-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}

.page-header h2 {
  font-size: 1.125rem;
  font-weight: 700;
}

.btn-back {
  padding: 6px 12px;
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-card);
  color: var(--color-text-secondary);
  font-size: 0.875rem;
  cursor: pointer;
  font-family: var(--font-family);
}

.loading, .error-msg {
  text-align: center;
  padding: 40px;
  color: var(--color-text-secondary);
}

.summary-card {
  background: var(--color-card);
  border-radius: var(--radius);
  padding: 16px 20px;
  margin-bottom: 16px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.summary-grid {
  display: flex;
  gap: 8px;
  margin-bottom: 10px;
}

.sum-item {
  flex: 1;
  text-align: center;
  padding: 8px;
  background: var(--color-input-bg);
  border-radius: var(--radius-sm);
}

.sum-val {
  display: block;
  font-size: 1.125rem;
  font-weight: 700;
  color: var(--color-primary);
}

.sum-label {
  font-size: 0.6875rem;
  color: var(--color-text-placeholder);
}

.sum-date {
  font-size: 0.8125rem;
  color: var(--color-text-secondary);
}

.days-list h3 {
  font-size: 1rem;
  font-weight: 600;
  margin-bottom: 10px;
}

.day-group {
  background: var(--color-card);
  border-radius: var(--radius-sm);
  margin-bottom: 8px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}

.day-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  cursor: pointer;
  list-style: none;
}

.day-header::-webkit-details-marker {
  display: none;
}

.day-date {
  font-weight: 600;
  font-size: 0.875rem;
}

.day-count {
  font-size: 0.75rem;
  color: var(--color-text-placeholder);
}

.task-list {
  padding: 0 16px 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.task-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  background: var(--color-input-bg);
  border-radius: 6px;
  font-size: 0.8125rem;
}

.task-check {
  font-size: 1rem;
  flex-shrink: 0;
}

.task-check.done {
  opacity: 0.6;
}

.task-subject {
  font-size: 1rem;
  flex-shrink: 0;
}

.task-info {
  flex: 1;
  min-width: 0;
}

.task-amount {
  font-weight: 600;
  color: var(--color-primary);
}

.task-time {
  color: var(--color-text-placeholder);
  font-size: 0.75rem;
  flex-shrink: 0;
}

.btn-calendar {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 14px;
  margin-bottom: 16px;
  background: var(--color-card);
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius);
  color: var(--color-primary);
  font-size: 0.9375rem;
  font-weight: 600;
  cursor: pointer;
  font-family: var(--font-family);
  text-decoration: none;
  transition: border-color 0.2s, background 0.2s;
}

.btn-calendar:active {
  border-color: var(--color-primary);
  background: rgba(79, 70, 229, 0.04);
}
</style>
