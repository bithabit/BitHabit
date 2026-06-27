<template>
  <div class="plans-page">
    <header class="page-header">
      <h2>🎯 我的计划</h2>
      <button class="btn-new" @click="router.push('/plan/create')">+ 新建</button>
    </header>

    <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

    <template v-else-if="plans.length > 0">
      <!-- 活跃 & 待开始 -->
      <div class="section-label" v-if="activePlans.length">🔵 进行中</div>
      <div class="plan-list">
        <div
          class="plan-card"
          v-for="plan in activePlans"
          :key="plan.id"
        >
          <div class="plan-main" @click="router.push('/plan/' + plan.id)">
            <div class="plan-top">
              <span class="plan-name">{{ plan.name }}</span>
              <span class="plan-arrow">→</span>
            </div>
            <div class="plan-dates">{{ plan.start_date }} ~ {{ plan.end_date }}</div>
            <div class="plan-stats">{{ plan.homework_count }} 项作业 · {{ plan.task_count }} 个任务</div>
            <div class="plan-progress" v-if="plan.task_count > 0">
              <div class="progress-bar-wrap">
                <div class="progress-fill" :style="{ width: pct(plan) + '%' }" :class="{ full: pct(plan) >= 100 }"></div>
              </div>
              <span class="progress-text">{{ plan.completed_count }}/{{ plan.task_count }}</span>
            </div>
          </div>
          <button class="btn-archive" @click.stop="handleArchive(plan)" v-if="plan.status === 'active'">归档</button>
        </div>
      </div>

      <!-- 已完成/已过期 -->
      <div class="section-label" v-if="archivedPlans.length">📦 已完成 / 已过期</div>
      <div class="plan-list">
        <div
          class="plan-card plan-card-archived"
          v-for="plan in archivedPlans"
          :key="plan.id"
        >
          <div class="plan-main" @click="router.push('/plan/' + plan.id)">
            <div class="plan-top">
              <span class="plan-name">{{ plan.name }}</span>
              <span class="status-tag" :class="plan.status === 'completed' ? 'tag-done' : 'tag-expired'">
                {{ plan.status === 'completed' ? '已完成' : '已过期' }}
              </span>
              <span class="plan-arrow">→</span>
            </div>
            <div class="plan-dates">{{ plan.start_date }} ~ {{ plan.end_date }}</div>
            <div class="plan-stats">{{ plan.homework_count }} 项作业</div>
            <div class="plan-progress" v-if="plan.task_count > 0">
              <div class="progress-bar-wrap">
                <div class="progress-fill" :style="{ width: pct(plan) + '%' }" :class="{ full: pct(plan) >= 100 }"></div>
              </div>
              <span class="progress-text">{{ plan.completed_count }}/{{ plan.task_count }}</span>
            </div>
          </div>
          <button class="btn-delete-archive" @click.stop="handleDeleteArchived(plan)">🗑 删除</button>
        </div>
      </div>
    </template>

    <div class="empty-state" v-else>
      <div class="empty-icon">🎯</div>
      <div class="empty-title">还没有计划</div>
      <div class="empty-hint">点击下方按钮创建第一个计划</div>
      <button class="btn-generate btn-press" @click="router.push('/plan/create')">
        ✨ 创建计划
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { planApi, type PlanListItem } from '../api'

const router = useRouter()
const plans = ref<PlanListItem[]>([])
const loading = ref(true)

const activePlans = computed(() => plans.value.filter(p => p.status === 'active' || p.status === 'pending'))
const archivedPlans = computed(() => plans.value.filter(p => p.status === 'completed' || p.status === 'expired'))

function pct(plan: PlanListItem): number {
  if (plan.task_count === 0) return 0
  return Math.round((plan.completed_count / plan.task_count) * 100)
}

async function handleArchive(plan: PlanListItem) {
  if (!confirm(`归档「${plan.name}」？归档后不再出现在今日页面。`)) return
  const res = await planApi.archive(plan.id)
  if (res.ok) {
    const listRes = await planApi.list()
    if (listRes.ok) plans.value = listRes.data.plans
  }
}

async function handleDeleteArchived(plan: PlanListItem) {
  if (!confirm(`确定删除「${plan.name}」？

此操作不可撤销，将一并删除该计划下的所有作业和任务。`)) return
  const res = await planApi.remove(plan.id)
  if (res.ok) {
    plans.value = plans.value.filter(p => p.id !== plan.id)
  }
}

