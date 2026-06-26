<template>
  <div class="page">
    <header class="page-header">
      <h2>🎯 生成作业计划</h2>
    </header>

    <!-- 计划参数 -->
    <section class="form-card">
      <div class="form-group">
        <label>计划名称</label>
        <input type="text" v-model="planName" maxlength="50" placeholder="暑假作业计划" />
      </div>
      <div class="form-row">
        <div class="form-group flex-1">
          <label>开始日期</label>
          <input type="date" v-model="startDate" />
        </div>
        <div class="form-group flex-1">
          <label>结束日期</label>
          <input type="date" v-model="endDate" />
        </div>
      </div>
      <div class="form-row">
        <div class="form-group flex-1">
          <label>每日开始</label>
          <input type="time" v-model="dailyStartTime" />
        </div>
        <div class="form-group flex-1">
          <label>每日结束</label>
          <input type="time" v-model="dailyEndTime" />
        </div>
      </div>
    </section>

    <!-- 作业摘要 -->
    <section class="summary-card">
      <h3>📋 当前作业</h3>
      <div v-if="homeworkStore.items.length">
        <div class="summary-item" v-for="hw in homeworkStore.items" :key="hw.id">
          <span>{{ hw.subject }} · {{ hw.task_type }}</span>
          <span class="summary-val">{{ formatAmount(hw.total_amount) }}{{ hw.unit }}{{ hw.time_per_unit ? ` (${hw.total_amount * hw.time_per_unit} 分)` : '' }}</span>
        </div>
        <div class="summary-total">
          ⚠️ 共 {{ homeworkStore.items.length }} 条，总计约 {{ totalWorkMinutes }} 分钟
        </div>
      </div>
      <div class="empty-hint" v-else>
        还没有作业，请先去「作业」页面录入
      </div>
    </section>

    <!-- 日程摘要 -->
    <section class="summary-card">
      <h3>📅 日程配置</h3>
      <div v-if="scheduleStore.data.weekly.length || scheduleStore.data.special.length">
        <div class="summary-item" v-for="w in scheduleStore.data.weekly" :key="'w'+w.id">
          <span>{{ dayNames[w.day_of_week] }}</span>
          <span class="summary-val">{{ w.start_time || '全天' }}{{ w.end_time ? ' - ' + w.end_time.slice(0,5) : '' }} {{ w.label }}</span>
        </div>
        <div class="summary-item" v-for="s in scheduleStore.data.special" :key="'s'+s.id">
          <span>{{ s.date_from }}{{ s.date_to && s.date_to !== s.date_from ? ' - ' + s.date_to : '' }}</span>
          <span class="summary-val">{{ s.start_time || '全天' }}{{ s.end_time ? ' - ' + s.end_time.slice(0,5) : '' }} {{ s.label }}</span>
        </div>
      </div>
      <div class="empty-hint" v-else>
        暂无日程配置（不配置也可以生成计划）
      </div>
    </section>

    <!-- 预览 -->
    <section class="preview-card" v-if="preview">
      <h3>📊 预览</h3>
      <div class="preview-grid">
        <div class="preview-item">
          <span class="preview-val">{{ preview.availableDays }}</span>
          <span class="preview-label">可用天数</span>
        </div>
        <div class="preview-item">
          <span class="preview-val">{{ totalWorkMinutes }}</span>
          <span class="preview-label">总工时(分)</span>
        </div>
        <div class="preview-item">
          <span class="preview-val">{{ Math.round(totalWorkMinutes / Math.max(preview.availableDays, 1)) }}</span>
          <span class="preview-label">日均(分)</span>
        </div>
      </div>
    </section>

    <!-- 生成按钮 -->
    <button
      class="btn-generate"
      :disabled="!canGenerate || generating"
      @click="handleGenerate"
    >
      <span v-if="generating" class="spinner"></span>
      {{ generating ? '生成中...' : '🎯 生成计划' }}
    </button>

    <!-- 错误 -->
    <div class="error-msg" v-if="errorMsg">{{ errorMsg }}</div>

    <!-- 结果 -->
    <div class="result-card" v-if="result">
      <h3>✅ 计划已生成！</h3>
      <p>计划名称：{{ result.name }}</p>
      <p>日期范围：{{ result.startDate }} ~ {{ result.endDate }}</p>
      <p>可用天数：{{ result.availableDays }} 天</p>
      <p>总工时：{{ result.totalWorkMinutes }} 分钟</p>
      <button class="btn-primary" @click="viewPlan">查看计划详情 →</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useHomeworkStore } from '../stores/homework'
import { useScheduleStore } from '../stores/schedule'
import { planApi, type PlanGenerateResult } from '../api'

const router = useRouter()
const homeworkStore = useHomeworkStore()
const scheduleStore = useScheduleStore()

