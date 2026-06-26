<template>
  <div class="page">
    <header class="page-header">
      <h2>📚 我的作业</h2>
      <span class="count-badge" v-if="store.items.length">{{ store.items.length }} 条</span>
    </header>

    <!-- 作业列表 -->
    <div class="list-section" v-if="store.items.length">
      <div class="homework-card" v-for="hw in store.items" :key="hw.id">
        <div class="hw-main">
          <span class="hw-subject">{{ subjectIcon(hw.subject) }} {{ hw.subject }}</span>
          <span class="hw-divider">|</span>
          <span class="hw-type">{{ hw.task_type }}</span>
          <span class="hw-amount">{{ formatAmount(hw.total_amount) }}{{ hw.unit }}</span>
        </div>
        <div class="hw-meta">
          <span class="hw-time" v-if="hw.time_per_unit">{{ hw.time_per_unit }} 分/{{ hw.unit }}</span>
          <span class="hw-notes" v-if="hw.notes">{{ hw.notes }}</span>
        </div>
        <button class="btn-delete" @click="handleDelete(hw.id)" :disabled="deleting === hw.id">
          {{ deleting === hw.id ? '...' : '✕' }}
        </button>
      </div>
    </div>
    <div class="empty-state" v-else-if="!store.loading && !showForm">
      <span class="empty-icon">📝</span>
      <p>还没有录入作业</p>
      <p class="hint">点击下方按钮开始录入</p>
    </div>

    <!-- 手动录入表单 -->
    <div class="form-section" v-if="showForm">
      <h3>录入新作业</h3>
      <div class="form-row">
        <div class="form-group half">
          <label>科目</label>
          <select v-model="form.subject">
            <option value="">选择科目</option>
            <option v-for="s in subjects" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
        <div class="form-group half">
          <label>类型</label>
          <input type="text" v-model="form.taskType" placeholder="如：练习册、模拟卷" />
        </div>
      </div>
      <div class="form-row">
        <div class="form-group half">
          <label>总量</label>
          <input type="number" v-model.number="form.totalAmount" min="0.01" step="0.01" placeholder="数量" />
        </div>
        <div class="form-group half">
          <label>单位</label>
          <input type="text" v-model="form.unit" placeholder="如：张、页、套" />
        </div>
      </div>
      <div class="form-group">
        <label>耗时/单位（分钟，不填默认 60 分钟=1 小时）</label>
        <input type="number" v-model.number="form.timePerUnit" min="1" placeholder="默认 60 分钟" />
      </div>
      <div class="form-group">
        <label>备注（选填）</label>
        <input type="text" v-model="form.notes" maxlength="200" placeholder="如：五年高考三年模拟" />
      </div>
      <div class="form-actions">
        <button class="btn-secondary" @click="showForm = false">取消</button>
        <button class="btn-primary" @click="handleAddAndContinue" :disabled="!canAdd">继续添加</button>
        <button class="btn-primary" @click="handleAddAndClose" :disabled="!canAdd">保存</button>
      </div>
      <div class="error-msg" v-if="store.error">{{ store.error }}</div>
    </div>

    <!-- 添加按钮 -->
    <button class="btn-fab" v-if="!showForm" @click="showForm = true">+ 添加作业</button>

    <!-- AI 解析区 -->
    <details class="ai-section">
      <summary>🤖 AI 智能录入</summary>
      <div class="ai-input-wrap">
        <textarea
          v-model="aiText"
          placeholder="用自然语言描述你的作业，例如：&#10;数学模拟卷3套，英语阅读理解20篇，语文读后感2篇"
          rows="3"
          maxlength="2000"
        ></textarea>
        <button class="btn-primary btn-ai" @click="handleAiParse" :disabled="aiParsing">
          {{ aiParsing ? '解析中...' : '🔮 AI 解析' }}
        </button>
      </div>
      <div class="ai-results" v-if="aiTasks.length">
        <div class="ai-task" v-for="(task, i) in aiTasks" :key="i">
          <span>✅ {{ task.subject }} | {{ task.type }} | {{ task.totalAmount }}{{ task.unit }}</span>
          <span class="ai-task-time" v-if="task.timePerUnit">（{{ task.totalAmount * task.timePerUnit }} 分）</span>
        </div>
        <button class="btn-primary" @click="handleAiConfirm" :disabled="aiConfirming">
          {{ aiConfirming ? '确认中...' : '全部确认' }}
        </button>
      </div>
      <div class="error-msg" v-if="aiError">{{ aiError }}</div>
    </details>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useHomeworkStore } from '../stores/homework'
