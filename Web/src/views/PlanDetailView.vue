<template>
  <div class="page" @click="closeMenu">
    <div class="menu-overlay" v-if="showMenu !== null" @click="closeMenu"></div>

    <!-- Header -->
    <header class="page-header">
      <button class="btn-back" @click="router.push('/plans')">← 返回</button>
      <h2>{{ plan?.name || '计划详情' }}</h2>
    </header>

    <div class="loading" v-if="loading">加载中...</div>
    <div class="error-msg" v-else-if="error">{{ error }}</div>

    <template v-else-if="plan">
      <!-- 摘要卡片 -->
      <section class="summary-card">
        <div class="summary-dates">{{ plan.start_date }} ~ {{ plan.end_date }}</div>
        <div class="summary-stats-row">
          <span>{{ homeworkItems.length }} 项作业</span>
          <span>·</span>
          <span>{{ totalTasks }} 个任务</span>
          <span>·</span>
          <span>{{ totalCompleted }}/{{ totalTasks }} 已完成</span>
        </div>
        <div class="plan-progress" v-if="totalTasks > 0">
          <div class="progress-bar-wrap">
            <div class="progress-fill" :style="{ width: overallPct + '%' }" :class="{ full: overallPct >= 100 }"></div>
          </div>
          <span class="progress-text">{{ overallPct }}%</span>
        </div>
      </section>

      <!-- 操作按钮 -->
      <div class="action-row">
        <router-link :to="`/plan/${planId}/calendar`" class="btn-action">📅 日历</router-link>
        <router-link :to="`/plan/${planId}/allocate`" class="btn-action">📐 分配策略</router-link>
      </div>

      <!-- 作业列表 -->
      <section class="hw-section">
        <h3>📚 作业列表</h3>

        <div class="hw-card" v-for="hw in homeworkItems" :key="hw.id" :class="{ 'is-editing': editingId === hw.id }">
          <div class="hw-main">
            <span class="hw-subject">{{ subjectIcon(hw.subject) }} {{ hw.subject }}</span>
            <span class="hw-divider">|</span>
            <span class="hw-type">{{ hw.task_type }}</span>
            <span class="hw-amount">{{ fmt(hw.total_amount) }}{{ hw.unit }}</span>
            <button class="btn-menu" @click.stop="toggleMenu(hw.id)" :class="{ active: showMenu === hw.id }">⋯</button>
          </div>
          <div class="hw-meta">
            <span v-if="hw.time_per_unit">{{ hw.time_per_unit }} 分/{{ hw.unit }}</span>
            <span class="hw-notes" v-if="hw.notes">{{ hw.notes }}</span>
          </div>
          <div class="hw-footer" v-if="totalTasks > 0">
            <div class="progress-bar-wrap">
              <div class="progress-fill" :style="{ width: hwPct(hw) + '%' }" :class="{ full: hwPct(hw) >= 100 }"></div>
            </div>
            <span class="progress-text">{{ hwPct(hw) }}% · {{ fmt(hw.completed_amount) }}/{{ fmt(hw.total_amount) }} {{ hw.unit }}</span>
          </div>
          <div class="hw-footer hw-no-tasks" v-else>
            <span class="hint">尚未生成每日任务，请先生成计划</span>
          </div>

          <!-- Context Menu -->
          <Transition name="menu-pop">
            <div class="context-menu" v-if="showMenu === hw.id" @click.stop>
              <button class="menu-item" @click="handleEdit(hw)"><span class="menu-icon">✏️</span> 编辑作业</button>
              <div class="menu-divider"></div>
              <button class="menu-item menu-item-danger" @click="handleDelete(hw.id)">
                <span class="menu-icon">🗑</span> 删除
              </button>
            </div>
          </Transition>

          <!-- Inline Edit Form -->
          <div class="inline-edit" v-if="editingId === hw.id">
            <div class="edit-row">
              <div class="edit-field half"><label>科目</label><select v-model="editForm.subject"><option v-for="s in subjects" :key="s" :value="s">{{ s }}</option></select></div>
              <div class="edit-field half"><label>类型</label><input type="text" v-model="editForm.taskType" /></div>
            </div>
            <div class="edit-row">
              <div class="edit-field half"><label>总量</label><input type="number" v-model.number="editForm.totalAmount" min="0.01" step="0.01" /></div>
              <div class="edit-field half"><label>单位</label><input type="text" v-model="editForm.unit" /></div>
            </div>
            <div class="edit-field"><label>耗时/单位（分钟）</label><input type="number" v-model.number="editForm.timePerUnit" min="1" /></div>
            <div class="edit-field"><label>备注</label><input type="text" v-model="editForm.notes" maxlength="200" /></div>
            <div class="edit-actions">
              <button class="btn-secondary" @click="cancelEdit">取消</button>
              <button class="btn-primary" @click="saveEdit(hw.id)" :disabled="!editValid">保存</button>
            </div>
          </div>
        </div>

        <!-- 添加作业按钮 -->
        <div class="add-hw" @click="showAddForm = !showAddForm">
          <span v-if="!showAddForm">+ 添加作业</span>
          <span v-else>✕ 收起</span>
        </div>

        <!-- 添加作业表单 -->
        <div class="add-form" v-if="showAddForm">
          <div class="form-row">
            <div class="form-group half"><label>科目</label><select v-model="addForm.subject"><option value="">选择</option><option v-for="s in subjects" :key="s" :value="s">{{ s }}</option></select></div>
            <div class="form-group half"><label>类型</label><input type="text" v-model="addForm.taskType" placeholder="模拟卷" /></div>
          </div>
          <div class="form-row">
            <div class="form-group half"><label>总量</label><input type="number" v-model.number="addForm.totalAmount" min="0.01" step="0.01" /></div>
            <div class="form-group half"><label>单位</label><input type="text" v-model="addForm.unit" placeholder="套" /></div>
          </div>
          <div class="form-group"><label>耗时/单位（分钟）</label><input type="number" v-model.number="addForm.timePerUnit" min="1" placeholder="默认 60" /></div>
          <div class="form-actions">
            <button class="btn-primary" @click="handleAddHw" :disabled="!addValid || submittingHw">添加</button>
          </div>
        </div>
      </section>

      <!-- 每日任务预览 -->
      <section class="days-section" v-if="plan.days && plan.days.length > 0">
        <h3>📅 每日任务（{{ plan.days.length }} 天）</h3>
        <details v-for="day in plan.days" :key="day.date" class="day-group" :open="day.date === today">
          <summary class="day-header">
            <span>{{ day.date }} {{ dayOfWeek(day.date) }}</span>
            <span class="day-count">{{ day.slots.length }} 项 · {{ dayMinutes(day) }} 分</span>
          </summary>
          <div class="task-list">
            <div class="task-item" v-for="slot in day.slots" :key="slot.id">
              <span class="task-check" :class="{ done: slot.completed }">{{ slot.completed ? '✅' : '⬜' }}</span>
              <span>{{ slot.subject }} · {{ slot.taskType }}</span>
              <span class="task-amount">{{ fmt(slot.amount) }}{{ slot.unit }}</span>
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
import { useRoute, useRouter } from 'vue-router'
import { planApi, homeworkApi, type PlanDetail, type HomeworkItem } from '../api'

