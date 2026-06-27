/**
 * BitHabit API 客户端
 * 封装 fetch，自动携带 JWT Token
 */

const API_BASE = '/api'

interface ApiResponse<T = unknown> {
  ok: boolean
  data: T
  error?: string
}

async function request<T = unknown>(
  method: string,
  path: string,
  body?: Record<string, unknown> | RegisterInput | { username: string; password: string },
  timeoutMs = 8000,
): Promise<ApiResponse<T>> {
  const token = localStorage.getItem('bithabit_token')

  const controller = new AbortController()
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs)

  try {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
    }
    if (token) {
      headers['Authorization'] = `Bearer ${token}`
    }

    const response = await fetch(`${API_BASE}${path}`, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
      signal: controller.signal,
    })

    clearTimeout(timeoutId)

    const data = await response.json()

    if (!response.ok) {
      return {
        ok: false,
        data: null as unknown as T,
        error: data.error || '请求失败',
      }
    }

    return { ok: true, data: data as T }
  } catch (err: unknown) {
    clearTimeout(timeoutId)
    if (err instanceof Error && err.name === 'AbortError') {
      return { ok: false, data: null as unknown as T, error: '网络好像不太好，再试试？' }
    }
    return { ok: false, data: null as unknown as T, error: '网络好像不太好，再试试？' }
  }
}

// --- 认证相关 API ---

export interface RegisterInput {
  username: string
  password: string
  nickname?: string
}

export interface AuthResponse {
  userId: string
  username: string
  nickname: string
  token: string
}

export interface UserInfo {
  userId: string
  username: string
  nickname: string
  createdAt: string
}

export const authApi = {
  register(input: RegisterInput) {
    return request<AuthResponse>('POST', '/auth/register.php', input)
  },

  login(username: string, password: string) {
    return request<AuthResponse>('POST', '/auth/login.php', { username, password })
  },

  checkUsername(username: string) {
    return request<{ available: boolean }>('GET', `/auth/check.php?username=${encodeURIComponent(username)}`)
  },

  getMe() {
    return request<UserInfo>('GET', '/auth/me.php')
  },
}

// --- 作业相关 API ---

export interface HomeworkItem {
  id: number
  subject: string
  task_type: string
  total_amount: number
  unit: string
  time_per_unit: number | null
  notes: string
  created_at: string
}

export interface HomeworkInput {
  subject: string
  type: string
  totalAmount: number
  unit: string
  timePerUnit?: number | null
  notes?: string
}

export const homeworkApi = {
  list() {
    return request<{ homework: HomeworkItem[] }>('GET', '/homework.php')
  },
  create(input: HomeworkInput) {
    return request<{ id: number; createdAt: string }>('POST', '/homework.php', input as unknown as Record<string, unknown>)
  },
  update(id: number, input: Partial<HomeworkInput>) {
    return request<{ updated: boolean }>('PATCH', `/homework.php?id=${id}`, input as unknown as Record<string, unknown>)
  },
  remove(id: number) {
    return request<{ deleted: boolean }>('DELETE', `/homework.php?id=${id}`)
  },
}

// --- 日程相关 API ---

export interface ScheduleWeekly {
  id: number
  day_of_week: number
  start_time: string | null
  end_time: string | null
  label: string
}

export interface ScheduleSpecial {
  id: number
  date_from: string
  date_to: string | null
  start_time: string | null
  end_time: string | null
  label: string
}

export interface ScheduleData {
  weekly: ScheduleWeekly[]
  special: ScheduleSpecial[]
}

export const scheduleApi = {
  list() {
    return request<ScheduleData>('GET', '/schedule.php')
  },
  addWeekly(input: {
    dayOfWeek: number
    startTime: string | null
    endTime: string | null
    label: string
  }) {
    return request<{ id: string }>('POST', '/schedule.php?type=weekly', input as unknown as Record<string, unknown>)
  },
  addSpecial(input: {
    dateFrom: string
    dateTo: string | null
    startTime: string | null
    endTime: string | null
    label: string
  }) {
    return request<{ id: string }>('POST', '/schedule.php?type=special', input as unknown as Record<string, unknown>)
  },
  remove(id: number, type: 'weekly' | 'special') {
    return request<{ deleted: boolean }>('DELETE', `/schedule.php?id=${id}&type=${type}`)
  },
  update(id: number, type: 'weekly' | 'special', input: Record<string, unknown>) {
    return request<{ updated: boolean }>('PATCH', `/schedule.php?id=${id}&type=${type}`, input)
  },
}

// --- AI 解析 API ---

export interface AiTask {
  subject: string
  type: string
  totalAmount: number
  unit: string
  timePerUnit: number | null
  notes: string
}

export interface AiScheduleResult {
  weekly: {
    dayOfWeek: number
    startTime: string | null
    endTime: string | null
    label: string
  }[]
  special: {
    dateFrom: string
    dateTo: string | null
    startTime: string | null
    endTime: string | null
    label: string
  }[]
}

export const aiApi = {
  parseHomework(text: string) {
    return request<{ tasks: AiTask[] }>('POST', '/ai/parse.php', { text } as unknown as Record<string, unknown>)
  },
  parseSchedule(text: string) {
    return request<AiScheduleResult>('POST', '/ai/parse-schedule.php', { text } as unknown as Record<string, unknown>)
  },
}

// --- 计划 API ---

export interface PlanTaskSlot {
  id: number
  homeworkId: number
  subject: string
  taskType: string
  amount: number
  unit: string
  estimatedMinutes: number
  completed: boolean
  completedAt: string | null
}

export interface PlanDay {
  date: string
  slots: PlanTaskSlot[]
}

export interface PlanDetail {
  id: number
  name: string
  start_date: string
  end_date: string
  daily_start_time: string
  daily_end_time: string
  strategy: string
  created_at: string
  days: PlanDay[]
}

export interface PlanGenerateInput {
  name?: string
  startDate: string
  endDate: string
  dailyStartTime?: string
  dailyEndTime?: string
  strategy?: string
}

export interface PlanGenerateResult {
  planId: number
  name: string
  startDate: string
  endDate: string
  totalWorkMinutes: number
  availableDays: number
  totalAvailableMinutes: number
}

// --- 计划列表 & 今日任务类型 ---

export interface PlanListItem {
  id: number
  name: string
  start_date: string
  end_date: string
  strategy: string
  task_count: number
  completed_count: number
  created_at: string
}

export interface TodayTask {
  id: number
  subject: string
  taskType: string
  amount: number
  unit: string
  estimatedMinutes: number
  completed: boolean
}

export interface TodayData {
  planId: number | null
  planName: string | null
  date: string
  tasks: TodayTask[]
}

export const planApi = {
  generate(input: PlanGenerateInput) {
    return request<PlanGenerateResult>('POST', '/plans/generate.php', input as unknown as Record<string, unknown>, 30000)
  },
  detail(planId: number) {
    return request<PlanDetail>('GET', `/plans/detail.php?id=${planId}`)
  },

  /** 计划列表 */
  list() {
    return request<{ plans: PlanListItem[] }>('GET', '/plans/list.php')
  },

  /** 今日任务 */
  today() {
    return request<TodayData>('GET', '/plans/today.php')
  },

  /** 切换任务完成状态 */
  toggleTask(taskId: number) {
    return request<{ id: number; completed: boolean; completedAt: string | null }>(
      'PATCH', '/plans/tasks/toggle.php', { id: taskId }
    )
  },
}
