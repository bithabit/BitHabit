<template>
  <div class="page">
    <header class="page-header">
      <h2>📅 日程配置</h2>
    </header>

    <!-- 每周固定 -->
    <section class="schedule-section">
      <h3>每周固定</h3>
      <div class="item-list" v-if="store.data.weekly.length">
        <div class="schedule-item" v-for="w in store.data.weekly" :key="'w' + w.id">
          <div class="item-info">
            <span class="item-day">{{ dayNames[w.day_of_week] }}</span>
            <span v-if="w.start_time && w.end_time">{{ w.start_time.slice(0, 5) }} - {{ w.end_time.slice(0, 5) }}</span>
            <span v-else class="all-day">全天</span>
            <span class="item-label" v-if="w.label">{{ w.label }}</span>
          </div>
          <button class="btn-del btn-press" @click="handleDelete(w.id, 'weekly')">✕</button>
        </div>
      </div>
      <div class="empty-hint" v-else>暂无每周固定时段</div>

      <details class="add-form-wrap">
        <summary>+ 添加时段</summary>
        <div class="inline-form">
          <div class="form-row">
            <label>星期</label>
            <select v-model="weeklyForm.dayOfWeek">
              <option v-for="(name, i) in dayNames" :key="i" :value="i">{{ name }}</option>
            </select>
          </div>
          <div class="form-row checkbox-row">
            <label><input type="checkbox" v-model="weeklyForm.allDay" /> 全天</label>
          </div>
          <div class="form-row" v-if="!weeklyForm.allDay">
            <label>开始</label>
            <input type="time" v-model="weeklyForm.startTime" />
          </div>
          <div class="form-row" v-if="!weeklyForm.allDay">
            <label>结束</label>
            <input type="time" v-model="weeklyForm.endTime" />
          </div>
          <div class="form-row">
            <label>标签</label>
            <input type="text" v-model="weeklyForm.label" placeholder="如：补习班" maxlength="20" />
          </div>
          <button class="btn-primary btn-sm btn-press" @click="handleAddWeekly">添加</button>
        </div>
      </details>
    </section>

    <!-- 特殊日期 -->
    <section class="schedule-section">
      <h3>🗓️ 特殊日期</h3>
      <div class="item-list" v-if="store.data.special.length">
        <div class="schedule-item" v-for="s in store.data.special" :key="'s' + s.id">
          <div class="item-info">
            <span class="item-day">{{ s.date_from }}{{ s.date_to && s.date_to !== s.date_from ? ' - ' + s.date_to : '' }}</span>
            <span v-if="s.start_time && s.end_time">{{ s.start_time.slice(0, 5) }} - {{ s.end_time.slice(0, 5) }}</span>
            <span v-else class="all-day">全天</span>
            <span class="item-label" v-if="s.label">{{ s.label }}</span>
          </div>
          <button class="btn-del btn-press" @click="handleDelete(s.id, 'special')">✕</button>
        </div>
      </div>
      <div class="empty-hint" v-else>暂无特殊日期</div>

      <details class="add-form-wrap">
        <summary>+ 添加日期</summary>
        <div class="inline-form">
          <div class="form-row">
            <label>日期从</label>
            <input type="date" v-model="specialForm.dateFrom" />
          </div>
          <div class="form-row">
            <label>到</label>
            <input type="date" v-model="specialForm.dateTo" />
          </div>
          <div class="form-row checkbox-row">
            <label><input type="checkbox" v-model="specialForm.allDay" /> 全天</label>
          </div>
          <div class="form-row" v-if="!specialForm.allDay">
            <label>开始</label>
            <input type="time" v-model="specialForm.startTime" />
          </div>
          <div class="form-row" v-if="!specialForm.allDay">
            <label>结束</label>
            <input type="time" v-model="specialForm.endTime" />
          </div>
          <div class="form-row">
            <label>标签</label>
            <input type="text" v-model="specialForm.label" placeholder="如：旅行" maxlength="20" />
          </div>
          <button class="btn-primary btn-sm btn-press" @click="handleAddSpecial">添加</button>
        </div>
      </details>
    </section>

    <!-- AI 解析 -->
    <details class="ai-section">
      <summary>🤖 AI 智能录入</summary>
      <div class="ai-input-wrap">
        <textarea
          v-model="aiText"
          placeholder="用自然语言描述你的日程，例如：&#10;我每周一三五早上8点到下午2点有补习班，周日全天休息。7月15到18号要去旅行。"
          rows="3"
          maxlength="2000"
        ></textarea>
        <button class="btn-primary btn-ai btn-press" @click="handleAiParse" :disabled="aiParsing">
          {{ aiParsing ? '解析中...' : '🔮 AI 解析' }}
        </button>
      </div>
      <div class="ai-results" v-if="aiResults">
        <div v-if="aiResults.weekly.length">
          <strong>每周固定：</strong>
          <div class="ai-item" v-for="(w, i) in aiResults.weekly" :key="'aw'+i">
            {{ dayNames[w.dayOfWeek] }} {{ w.startTime || '全天' }}{{ w.endTime ? ' - ' + w.endTime : '' }} {{ w.label }}
          </div>
        </div>
        <div v-if="aiResults.special.length">
          <strong>特殊日期：</strong>
          <div class="ai-item" v-for="(s, i) in aiResults.special" :key="'as'+i">
            {{ s.dateFrom }}{{ s.dateTo ? ' - ' + s.dateTo : '' }} {{ s.startTime || '全天' }}{{ s.endTime ? ' - ' + s.endTime : '' }} {{ s.label }}
          </div>
        </div>
        <button class="btn-primary btn-sm btn-press" @click="handleAiConfirm" :disabled="aiConfirming">
          {{ aiConfirming ? '确认中...' : '全部确认' }}
        </button>
      </div>
      <div class="error-msg" v-if="aiError">{{ aiError }}</div>
    </details>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useScheduleStore } from '../stores/schedule'