const route = useRoute()
const router = useRouter()
const planId = computed(() => Number(route.params.id))

const plan = ref<PlanDetail | null>(null)
const homeworkItems = ref<HomeworkItem[]>([])
const loading = ref(true)
const error = ref('')

const subjects = ['数学', '英语', '语文', '物理', '化学', '生物', '历史', '地理', '政治']
const today = new Date().toISOString().slice(0, 10)

const totalTasks = computed(() => plan.value?.days.reduce((s, d) => s + d.slots.length, 0) ?? 0)
const totalCompleted = computed(() => plan.value?.days.reduce((s, d) => s + d.slots.filter(t => t.completed).length, 0) ?? 0)
const overallPct = computed(() => totalTasks.value > 0 ? Math.round((totalCompleted.value / totalTasks.value) * 100) : 0)

// Menu & edit
const showMenu = ref<number | null>(null)
const editingId = ref<number | null>(null)
const submittingHw = ref(false)

// Add form
const showAddForm = ref(false)
const addForm = ref({ subject: '', taskType: '有效作业', totalAmount: 1 as number | null, unit: '张', timePerUnit: null as number | null, notes: '' })
const addValid = computed(() => addForm.value.subject && addForm.value.taskType.trim() && addForm.value.totalAmount && addForm.value.totalAmount > 0 && addForm.value.unit.trim())

