<template>
  <div class="page">
    <header class="page-header">
      <button class="btn-back" @click="handleBack">← {{ step === 1 ? '返回' : '上一步' }}</button>
      <h2>{{ stepTitle }}</h2>
    </header>

    <!-- Step 1: 基本信息 -->
    <template v-if="step === 1">
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
        <div class="form-actions">
          <button class="btn-primary btn-press" :disabled="!step1Valid" @click="step1Next">
            下一步：录入作业 →
          </button>
        </div>
      </section>
    </template>

    <!-- Step 2: 录入作业 -->
    <template v-if="step === 2">
      <!-- 手动录入 -->
      <section class="form-card">
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
            <input type="text" v-model="form.taskType" placeholder="模拟卷" />
          </div>
        </div>
        <div class="form-row">
          <div class="form-group half">
            <label>总量</label>
            <input type="number" v-model.number="form.totalAmount" min="0.01" step="0.01" placeholder="数量" />
          </div>
          <div class="form-group half">
            <label>单位</label>
            <input type="text" v-model="form.unit" placeholder="张/套/篇" />
          </div>
        </div>
        <div class="form-group">
          <label>耗时/单位（分钟，选填）</label>
          <input type="number" v-model.number="form.timePerUnit" min="1" placeholder="默认 60" />
        </div>
        <div class="form-group">
          <label>备注（选填）</label>
          <input type="text" v-model="form.notes" maxlength="200" placeholder="如：五年高考三年模拟" />
        </div>
        <div class="form-actions">
          <button class="btn-outline btn-press" @click="handleAddAndContinue" :disabled="!formValid || submitting">继续添加</button>
          <button class="btn-primary btn-press" @click="handleAddAndClose" :disabled="!formValid || submitting">确定</button>
        </div>
      </section>

      <!-- 已录入的作业 -->
      <section class="hw-list" v-if="homeworkItems.length">
        <h3>已录入 {{ homeworkItems.length }} 条</h3>
        <div class="hw-item" v-for="hw in homeworkItems" :key="hw.id">
          <span class="hw-info">{{ hw.subject }} · {{ hw.task_type }} · {{ hw.total_amount }}{{ hw.unit }}</span>
          <button class="btn-del" @click="removeHomework(hw.id)">✕</button>
        </div>
      </section>

      <!-- AI 录入 -->
      <details class="ai-section">
        <summary>🤖 AI 智能录入</summary>
        <textarea v-model="aiText" placeholder="用自然语言描述作业…" rows="3" maxlength="2000"></textarea>
        <button class="btn-primary btn-ai btn-press" @click="handleAiParse" :disabled="aiParsing">
          {{ aiParsing ? '解析中...' : '🔮 AI 解析' }}
        </button>
        <div class="ai-results" v-if="aiTasks.length">
          <div class="ai-task" v-for="(task, i) in aiTasks" :key="i">
            ✅ {{ task.subject }} | {{ task.type }} | {{ task.totalAmount }}{{ task.unit }}
          </div>
          <button class="btn-primary btn-press" @click="handleAiConfirm" :disabled="aiConfirming">
            {{ aiConfirming ? '确认中...' : '全部确认' }}
          </button>
        </div>
        <div class="error-msg" v-if="aiError">{{ aiError }}</div>
      </details>

      <!-- 生成按钮 -->
      <button
        class="btn-generate btn-press"
        :class="{ 'btn-generating': generating }"
        :disabled="homeworkItems.length === 0 || generating"
        @click="handleGenerate"
      >
        {{ generating ? '生成中...' : '🎯 生成每日计划' }}
      </button>
    </template>

    <!-- 错误 -->
    <div class="error-msg" v-if="errorMsg">{{ errorMsg }}</div>

    <!-- 结果 -->
    <div class="result-card" v-if="result">
      <h3>✅ 计划已生成！</h3>
      <p>计划：{{ result.name }}</p>
      <p>可用 {{ result.availableDays }} 天，共 {{ result.totalWorkMinutes }} 分钟</p>
      <button class="btn-primary btn-press" @click="router.push('/plan/' + result.planId)">查看计划详情 →</button>
      <button class="btn-outline btn-press" @click="router.push('/plans')">返回计划列表</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { planApi, aiApi, homeworkApi, type AiTask, type HomeworkItem, type PlanGenerateResult } from '../api'

const router = useRouter()
const subjects = ['数学', '英语', '语文', '物理', '化学', '生物', '历史', '地理', '政治']

// Step
const step = ref(1)
const stepTitle = computed(() => ['', '创建新计划', '录入作业'][step.value])

