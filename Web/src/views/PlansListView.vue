<template>
  <div class="plans-page">
    <!-- 标题 -->
    <header class="page-header">
      <h2>🎯 我的计划</h2>
    </header>

    <!-- 加载中 -->
    <div class="loading-state" v-if="loading">
      <div class="spinner"></div>
    </div>

    <!-- 计划列表 -->
    <div class="plan-list" v-else-if="plans.length > 0">
      <div
        class="plan-card"
        v-for="plan in plans"
        :key="plan.id"
        @click="router.push('/plan/' + plan.id)"
      >
        <div class="plan-card-header">
          <span class="plan-name">{{ plan.name }}</span>
          <span class="plan-arrow">→</span>
        </div>
        <div class="plan-dates">{{ plan.start_date }} ~ {{ plan.end_date }}</div>
        <div class="plan-stats">{{ plan.task_count }} 项任务</div>
        <!-- 进度条 -->
        <div class="progress-bar" v-if="plan.task_count > 0">
          <div class="progress-fill" :style="{ width: progressPct(plan) + '%' }"></div>
        </div>
        <div class="progress-text" v-if="plan.task_count > 0">
          {{ plan.completed_count }}/{{ plan.task_count }} 已完成
        </div>
        <div class="plan-created">创建于 {{ formatDate(plan.created_at) }}</div>
      </div>
    </div>

    <!-- 空状态 -->
    <div class="empty-state" v-else>
      <div class="empty-icon">🎯</div>
      <div class="empty-title">还没有计划</div>
      <div class="empty-hint">点击下方按钮生成第一个计划</div>
      <button class="btn-generate btn-press" @click="handleCreatePlan">
        ✨ 生成我的计划
      </button>
    </div>

    <!-- 新建计划按钮（有列表时显示为加号卡片） -->
    <div class="new-plan-card card-press" v-if="!loading && plans.length > 0" @click="handleCreatePlan">
      <span class="new-plan-icon">＋</span>
      <span class="new-plan-text">新建计划</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useHomeworkStore } from '../stores/homework'
import { planApi, type PlanListItem } from '../api'

const router = useRouter()
const homeworkStore = useHomeworkStore()

const plans = ref<PlanListItem[]>([])
const loading = ref(true)

function progressPct(plan: PlanListItem): number {
  if (plan.task_count === 0) return 0
  return Math.round((plan.completed_count / plan.task_count) * 100)
}

function formatDate(dateStr: string): string {
  const d = new Date(dateStr.replace(' ', 'T'))
  const month = d.getMonth() + 1
  const day = d.getDate()
  return `${month}月${day}日`
}

async function handleCreatePlan() {
  // 检查是否有作业数据
  await homeworkStore.fetchAll()
  if (homeworkStore.items.length === 0) {
    alert('请先在「作业」页面录入暑假作业')
    router.push('/homework')
    return
  }
  router.push('/plan/create')
}

onMounted(async () => {
  const res = await planApi.list()
  if (res.ok) {
    plans.value = res.data.plans
  }
  loading.value = false
})
</script>

<style scoped>
.plans-page {
  min-height: 100vh;
  min-height: 100dvh;
  padding: 16px;
  padding-bottom: 80px;
  max-width: 600px;
  margin: 0 auto;
  background: var(--color-bg);
}

.page-header h2 {
  font-size: 1.25rem;
  font-weight: 700;
  margin-bottom: 16px;
  color: var(--color-text);
}

/* 计划卡片 */
.plan-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.plan-card {
  background: var(--color-card);
  border-radius: var(--radius);
  padding: 16px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
  cursor: pointer;
  transition: transform var(--duration-fast) var(--ease-smooth), box-shadow var(--duration-normal) ease;
  animation: fadeInUp var(--duration-slow) ease both;
}

.plan-card:active {
  transform: scale(0.98);
}

.plan-card:nth-child(1) { animation-delay: 0s; }
.plan-card:nth-child(2) { animation-delay: 0.08s; }
.plan-card:nth-child(3) { animation-delay: 0.16s; }
.plan-card:nth-child(4) { animation-delay: 0.24s; }
.plan-card:nth-child(5) { animation-delay: 0.32s; }

.plan-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}

.plan-name {
  font-size: 1rem;
  font-weight: 600;
  color: var(--color-text);
}

.plan-arrow {
  color: var(--color-text-placeholder);
  font-size: 1rem;
}

.plan-dates {
  font-size: 0.8125rem;
  color: var(--color-text-secondary);
  margin-bottom: 4px;
}

.plan-stats {
  font-size: 0.8125rem;
  color: var(--color-text-placeholder);
  margin-bottom: 10px;
}

/* 进度条 */
.progress-bar {
  height: 6px;
  background: var(--color-input-bg);
  border-radius: 3px;
  overflow: hidden;
  margin-bottom: 4px;
}

.progress-fill {
  height: 100%;
  background: var(--color-primary);
  border-radius: 3px;
  transition: width 0.3s ease;
}

.progress-text {
  font-size: 0.75rem;
  color: var(--color-text-secondary);
  margin-bottom: 4px;
}

.plan-created {
  font-size: 0.75rem;
  color: var(--color-text-placeholder);
}

/* 新建计划卡片 */
.new-plan-card {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 16px;
  margin-top: 8px;
  background: var(--color-card);
  border: 2px dashed var(--color-border);
  border-radius: var(--radius);
  cursor: pointer;
  transition: border-color 0.2s;
  color: var(--color-text-secondary);
}

.new-plan-card:hover {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

.new-plan-icon {
  font-size: 1.25rem;
  font-weight: 300;
}

.new-plan-text {
  font-size: 0.9375rem;
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

.empty-title {
  font-size: 1.125rem;
  font-weight: 600;
  color: var(--color-text);
  margin-bottom: 8px;
}

.empty-hint {
  font-size: 0.875rem;
  color: var(--color-text-secondary);
  margin-bottom: 24px;
}

.btn-generate {
  padding: 14px 32px;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--color-primary);
  color: #fff;
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  font-family: var(--font-family);
  transition: background 0.2s;
}

.btn-generate:hover {
  background: var(--color-primary-hover);
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