import { aiApi, type AiTask } from '../api'

const store = useHomeworkStore()
const showForm = ref(false)
const deleting = ref<number | null>(null)

// 预设数据
const subjects = ['数学', '英语', '语文', '物理', '化学', '生物', '历史', '地理', '政治']

// 表单
const form = ref({
  subject: '',
  taskType: '有效作业',
  totalAmount: 1 as number | null,
  unit: '张',
  timePerUnit: null as number | null,
  notes: '',
})

const canAdd = computed(() => {
  return form.value.subject && form.value.taskType.trim() && form.value.totalAmount && form.value.totalAmount > 0 && form.value.unit.trim()
})

// AI
const aiText = ref('')
const aiTasks = ref<AiTask[]>([])
const aiParsing = ref(false)
const aiConfirming = ref(false)
const aiError = ref('')

onMounted(() => {
  store.fetchAll()
})

function subjectIcon(subject: string): string {
  const icons: Record<string, string> = {
    '数学': '📐', '英语': '🇬🇧', '语文': '📖', '物理': '⚛️',
    '化学': '🧪', '生物': '🧬', '历史': '📜', '地理': '🌍', '政治': '⚖️',
  }
  return icons[subject] || '📝'
}

function formatAmount(n: number): string {
  // 如果是整数就显示整数
  return Number.isInteger(n) ? n.toString() : n.toFixed(2).replace(/\.?0+$/, '')
}

async function handleAddAndContinue() {
  if (!form.value.totalAmount) return
  const ok = await store.create({
    subject: form.value.subject,
    type: form.value.taskType,
    totalAmount: form.value.totalAmount,
    unit: form.value.unit,
    timePerUnit: form.value.timePerUnit || 60,
    notes: form.value.notes,
  })
  if (ok) {
    form.value.totalAmount = 1
    form.value.unit = '张'
    form.value.timePerUnit = null
    form.value.notes = ''
  }
}

async function handleAddAndClose() {
  if (!form.value.totalAmount) return
  const ok = await store.create({
    subject: form.value.subject,
    type: form.value.taskType,
    totalAmount: form.value.totalAmount,
    unit: form.value.unit,
    timePerUnit: form.value.timePerUnit || 60,
    notes: form.value.notes,
  })
  if (ok) {
    showForm.value = false
    resetForm()
  }
}

function resetForm() {
  form.value = { subject: '', taskType: '有效作业', totalAmount: 1, unit: '张', timePerUnit: null, notes: '' }
}

async function handleDelete(id: number) {
  if (!confirm('确定删除这条作业？')) return
  deleting.value = id
  await store.remove(id)
  deleting.value = null
}

async function handleAiParse() {
  if (!aiText.value.trim()) return
  aiParsing.value = true
  aiError.value = ''
  aiTasks.value = []

  const res = await aiApi.parseHomework(aiText.value.trim())
  aiParsing.value = false

  if (res.ok) {
    aiTasks.value = res.data.tasks
  } else {
    aiError.value = res.error || 'AI 解析失败，请手动录入'
  }
}

async function handleAiConfirm() {
  aiConfirming.value = true
  let allOk = true
  for (const task of aiTasks.value) {
    const ok = await store.create({
      subject: task.subject,
      type: task.type,
      totalAmount: task.totalAmount,
      unit: task.unit,
      timePerUnit: task.timePerUnit || undefined,
      notes: task.notes,
    })
    if (!ok) allOk = false
  }
  aiConfirming.value = false
  if (allOk) {
    aiTasks.value = []
    aiText.value = ''
    aiError.value = ''
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
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}

.page-header h2 {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--color-text);
}