const editForm = ref({ subject: '', taskType: '', totalAmount: 1 as number | null, unit: '', timePerUnit: null as number | null, notes: '' })
const editValid = computed(() => editForm.value.subject && editForm.value.taskType.trim() && editForm.value.totalAmount && editForm.value.totalAmount > 0 && editForm.value.unit.trim())

async function loadAll() {
  loading.value = true
  const [detailRes, hwRes] = await Promise.all([
    planApi.detail(planId.value),
    homeworkApi.list(planId.value),
  ])
  loading.value = false
  if (detailRes.ok) plan.value = detailRes.data
  else error.value = detailRes.error || '加载失败'
  if (hwRes.ok) homeworkItems.value = hwRes.data.homework
}

onMounted(loadAll)

function subjectIcon(s: string): string {
  const m: Record<string,string> = {'数学':'📐','英语':'🇬🇧','语文':'📖','物理':'⚛️','化学':'🧪','生物':'🧬','历史':'📜','地理':'🌍','政治':'⚖️'}
  return m[s] || '📝'
}
function fmt(n: number): string { return Number.isInteger(n) ? n.toString() : n.toFixed(2).replace(/\.?0+$/,'') }
function hwPct(hw: HomeworkItem): number {
  if (!hw.total_amount || hw.total_amount <= 0) return 0
  return Math.min(Math.round((hw.completed_amount / hw.total_amount) * 100), 100)
}
function dayOfWeek(d: string): string { return ['周日','周一','周二','周三','周四','周五','周六'][new Date(d+'T00:00:00').getDay()] }
function dayMinutes(day: { slots: {estimatedMinutes:number}[] }): number {
  return day.slots.reduce((s, t) => s + t.estimatedMinutes, 0)
}

function closeMenu() { showMenu.value = null }
function toggleMenu(id: number) { showMenu.value = showMenu.value === id ? null : id }

function handleEdit(hw: HomeworkItem) {
  closeMenu(); editingId.value = hw.id
  editForm.value = { subject: hw.subject, taskType: hw.task_type, totalAmount: hw.total_amount, unit: hw.unit, timePerUnit: hw.time_per_unit, notes: hw.notes }
}
function cancelEdit() { editingId.value = null }
async function saveEdit(id: number) {
  if (!editValid.value) return
  await homeworkApi.update(id, { planId: planId.value, subject: editForm.value.subject, type: editForm.value.taskType, totalAmount: editForm.value.totalAmount ?? 1, unit: editForm.value.unit, timePerUnit: editForm.value.timePerUnit || 60, notes: editForm.value.notes })
  editingId.value = null
  loadAll()
}

async function handleDelete(id: number) {
  closeMenu()
  if (!confirm('确定删除这条作业？')) return
  await homeworkApi.remove(id)
  loadAll()
}

