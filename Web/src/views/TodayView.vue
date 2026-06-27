<template>
  <div class="today-page">
    <!-- 顶部栏 -->
    <header class="today-header">
      <h1>BitHabit</h1>
      <div class="user-area">
        <span class="greeting" v-if="auth.userInfo">{{ auth.userInfo.nickname }}</span>
        <button class="btn-logout" @click="handleLogout">退出</button>
      </div>
    </header>

    <!-- 日期显示 -->
    <div class="date-header" v-if="todayData">
      <span class="date-text">{{ formatDate(todayData.date) }}</span>
    </div>

    <!-- 有任务时 -->
    <div class="task-list" v-if="todayData && todayData.tasks.length > 0">
      <div
        class="task-card card-press"
        :class="{ completed: task.completed }"
        v-for="task in todayData.tasks"
        :key="task.id"
        @click="toggleTask(task)"
      >
        <div class="task-check">
          <span v-if="task.completed" :class="{ 'task-check-bounce': bouncingId === task.id }">✅</span>
          <span v-else class="uncheck" :class="{ 'task-check-bounce': bouncingId === task.id }">▢</span>
        </div>
        <div class="task-info">
          <div class="task-title">
            <span class="task-subject">{{ getSubjectEmoji(task.subject) }} {{ task.subject }}</span>
            <span class="task-sep">·</span>
            <span class="task-type">{{ task.taskType }}</span>
          </div>
          <div class="task-meta">
            <span>{{ formatAmount(task.amount) }} {{ task.unit }}</span>
            <span class="meta-sep">·</span>
            <span>约 {{ task.estimatedMinutes }} 分钟</span>
          </div>
        </div>
      </div>

      <!-- 摘要 -->
      <div class="today-summary">
        总计：{{ todayData.tasks.length }} 项 · 约 {{ totalMinutes }} 分钟
      </div>
    </div>

    <!-- 无计划 -->
    <div class="empty-state" v-else-if="todayData && todayData.planId === null">
      <div class="empty-icon empty-icon-float">📋</div>
      <div class="empty-title">还没有学习计划</div>
      <div class="empty-hint">去「计划」页面生成一个吧 →</div>
    </div>

    <!-- 今日无任务（休息日） -->
    <div class="empty-state" v-else-if="todayData && todayData.tasks.length === 0">
      <div class="empty-icon empty-icon-float">🎉</div>
      <div class="empty-title">今天没有任务</div>
      <div class="empty-hint">好好休息一下吧～</div>
    </div>

    <!-- 加载中 -->
    <div class="loading-state" v-else>
      <div class="spinner"></div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { planApi, type TodayData } from '../api'

const auth = useAuthStore()
const router = useRouter()

const todayData = ref<TodayData | null>(null)
const bouncingId = ref<number | null>(null)

const totalMinutes = computed(() => {
  if (!todayData.value) return 0
  return todayData.value.tasks.reduce((sum, t) => sum + t.estimatedMinutes, 0)
})

const dayNames = ['周日', '周一', '周二', '周三', '周四', '周五', '周六']

function formatDate(dateStr: string): string {
  const d = new Date(dateStr + 'T00:00:00')
  const month = d.getMonth() + 1
  const day = d.getDate()
  const dow = dayNames[d.getDay()]
  return `📅 ${month}月${day}日 ${dow}`
}

function formatAmount(n: number): string {
  return Number.isInteger(n) ? n.toString() : n.toFixed(2).replace(/\.?0+$/, '')
}

function getSubjectEmoji(subject: string): string {
  const map: Record<string, string> = {
    '数学': '📐',
    '语文': '📖',
    '英语': '🇬🇧',
    '物理': '⚡',
    '化学': '🧪',
    '生物': '🧬',
    '历史': '📜',
    '地理': '🌍',
    '政治': '⚖️',
  }
  return map[subject] || '📝'
}

async function toggleTask(task: { id: number; completed: boolean }) {
  const res = await planApi.toggleTask(task.id)
  if (res.ok) {
    task.completed = res.data.completed
    bouncingId.value = task.id
    setTimeout(() => { if (bouncingId.value === task.id) bouncingId.value = null }, 400)
  }
}

function handleLogout() {
  auth.clearAuth()
  router.push('/login')
}

onMounted(async () => {
  const res = await planApi.today()
  if (res.ok) {
    todayData.value = res.data
  }
})
</script>

<style scoped>
.today-page {
  min-height: 100vh;
  min-height: 100dvh;
  padding-bottom: 80px;
  background: var(--color-bg);
}

.today-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  background: var(--color-card);
  border-bottom: 1px solid var(--color-border);
  position: sticky;
  top: 0;
  z-index: 10;
}

.today-header h1 {
  font-size: 1.125rem;
  font-weight: 700;
  color: var(--color-primary);
}

.user-area {
  display: flex;
  align-items: center;
  gap: 12px;
}

.greeting {
  font-size: 0.875rem;
  color: var(--color-text-secondary);
}

.btn-logout {
  padding: 6px 14px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-card);
  color: var(--color-text-secondary);
  font-size: 0.8125rem;
  cursor: pointer;
  font-family: var(--font-family);
  transition: border-color 0.2s, color 0.2s;
}

.btn-logout:hover {
  border-color: var(--color-error);
  color: var(--color-error);
}

/* 日期头 */
.date-header {
  padding: 20px 20px 8px;
}

.date-text {
  font-size: 1.125rem;
  font-weight: 600;
  color: var(--color-text);
}

/* 任务卡片 */
.task-list {
  padding: 8px 16px;
}

.task-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  margin-bottom: 8px;
  background: var(--color-card);
  border-radius: var(--radius);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
  cursor: pointer;
  transition: transform var(--duration-fast) var(--ease-smooth), box-shadow var(--duration-normal) ease;
}

.task-card.completed {
  opacity: 0.6;
}

.task-card.completed .task-title {
  text-decoration: line-through;
  transition: text-decoration 0.3s ease;
}

.task-check {
  font-size: 1.375rem;
  flex-shrink: 0;
  width: 32px;
  text-align: center;
}

.uncheck {
  color: var(--color-text-placeholder);
}

/* ✅ 打卡弹簧 */
.task-check-bounce {
  animation: checkBounce 0.4s var(--ease-spring);
}

.task-info {
  flex: 1;
  min-width: 0;
}

.task-title {
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--color-text);
  margin-bottom: 2px;
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
}

.task-subject {
  white-space: nowrap;
}

.task-sep {
  color: var(--color-text-placeholder);
  font-weight: 400;
}

.task-type {
  color: var(--color-text-secondary);
  font-weight: 500;
}

.task-meta {
  font-size: 0.8125rem;
  color: var(--color-text-placeholder);
  display: flex;
  align-items: center;
  gap: 4px;
}

.meta-sep {
  color: var(--color-border);
}

/* 摘要 */
.today-summary {
  text-align: center;
  font-size: 0.8125rem;
  color: var(--color-text-secondary);
  padding: 16px 0;
  font-weight: 500;
}

/* 空状态 */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80px 20px;
  text-align: center;
}

.empty-icon {
  font-size: 3rem;
  margin-bottom: 16px;
}

.empty-icon-float {
  animation: float 3s ease-in-out infinite;
}

.empty-title {
  font-size: 1.125rem;
  font-weight: 600;
  color: var(--color-text);
  margin-bottom: 8px;
}

.empty-hint {
  font-size: 0.875rem;
  color: var(--color-text-secondary);
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