const dayNames = ['周日', '周一', '周二', '周三', '周四', '周五', '周六']

const planName = ref('暑假作业计划')
const startDate = ref('2026-07-01')
const endDate = ref('2026-08-30')
const dailyStartTime = ref('08:00')
const dailyEndTime = ref('22:00')

const generating = ref(false)
const errorMsg = ref('')
const result = ref<PlanGenerateResult | null>(null)
const preview = ref<{ availableDays: number } | null>(null)

const totalWorkMinutes = computed(() => {
  return homeworkStore.items.reduce((sum, hw) => {
    return sum + (hw.time_per_unit ? hw.total_amount * hw.time_per_unit : 0)
  }, 0)
})

const canGenerate = computed(() => {
  return homeworkStore.items.length > 0 && startDate.value && endDate.value
})

onMounted(async () => {
  await Promise.all([homeworkStore.fetchAll(), scheduleStore.fetchAll()])
})

function formatAmount(n: number): string {
  return Number.isInteger(n) ? n.toString() : n.toFixed(2).replace(/\.?0+$/, '')
}

async function handleGenerate() {
  generating.value = true
  errorMsg.value = ''
  result.value = null

  const res = await planApi.generate({
    name: planName.value,
    startDate: startDate.value,
    endDate: endDate.value,
    dailyStartTime: dailyStartTime.value + ':00',
    dailyEndTime: dailyEndTime.value + ':00',
    strategy: 'average',
  })

  generating.value = false

  if (res.ok) {
    result.value = res.data
  } else {
    errorMsg.value = res.error || '生成失败'
  }
}

function viewPlan() {
  if (result.value) {
    router.push(`/plan/${result.value.planId}`)
  }
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
  margin-bottom: 16px;
}

.page-header h2 {
  font-size: 1.25rem;
  font-weight: 700;
}

.form-card, .summary-card, .preview-card, .result-card {
  background: var(--color-card);
  border-radius: var(--radius);
  padding: 16px 20px;
  margin-bottom: 16px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

h3 {
  font-size: 0.9375rem;
  font-weight: 600;
  margin-bottom: 10px;
  color: var(--color-text);
}

.form-group {
  display: flex;
  flex-direction: column;
  margin-bottom: 10px;
}

.form-group label {
  font-size: 0.8125rem;
  color: var(--color-text-secondary);
  margin-bottom: 4px;
}

.form-group input {
  padding: 10px 12px;
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-sm);
  font-size: 0.9375rem;
  font-family: var(--font-family);
  background: var(--color-input-bg);
  color: var(--color-text);
}

.form-group input:focus {
  outline: none;
  border-color: var(--color-border-focus);
}

.form-row {
  display: flex;
  gap: 10px;
}

.flex-1 { flex: 1; }

.summary-item {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  font-size: 0.875rem;
  border-bottom: 1px solid var(--color-border);
}

.summary-item:last-child {
  border-bottom: none;
}

.summary-val {
  color: var(--color-text-secondary);
}

.summary-total {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid var(--color-border);
  font-size: 0.8125rem;
  color: var(--color-primary);
  font-weight: 600;
}

.empty-hint {
  font-size: 0.8125rem;
  color: var(--color-text-placeholder);
  padding: 8px 0;
}

.preview-grid {
  display: flex;
  gap: 12px;
}

.preview-item {
  flex: 1;
  text-align: center;
  padding: 12px 8px;
  background: var(--color-input-bg);
  border-radius: var(--radius-sm);
}

.preview-val {
  display: block;
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--color-primary);
}

.preview-label {
  display: block;
  font-size: 0.75rem;
  color: var(--color-text-secondary);
  margin-top: 2px;
}

.btn-generate {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  padding: 16px;
  border: none;
  border-radius: var(--radius);
  background: var(--color-primary);
  color: #fff;
  font-size: 1.125rem;
  font-weight: 700;
  cursor: pointer;
  font-family: var(--font-family);
  margin-bottom: 16px;
}

.btn-generate:disabled {
  background: var(--color-primary-disabled);
  cursor: not-allowed;
}

.error-msg {
  color: var(--color-error);
  font-size: 0.875rem;
  text-align: center;
  margin-bottom: 16px;
  padding: 12px;
  background: var(--color-error-bg);
  border-radius: var(--radius-sm);
}

.result-card p {
  font-size: 0.875rem;
  color: var(--color-text-secondary);
  margin-bottom: 6px;
}

.btn-primary {
  display: block;
  width: 100%;
  margin-top: 12px;
  padding: 12px;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--color-primary);
  color: #fff;
  font-size: 0.9375rem;
  font-weight: 600;
  cursor: pointer;
  font-family: var(--font-family);
  text-align: center;
}

.spinner {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