onMounted(async () => {
  const res = await planApi.list()
  if (res.ok) plans.value = res.data.plans
  loading.value = false
})
</script>

<style scoped>
.plans-page {
  min-height: 100vh; min-height: 100dvh;
  padding: 16px; padding-bottom: 80px;
  max-width: 600px; margin: 0 auto;
  background: var(--color-bg);
}

.page-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px;
}

.page-header h2 { font-size: 1.25rem; font-weight: 700; color: var(--color-text); }

.btn-new {
  padding: 8px 18px; border: none; border-radius: var(--radius-sm);
  background: var(--color-primary); color: #fff;
  font-size: 0.875rem; font-weight: 600; cursor: pointer;
  font-family: var(--font-family);
}

.section-label {
  font-size: 0.8125rem; font-weight: 600;
  color: var(--color-text-secondary);
  margin: 12px 0 8px;
}

.plan-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px; }

.plan-card {
  background: var(--color-card); border-radius: var(--radius);
  box-shadow: 0 1px 3px rgba(0,0,0,0.06); display: flex;
  animation: fadeInUp var(--duration-slow) ease both;
}
.plan-card-archived { opacity: 0.7; }

.plan-main {
  flex: 1; padding: 16px 20px; cursor: pointer;
  transition: transform var(--duration-fast) var(--ease-smooth);
}
.plan-main:active { transform: scale(0.98); }

.plan-top { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }

.plan-name { font-size: 1rem; font-weight: 600; color: var(--color-text); flex: 1; }
.plan-arrow { color: var(--color-text-placeholder); font-size: 0.875rem; }

.plan-dates { font-size: 0.8125rem; color: var(--color-text-secondary); }
.plan-stats { font-size: 0.75rem; color: var(--color-text-placeholder); margin-top: 2px; }

.plan-progress { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
.progress-bar-wrap { flex: 1; height: 5px; background: var(--color-input-bg); border-radius: 3px; overflow: hidden; }
.progress-fill { height: 100%; background: var(--color-primary); border-radius: 3px; transition: width 0.3s; }
.progress-fill.full { background: var(--color-success); }
.progress-text { font-size: 0.6875rem; color: var(--color-text-placeholder); white-space: nowrap; }

.status-tag { font-size: 0.6875rem; padding: 2px 8px; border-radius: 8px; }
.tag-done { background: #dcfce7; color: #166534; }
.tag-expired { background: #fef2f2; color: #991b1b; }

.btn-archive {
  padding: 0 16px; border: none; border-left: 1px solid var(--color-border);
  background: none; color: var(--color-text-placeholder); font-size: 0.75rem;
  cursor: pointer; font-family: var(--font-family); border-radius: 0 var(--radius) var(--radius) 0;
}
.btn-archive:hover { color: var(--color-error); background: var(--color-error-bg); }

.btn-delete-archive {
  padding: 10px 16px; border: none; border-left: 1px solid var(--color-border);
  background: none; color: var(--color-error); font-size: 0.8125rem;
  cursor: pointer; font-family: var(--font-family);
  border-radius: 0 var(--radius) var(--radius) 0;
  white-space: nowrap;
}
.btn-delete-archive:hover { background: var(--color-error-bg); }

.plan-card-archived.plan-card-archived { display: flex; }
.plan-card-archived .plan-main { flex: 1; }

.empty-state {
  display: flex; flex-direction: column; align-items: center;
  padding: 80px 20px; text-align: center;
}
.empty-icon { font-size: 3rem; margin-bottom: 16px; }
.empty-title { font-size: 1.125rem; font-weight: 600; color: var(--color-text); margin-bottom: 8px; }
.empty-hint { font-size: 0.875rem; color: var(--color-text-secondary); margin-bottom: 24px; }
.btn-generate {
  padding: 14px 32px; border: none; border-radius: var(--radius-sm);
  background: var(--color-primary); color: #fff;
  font-size: 1rem; font-weight: 600; cursor: pointer; font-family: var(--font-family);
}

.loading-state { display: flex; align-items: center; justify-content: center; padding: 80px; }
.spinner {
  width: 28px; height: 28px;
  border: 3px solid var(--color-border); border-top-color: var(--color-primary);
  border-radius: 50%; animation: spin 0.6s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