// Step 1
const planName = ref('暑假作业计划')
const startDate = ref('')
const endDate = ref('')

const createdPlanId = ref(0)

const step1Valid = computed(() => startDate.value && endDate.value)

// Step 2
const form = ref({ subject: '', taskType: '有效作业', totalAmount: 1 as number | null, unit: '张', timePerUnit: null as number | null, notes: '' })
const formValid = computed(() => form.value.subject && form.value.taskType.trim() && form.value.totalAmount && form.value.totalAmount > 0 && form.value.unit.trim())
const homeworkItems = ref<HomeworkItem[]>([])
const submitting = ref(false)
const generating = ref(false)
const errorMsg = ref('')
const result = ref<PlanGenerateResult | null>(null)

// AI
const aiText = ref('')
const aiTasks = ref<AiTask[]>([])
const aiParsing = ref(false)
const aiConfirming = ref(false)
const aiError = ref('')

onMounted(() => {
  const today = new Date()
  const end = new Date()
  end.setMonth(end.getMonth() + 2)
  startDate.value = today.toISOString().slice(0, 10)
  endDate.value = end.toISOString().slice(0, 10)
})

function handleBack() {
  if (step.value === 2) {
    step.value = 1
  } else {
    router.push('/plans')
  }
}

async function step1Next() {
  // 先创建计划
  const res = await planApi.create({
    name: planName.value,
    startDate: startDate.value,
    endDate: endDate.value,
  })
  if (res.ok) {
    createdPlanId.value = res.data.id
    planName.value = res.data.name
    step.value = 2
  } else {
    errorMsg.value = res.error || '创建计划失败'
  }
}

async function loadHomework() {
  if (createdPlanId.value) {
    const res = await homeworkApi.list(createdPlanId.value)
    if (res.ok) homeworkItems.value = res.data.homework
  }
}

async function addHomework(input: { subject: string; type: string; totalAmount: number; unit: string; timePerUnit?: number | null; notes?: string }) {
  if (!createdPlanId.value) return false
  const res = await homeworkApi.create({
    planId: createdPlanId.value,
    ...input,
  })
  if (res.ok) {
    await loadHomework()
    return true
  }
  errorMsg.value = res.error || '添加失败'
  return false
}

async function handleAddAndContinue() {
  if (!formValid.value || submitting.value) return
  submitting.value = true
  const ok = await addHomework({
    subject: form.value.subject,
    type: form.value.taskType,
    totalAmount: form.value.totalAmount ?? 1,
    unit: form.value.unit,
    timePerUnit: form.value.timePerUnit || 60,
    notes: form.value.notes,
  })
  submitting.value = false
  if (ok) {
    form.value = { subject: '', taskType: '有效作业', totalAmount: 1, unit: '张', timePerUnit: null, notes: '' }
  }
}

async function handleAddAndClose() {
  if (!formValid.value || submitting.value) return
  submitting.value = true
  await addHomework({
    subject: form.value.subject,
    type: form.value.taskType,
    totalAmount: form.value.totalAmount ?? 1,
    unit: form.value.unit,
    timePerUnit: form.value.timePerUnit || 60,
    notes: form.value.notes,
  })
  submitting.value = false
}

async function removeHomework(id: number) {
  await homeworkApi.remove(id)
  await loadHomework()
}

async function handleGenerate() {
  generating.value = true
  errorMsg.value = ''
  result.value = null

  const res = await planApi.generate({
    planId: createdPlanId.value,
    startDate: startDate.value,
    endDate: endDate.value,
  })

  generating.value = false
  if (res.ok) {
    result.value = res.data
  } else {
    errorMsg.value = res.error || '生成失败'
  }
}

async function handleAiParse() {
  if (!aiText.value.trim()) return
  aiParsing.value = true; aiError.value = ''; aiTasks.value = []
  const res = await aiApi.parseHomework(aiText.value.trim())
  aiParsing.value = false
  if (res.ok) aiTasks.value = res.data.tasks
  else aiError.value = res.error || 'AI 解析失败'
}

async function handleAiConfirm() {
  if (aiConfirming.value || submitting.value) return
  aiConfirming.value = true; submitting.value = true; let allOk = true
  for (const task of aiTasks.value) {
    const ok = await addHomework({
      subject: task.subject, type: task.type, totalAmount: task.totalAmount,
      unit: task.unit, timePerUnit: task.timePerUnit || undefined, notes: task.notes,
    })
    if (!ok) allOk = false
  }
  aiConfirming.value = false; submitting.value = false
  if (allOk) { aiTasks.value = []; aiText.value = ''; aiError.value = '' }
}
</script>