.count-badge {
  background: var(--color-primary);
  color: #fff;
  font-size: 0.75rem;
  padding: 2px 8px;
  border-radius: 10px;
  font-weight: 500;
}

.list-section {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 16px;
}

.homework-card {
  background: var(--color-card);
  border-radius: var(--radius-sm);
  padding: 14px 16px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.hw-main {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  font-size: 0.9375rem;
}

.hw-subject {
  font-weight: 600;
  color: var(--color-text);
}

.hw-divider {
  color: var(--color-border);
}

.hw-type {
  color: var(--color-text-secondary);
}

.hw-amount {
  font-weight: 600;
  color: var(--color-primary);
}

.hw-meta {
  display: flex;
  gap: 10px;
  font-size: 0.8125rem;
  color: var(--color-text-placeholder);
}

.btn-delete {
  position: absolute;
  right: 12px;
  top: 12px;
  background: none;
  border: none;
  font-size: 1rem;
  color: var(--color-text-placeholder);
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 4px;
}

.btn-delete:hover {
  color: var(--color-error);
  background: var(--color-error-bg);
}

.empty-state {
  text-align: center;
  padding: 48px 20px;
  color: var(--color-text-secondary);
}

.empty-icon {
  font-size: 2.5rem;
  display: block;
  margin-bottom: 12px;
}

.hint {
  font-size: 0.8125rem;
  color: var(--color-text-placeholder);
}

/* 表单 */
.form-section {
  background: var(--color-card);
  border-radius: var(--radius);
  padding: 20px;
  margin-bottom: 16px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.form-section h3 {
  font-size: 1rem;
  font-weight: 600;
  margin-bottom: 14px;
  color: var(--color-text);
}

.form-row {
  display: flex;
  gap: 10px;
  margin-bottom: 12px;
}

.form-group {
  display: flex;
  flex-direction: column;
  margin-bottom: 12px;
}

.form-group.half { flex: 1; }
.form-group.flex-2 { flex: 2; }
.form-group.flex-1 { flex: 1; }

.form-group label {
  font-size: 0.8125rem;
  color: var(--color-text-secondary);
  margin-bottom: 4px;
}

.form-group input,
.form-group select {
  width: 100%;
  box-sizing: border-box;
  padding: 10px 12px;
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-sm);
  font-size: 0.9375rem;
  font-family: var(--font-family);
  background: var(--color-input-bg);
  color: var(--color-text);
}

.form-group input:focus,
.form-group select:focus {
  outline: none;
  border-color: var(--color-border-focus);
}

.form-actions {
  display: flex;
  gap: 8px;
  margin-top: 8px;
}

.btn-secondary {
  padding: 10px 16px;
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-card);
  color: var(--color-text-secondary);
  font-size: 0.875rem;
  cursor: pointer;
  font-family: var(--font-family);
}

.btn-primary {
  padding: 10px 16px;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--color-primary);
  color: #fff;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  font-family: var(--font-family);
  flex: 1;
}

.btn-primary:disabled {
  background: var(--color-primary-disabled);
  cursor: not-allowed;
}

.btn-fab {
  display: block;
  width: 100%;
  padding: 14px;
  border: 2px dashed var(--color-border);
  border-radius: var(--radius);
  background: transparent;
  color: var(--color-primary);
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  font-family: var(--font-family);
  margin-bottom: 20px;
}

.btn-fab:hover {
  border-color: var(--color-primary);
  background: rgba(79, 70, 229, 0.04);
}

.error-msg {
  color: var(--color-error);
  font-size: 0.875rem;
  margin-top: 8px;
  text-align: center;
}

/* AI 区 */
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
  color: var(--color-text);
  padding: 4px 0;
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
  color: var(--color-text);
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
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ai-task {
  padding: 10px 12px;
  background: #F0FDF4;
  border-radius: var(--radius-sm);
  font-size: 0.875rem;
  color: #166534;
}

.ai-task-time {
  color: var(--color-text-placeholder);
  font-size: 0.8125rem;
}
</style>