import { aiApi } from '../api'
import type { AiScheduleResult } from '../api'

const store = useScheduleStore()

const dayNames = ['周日', '周一', '周二', '周三', '周四', '周五', '周六']

const weeklyForm = reactive({
  dayOfWeek: 1,
  allDay: false,
  startTime: '08:00',
  endTime: '14:00',
  label: '',
})

const specialForm = reactive({
  dateFrom: '',
  dateTo: '',
  allDay: false,
  startTime: '08:00',
  endTime: '18:00',
  label: '',
})

// AI
const aiText = ref('')
const aiResults = ref<AiScheduleResult | null>(null)
const aiParsing = ref(false)
const aiConfirming = ref(false)
const aiError = ref('')

onMounted(() => {
  store.fetchAll()
})

async function handleAddWeekly() {
  const ok = await store.addWeekly({
    dayOfWeek: weeklyForm.dayOfWeek,
    startTime: weeklyForm.allDay ? null : weeklyForm.startTime + ':00',
    endTime: weeklyForm.allDay ? null : weeklyForm.endTime + ':00',
    label: weeklyForm.label,
  })
  if (ok) {
    weeklyForm.label = ''
  }
}

async function handleAddSpecial() {
  if (!specialForm.dateFrom) return
  const ok = await store.addSpecial({
    dateFrom: specialForm.dateFrom,
    dateTo: specialForm.dateTo || null,
    startTime: specialForm.allDay ? null : specialForm.startTime + ':00',
    endTime: specialForm.allDay ? null : specialForm.endTime + ':00',
    label: specialForm.label,
  })
  if (ok) {
    specialForm.label = ''
    specialForm.dateFrom = ''
    specialForm.dateTo = ''
  }
}

async function handleDelete(id: number, type: 'weekly' | 'special') {
  await store.remove(id, type)
}

async function handleAiParse() {
  if (!aiText.value.trim()) return
  aiParsing.value = true
  aiError.value = ''
  aiResults.value = null

  const res = await aiApi.parseSchedule(aiText.value.trim())
  aiParsing.value = false

  if (res.ok) {
    aiResults.value = res.data
  } else {
    aiError.value = res.error || 'AI 解析失败，请手动添加'
  }
}