async function handleAddHw() {
  if (!addValid.value || !addForm.value.totalAmount || submittingHw.value) return
  submittingHw.value = true
  await homeworkApi.create({ planId: planId.value, subject: addForm.value.subject, type: addForm.value.taskType, totalAmount: addForm.value.totalAmount ?? 1, unit: addForm.value.unit, timePerUnit: addForm.value.timePerUnit || 60, notes: addForm.value.notes })
  submittingHw.value = false
  addForm.value = { subject: '', taskType: '有效作业', totalAmount: 1, unit: '张', timePerUnit: null, notes: '' }
  showAddForm.value = false
  loadAll()
}

</script>

<style scoped>
.page { padding: 16px; padding-bottom: 100px; max-width: 600px; margin: 0 auto; position: relative; }
.menu-overlay { position: fixed; inset: 0; z-index: 9; background: transparent; }
.page-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.page-header h2 { font-size: 1.125rem; font-weight: 700; color: var(--color-text); }
.btn-back { padding: 6px 12px; border: 1.5px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-card); color: var(--color-text-secondary); font-size: 0.875rem; cursor: pointer; font-family: var(--font-family); }
.loading, .error-msg { text-align: center; padding: 40px; color: var(--color-text-secondary); }
.summary-card { background: var(--color-card); border-radius: var(--radius); padding: 16px 20px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.summary-dates { font-size: 0.8125rem; color: var(--color-text-secondary); }
.summary-stats-row { display: flex; gap: 4px; font-size: 0.8125rem; color: var(--color-text-placeholder); margin: 4px 0 8px; }
.plan-progress { display: flex; align-items: center; gap: 8px; }
.progress-bar-wrap { flex: 1; height: 5px; background: var(--color-input-bg); border-radius: 3px; overflow: hidden; }
.progress-fill { height: 100%; background: var(--color-primary); border-radius: 3px; transition: width 0.3s; }
.progress-fill.full { background: var(--color-success); }
.progress-text { font-size: 0.75rem; color: var(--color-text-secondary); white-space: nowrap; min-width: 35px; }

.action-row { display: flex; gap: 8px; margin-bottom: 16px; }
.btn-action { flex: 1; text-align: center; padding: 10px; border: 1.5px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-card); color: var(--color-primary); font-size: 0.875rem; font-weight: 600; cursor: pointer; font-family: var(--font-family); text-decoration: none; }
.btn-action:disabled { opacity: 0.5; cursor: not-allowed; }

.hw-section h3, .days-section h3 { font-size: 1rem; font-weight: 600; margin-bottom: 10px; color: var(--color-text); }
.hw-card { background: var(--color-card); border-radius: var(--radius-sm); padding: 14px 16px; margin-bottom: 8px; position: relative; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.hw-card.is-editing { border: 2px solid var(--color-primary); }
.hw-main { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 0.9375rem; padding-right: 32px; }
.hw-subject { font-weight: 600; color: var(--color-text); }
.hw-divider { color: var(--color-border); }
.hw-type { color: var(--color-text-secondary); }
.hw-amount { font-weight: 600; color: var(--color-primary); }
.btn-menu { position: absolute; right: 12px; top: 10px; background: none; border: none; font-size: 1.25rem; color: var(--color-text-placeholder); cursor: pointer; padding: 2px 8px; border-radius: 4px; line-height: 1; letter-spacing: 2px; }
.btn-menu:hover, .btn-menu.active { color: var(--color-text); background: var(--color-bg-secondary); }
.hw-meta { font-size: 0.8125rem; color: var(--color-text-placeholder); display: flex; gap: 10px; }
.hw-footer { margin-top: 6px; display: flex; align-items: center; gap: 8px; }
.hw-no-tasks .hint { font-size: 0.75rem; color: var(--color-text-placeholder); }

.context-menu { position: absolute; right: 8px; top: 38px; z-index: 10; background: var(--color-card); border-radius: var(--radius-sm); box-shadow: 0 4px 16px rgba(0,0,0,0.12); padding: 6px 0; min-width: 150px; overflow: hidden; }
.menu-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 10px 16px; border: none; background: none; font-size: 0.875rem; color: var(--color-text); cursor: pointer; text-align: left; font-family: var(--font-family); }
.menu-item:hover { background: var(--color-bg-secondary); }
.menu-item-danger { color: var(--color-error); }
.menu-icon { font-size: 1rem; width: 20px; text-align: center; }
.menu-divider { height: 1px; background: var(--color-border); margin: 4px 0; }
.menu-pop-enter-active { transition: all 0.2s cubic-bezier(0.34,1.56,0.64,1); }
.menu-pop-leave-active { transition: all 0.12s; }
.menu-pop-enter-from { opacity: 0; transform: scale(0.95) translateY(-4px); }
.menu-pop-leave-to { opacity: 0; transform: scale(0.95) translateY(-2px); }