<style scoped>
.page { padding: 16px; padding-bottom: 100px; max-width: 600px; margin: 0 auto; }

.page-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.page-header h2 { font-size: 1.125rem; font-weight: 700; color: var(--color-text); }

.btn-back {
  padding: 6px 12px; border: 1.5px solid var(--color-border); border-radius: var(--radius-sm);
  background: var(--color-card); color: var(--color-text-secondary); font-size: 0.875rem;
  cursor: pointer; font-family: var(--font-family); white-space: nowrap;
}

.form-card { background: var(--color-card); border-radius: var(--radius); padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.form-group { display: flex; flex-direction: column; margin-bottom: 10px; }
.form-group label { font-size: 0.8125rem; color: var(--color-text-secondary); margin-bottom: 4px; }
.form-group input, .form-group select {
  width: 100%; box-sizing: border-box;
  padding: 10px 12px; border: 1.5px solid var(--color-border); border-radius: var(--radius-sm);
  font-size: 0.9375rem; font-family: var(--font-family); background: var(--color-input-bg); color: var(--color-text);
}
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--color-border-focus); }
.form-row { display: flex; gap: 10px; }
.half { flex: 1; min-width: 0; }
.flex-1 { flex: 1; min-width: 0; }
.form-actions { display: flex; gap: 8px; margin-top: 12px; }

.btn-primary {
  padding: 10px 16px; border: none; border-radius: var(--radius-sm); background: var(--color-primary);
  color: #fff; font-size: 0.875rem; font-weight: 600; cursor: pointer; font-family: var(--font-family); flex: 1;
}
.btn-primary:disabled { background: var(--color-primary-disabled); cursor: not-allowed; }
.btn-outline {
  padding: 10px 16px; border: 1.5px solid var(--color-primary); border-radius: var(--radius-sm);
  background: transparent; color: var(--color-primary); font-size: 0.875rem; font-weight: 600;
  cursor: pointer; font-family: var(--font-family); flex: 1;
}

/* 已录入作业 */
.hw-list { margin-bottom: 16px; }
.hw-list h3 { font-size: 0.9375rem; font-weight: 600; margin-bottom: 8px; color: var(--color-text); }
.hw-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px; background: var(--color-card); border-radius: var(--radius-sm);
  margin-bottom: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}
.hw-info { font-size: 0.875rem; color: var(--color-text); }
.btn-del {
  background: none; border: none; color: var(--color-text-placeholder);
  font-size: 1rem; cursor: pointer; padding: 4px;
}
.btn-del:hover { color: var(--color-error); }

/* AI */
.ai-section { background: var(--color-card); border-radius: var(--radius); padding: 16px 20px; margin-bottom: 16px; }
.ai-section summary { font-size: 1rem; font-weight: 600; cursor: pointer; color: var(--color-text); padding: 4px 0; }
.ai-section textarea {
  width: 100%; padding: 12px; border: 1.5px solid var(--color-border); border-radius: var(--radius-sm);
  font-size: 0.9375rem; font-family: var(--font-family); resize: vertical;
  background: var(--color-input-bg); color: var(--color-text); box-sizing: border-box; margin-top: 12px;
}
.ai-section textarea:focus { outline: none; border-color: var(--color-border-focus); }
.btn-ai { width: 100%; margin-top: 10px; }
.ai-results { margin-top: 14px; display: flex; flex-direction: column; gap: 8px; }
.ai-task { padding: 10px 12px; background: #F0FDF4; border-radius: var(--radius-sm); font-size: 0.875rem; color: #166534; }

.btn-generate {
  display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 16px;
  border: none; border-radius: var(--radius); background: var(--color-primary); color: #fff;
  font-size: 1.125rem; font-weight: 700; cursor: pointer; font-family: var(--font-family); margin-bottom: 16px;
}
.btn-generate:disabled { background: var(--color-primary-disabled); cursor: not-allowed; }
.btn-generating { animation: pulseGlow 1.5s ease-in-out infinite; }

.error-msg { color: var(--color-error); font-size: 0.875rem; text-align: center; margin-bottom: 16px; padding: 12px; background: var(--color-error-bg); border-radius: var(--radius-sm); }

.result-card { background: var(--color-card); border-radius: var(--radius); padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.result-card h3 { font-size: 1rem; font-weight: 600; margin-bottom: 10px; color: var(--color-text); }
.result-card p { font-size: 0.875rem; color: var(--color-text-secondary); margin-bottom: 6px; }
</style>