async function handleAiConfirm() {
  if (!aiResults.value) return
  aiConfirming.value = true

  for (const w of aiResults.value.weekly) {
    await store.addWeekly({
      dayOfWeek: w.dayOfWeek,
      startTime: w.startTime,
      endTime: w.endTime,
      label: w.label,
    })
  }
  for (const s of aiResults.value.special) {
    await store.addSpecial({
      dateFrom: s.dateFrom,
      dateTo: s.dateTo,
      startTime: s.startTime,
      endTime: s.endTime,
      label: s.label,
    })
  }
  aiConfirming.value = false
  aiResults.value = null
  aiText.value = ''
  aiError.value = ''
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

.schedule-section {
  margin-bottom: 24px;
  background: var(--color-card);
  border-radius: var(--radius);
  padding: 16px 20px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.schedule-section h3 {
  font-size: 1rem;
  font-weight: 600;
  margin-bottom: 12px;
}

.item-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.schedule-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px;
  background: var(--color-input-bg);
  border-radius: var(--radius-sm);
}

.item-info {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 0.875rem;
}

.item-day {
  font-weight: 600;
  color: var(--color-text);
}

.all-day {
  color: var(--color-text-placeholder);
  font-size: 0.8125rem;
}

.item-label {
  color: var(--color-primary);
  font-size: 0.8125rem;
  background: rgba(79, 70, 229, 0.08);
  padding: 2px 8px;
  border-radius: 4px;
}

.btn-del {
  background: none;
  border: none;
  font-size: 1rem;
  color: var(--color-text-placeholder);
  cursor: pointer;
  padding: 2px 8px;
  border-radius: 4px;
}

.btn-del:hover {
  color: var(--color-error);
  background: var(--color-error-bg);
}

.empty-hint {
  color: var(--color-text-placeholder);
  font-size: 0.875rem;
  padding: 8px 0;
}

.add-form-wrap {
  margin-top: 12px;
}

.add-form-wrap summary {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-primary);
  cursor: pointer;
}

.inline-form {
  margin-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.form-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.form-row label {
  font-size: 0.875rem;
  color: var(--color-text-secondary);
  min-width: 48px;
}

.form-row input,
.form-row select {
  flex: 1;
  padding: 8px 10px;
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-sm);
  font-size: 0.875rem;
  font-family: var(--font-family);
  background: var(--color-input-bg);
  color: var(--color-text);
}

.form-row input:focus,
.form-row select:focus {
  outline: none;
  border-color: var(--color-border-focus);
}

.checkbox-row {
  min-width: auto;
}

.checkbox-row input[type="checkbox"] {
  flex: none;
  width: auto;
  margin-right: 4px;
}

.btn-sm {
  padding: 8px 16px;
  font-size: 0.875rem;
  width: 100%;
}

.btn-primary {
  padding: 10px 16px;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--color-primary);
  color: #fff;
  font-size: 0.9375rem;
  font-weight: 600;
  cursor: pointer;
  font-family: var(--font-family);
}

.btn-primary:disabled {
  background: var(--color-primary-disabled);
  cursor: not-allowed;
}

/* AI */
.ai-section {
  background: var(--color-card);
  border-radius: var(--radius);
  padding: 16px 20px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.ai-section summary {
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
}

.ai-input-wrap {
  margin-top: 12px;
}

.ai-input-wrap textarea {
  width: 100%;
  padding: 12px;
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-sm);
  font-size: 0.9375rem;
  font-family: var(--font-family);
  resize: vertical;
  background: var(--color-input-bg);
}

.ai-input-wrap textarea:focus {
  outline: none;
  border-color: var(--color-border-focus);
}

.btn-ai {
  width: 100%;
  margin-top: 10px;
}

.ai-results {
  margin-top: 14px;
}

.ai-item {
  padding: 6px 10px;
  background: #F0FDF4;
  border-radius: 4px;
  font-size: 0.8125rem;
  color: #166534;
  margin: 4px 0;
}

.error-msg {
  color: var(--color-error);
  font-size: 0.875rem;
  margin-top: 8px;
  text-align: center;
}

/* 🎬 日程项入场 */
.schedule-item {
  animation: fadeInUp var(--duration-slow) ease both;
}

.schedule-item:nth-child(1) { animation-delay: 0s; }
.schedule-item:nth-child(2) { animation-delay: 0.06s; }
.schedule-item:nth-child(3) { animation-delay: 0.12s; }
.schedule-item:nth-child(4) { animation-delay: 0.18s; }
.schedule-item:nth-child(5) { animation-delay: 0.24s; }

/* 🎬 AI 结果项 */
.ai-item {
  animation: fadeInUp 0.3s ease both;
}

.ai-item:nth-child(1) { animation-delay: 0s; }
.ai-item:nth-child(2) { animation-delay: 0.05s; }
.ai-item:nth-child(3) { animation-delay: 0.1s; }
</style>
