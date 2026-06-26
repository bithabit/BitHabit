import { defineStore } from 'pinia'
import { ref } from 'vue'
import { scheduleApi, type ScheduleData } from '../api'

export const useScheduleStore = defineStore('schedule', () => {
  const data = ref<ScheduleData>({ weekly: [], special: [] })
  const loading = ref(false)
  const error = ref('')

  async function fetchAll() {
    loading.value = true
    error.value = ''
    const res = await scheduleApi.list()
    loading.value = false
    if (res.ok) {
      data.value = res.data
    } else {
      error.value = res.error || '加载失败'
    }
  }

  async function addWeekly(input: {
    dayOfWeek: number
    startTime: string | null
    endTime: string | null
    label: string
  }) {
    const res = await scheduleApi.addWeekly(input)
    if (res.ok) {
      await fetchAll()
      return true
    }
    error.value = res.error || '添加失败'
    return false
  }

  async function addSpecial(input: {
    dateFrom: string
    dateTo: string | null
    startTime: string | null
    endTime: string | null
    label: string
  }) {
    const res = await scheduleApi.addSpecial(input)
    if (res.ok) {
      await fetchAll()
      return true
    }
    error.value = res.error || '添加失败'
    return false
  }

  async function remove(id: number, type: 'weekly' | 'special') {
    const res = await scheduleApi.remove(id, type)
    if (res.ok) {
      await fetchAll()
      return true
    }
    error.value = res.error || '删除失败'
    return false
  }

  return { data, loading, error, fetchAll, addWeekly, addSpecial, remove }
})
