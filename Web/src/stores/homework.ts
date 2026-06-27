import { defineStore } from 'pinia'
import { ref } from 'vue'
import { homeworkApi, type HomeworkItem, type HomeworkInput } from '../api'

export const useHomeworkStore = defineStore('homework', () => {
  const items = ref<HomeworkItem[]>([])
  const loading = ref(false)
  const error = ref('')
  const currentPlanId = ref(0)

  async function fetchByPlan(planId: number) {
    currentPlanId.value = planId
    loading.value = true
    error.value = ''
    const res = await homeworkApi.list(planId)
    loading.value = false
    if (res.ok) {
      items.value = res.data.homework
    } else {
      error.value = res.error || '加载失败'
    }
  }

  async function create(input: HomeworkInput) {
    const res = await homeworkApi.create(input)
    if (res.ok) {
      await fetchByPlan(input.planId)
      return true
    }
    error.value = res.error || '添加失败'
    return false
  }

  async function remove(id: number) {
    const res = await homeworkApi.remove(id)
    if (res.ok) {
      items.value = items.value.filter(h => h.id !== id)
      return true
    }
    error.value = res.error || '删除失败'
    return false
  }

  async function update(id: number, input: Partial<HomeworkInput>) {
    const res = await homeworkApi.update(id, input)
    if (res.ok) {
      if (currentPlanId.value) await fetchByPlan(currentPlanId.value)
      return true
    }
    error.value = res.error || '更新失败'
    return false
  }

  return { items, loading, error, currentPlanId, fetchByPlan, create, remove, update }
})