.inline-edit { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--color-border); display: flex; flex-direction: column; gap: 8px; }
.edit-row { display: flex; gap: 10px; }
.edit-field { display: flex; flex-direction: column; gap: 3px; }
.edit-field.half { flex: 1; }
.edit-field label { font-size: 0.75rem; color: var(--color-text-placeholder); }
.edit-field input, .edit-field select { padding: 8px 10px; border: 1.5px solid var(--color-border); border-radius: var(--radius-sm); font-size: 0.875rem; font-family: var(--font-family); background: var(--color-input-bg); color: var(--color-text); width: 100%; box-sizing: border-box; }
.edit-actions { display: flex; gap: 8px; margin-top: 4px; }

.add-hw { text-align: center; padding: 12px; margin: 8px 0 16px; border: 2px dashed var(--color-border); border-radius: var(--radius); color: var(--color-primary); font-size: 0.9375rem; font-weight: 600; cursor: pointer; }
.add-hw:hover { border-color: var(--color-primary); background: rgba(79,70,229,0.04); }
.add-form { background: var(--color-card); border-radius: var(--radius); padding: 16px; margin-bottom: 16px; }
.form-group { display: flex; flex-direction: column; margin-bottom: 8px; }
.form-group label { font-size: 0.75rem; color: var(--color-text-placeholder); margin-bottom: 3px; }
.form-group input, .form-group select { padding: 8px 10px; border: 1.5px solid var(--color-border); border-radius: var(--radius-sm); font-size: 0.875rem; font-family: var(--font-family); background: var(--color-input-bg); color: var(--color-text); width: 100%; box-sizing: border-box; }
.form-row { display: flex; gap: 10px; }
.form-actions { margin-top: 8px; }

.btn-primary { padding: 10px 16px; border: none; border-radius: var(--radius-sm); background: var(--color-primary); color: #fff; font-size: 0.875rem; font-weight: 600; cursor: pointer; font-family: var(--font-family); width: 100%; }
.btn-primary:disabled { background: var(--color-primary-disabled); cursor: not-allowed; }
.btn-secondary { padding: 10px 16px; border: 1.5px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-card); color: var(--color-text-secondary); font-size: 0.875rem; cursor: pointer; font-family: var(--font-family); flex: 1; }

.days-section { margin-top: 8px; }
.day-group { background: var(--color-card); border-radius: var(--radius-sm); margin-bottom: 8px; }
.day-header { display: flex; justify-content: space-between; padding: 10px 14px; cursor: pointer; list-style: none; font-size: 0.8125rem; }
.day-header::-webkit-details-marker { display: none; }
.day-count { color: var(--color-text-placeholder); font-size: 0.75rem; }
.task-list { padding: 0 14px 10px; display: flex; flex-direction: column; gap: 4px; }
.task-item { display: flex; align-items: center; gap: 6px; padding: 6px 8px; background: var(--color-input-bg); border-radius: 6px; font-size: 0.8125rem; }
.task-check { font-size: 0.875rem; }
.task-check.done { opacity: 0.5; }
.task-amount { font-weight: 600; color: var(--color-primary); margin-left: auto; }
.task-time { color: var(--color-text-placeholder); font-size: 0.75rem; }
</style>
