/**
 * BitHabit API 客户端 (v2)
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

    // Read as text first so we can show raw output on parse failure
    const rawText = await response.text()

    let data: T
    try {
      data = JSON.parse(rawText) as T
    } catch (parseErr) {
      return {
        ok: false,
        data: null as unknown as T,
        error: '服务器返回的内容不是JSON: ' + rawText.slice(0, 500),
      }
    }

    if (!response.ok) {
      return {
        ok: false,
        data: null as unknown as T,
        error: (data as Record<string,unknown>).error as string || '请求失败',
      }
    }

    return { ok: true, data: data as T }
  } catch (err: unknown) {
    clearTimeout(timeoutId)
    if (err instanceof Error) {
      if (err.name === 'AbortError') {
        return { ok: false, data: null as unknown as T, error: '请求超时（' + (timeoutMs / 1000) + '秒），再试试？' }
      }
      return { ok: false, data: null as unknown as T, error: '网络错误: ' + err.message }
    }
    return { ok: false, data: null as unknown as T, error: '未知错误' }
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

// --- 作业相关 API (v2: 归属于计划) ---

export interface HomeworkItem {
  id: number
  subject: string
  task_type: string
  total_amount: number
  unit: string
  time_per_unit: number | null
  notes: string
  created_at: string
  completed_amount: number
}

export interface HomeworkInput {
  planId: number
  subject: string
  type: string
  totalAmount: number
  unit: string
  timePerUnit?: number | null
  notes?: string
}

export const homeworkApi = {
  list(planId: number) {
    return request<{ homework: HomeworkItem[] }>('GET', `/homework.php?plan_id=${planId}`)
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
  addWeekly(input: { dayOfWeek: number; startTime: string | null; endTime: string | null; label: string }) {
    return request<{ id: string }>('POST', '/schedule.php?type=weekly', input as unknown as Record<string, unknown>)
  },
  addSpecial(input: { dateFrom: string; dateTo: string | null; startTime: string | null; endTime: string | null; label: string }) {
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
  weekly: { dayOfWeek: number; startTime: string | null; endTime: string | null; label: string }[]
  special: { dateFrom: string; dateTo: string | null; startTime: string | null; endTime: string | null; label: string }[]
}

export const aiApi = {
  parseHomework(text: string) {
    return request<{ tasks: AiTask[] }>('POST', '/ai/parse.php', { text } as unknown as Record<string, unknown>)
  },
  parseSchedule(text: string) {
    return request<AiScheduleResult>('POST', '/ai/parse-schedule.php', { text } as unknown as Record<string, unknown>)
  },
}

// --- 计划 API (v2) ---

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
  planId?: number
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

// --- 计划列表 v2 ---

export interface PlanListItem {
  id: number
  name: string
  start_date: string
  end_date: string
  strategy: string
  homework_count: number
  task_count: number
  completed_count: number
  status: 'active' | 'pending' | 'completed' | 'expired'
  created_at: string
}

// --- 今日任务 v2 (多计划) ---

export interface TodayTask {
  id: number
  subject: string
  taskType: string
  amount: number
  unit: string
  estimatedMinutes: number
  completed: boolean
}

export interface PlanTaskGroup {
  planId: number
  planName: string
  tasks: TodayTask[]
}

export interface TodayData {
  date: string
  plans: PlanTaskGroup[]
  totalTasks: number
  totalMinutes: number
}

// --- 日历视图 ---

export interface CalendarDay {
  date: string
  taskCount: number
  completedCount: number
  totalMinutes: number
}

export interface CalendarData {
  planId: number
  planName: string
  year: number
  month: number
  startDate: string
  endDate: string
  days: CalendarDay[]
}

export interface PlanCreateInput {
  name?: string
  startDate: string
  endDate: string
  dailyStartTime?: string
  dailyEndTime?: string
  strategy?: string
}

export interface PlanCreateResult {
  id: number
  name: string
  startDate: string
  endDate: string
}

// --- 预览分配类型 ---

export interface PreviewTask {
  homeworkId: number
  subject: string
  taskType: string
  amount: number
  unit: string
  estimatedMinutes: number
}

export interface PreviewDay {
  date: string
  totalMinutes: number
  overLimit: boolean
  tasks: PreviewTask[]
}

export interface PreviewResult {
  daily: PreviewDay[]
  allocatedRanges: { homeworkId: number; range: string }[]
  targetEndDate?: string
  warnings: string[]
  stats: { availableDays: number; maxDailyMinutes: number; rhythm: number }
}

export interface HomeworkAdjustItem {
  id: number
  subject: string
  taskType: string
  totalAmount: number
  unit: string
  timePerUnit: number | null
  windowStart: string | null
  windowEnd: string | null
  locked: boolean
  priority: number
  intervalDays: number
  allocatedRange: string | null
}

export const planApi = {
  /** 创建计划 */
  create(input: PlanCreateInput) {
    return request<PlanCreateResult>('POST', '/plans/create.php', input as unknown as Record<string, unknown>)
  },

  /** 生成每日任务 */
  generate(input: PlanGenerateInput) {
    return request<PlanGenerateResult>('POST', '/plans/generate.php', input as unknown as Record<string, unknown>, 30000)
  },

  /** 计划详情 */
  detail(planId: number) {
    return request<PlanDetail>('GET', `/plans/detail.php?id=${planId}`)
  },

  /** 计划列表 */
  list() {
    return request<{ plans: PlanListItem[] }>('GET', '/plans/list.php')
  },

  /** 编辑计划 */
  update(planId: number, input: Partial<PlanCreateInput>) {
    return request<{ updated: boolean }>('PATCH', `/plans/update.php?id=${planId}`, input as unknown as Record<string, unknown>)
  },

  /** 删除计划 */
  remove(planId: number) {
    return request<{ deleted: boolean }>('DELETE', `/plans/delete.php?id=${planId}`)
  },

  /** 归档计划 */
  archive(planId: number) {
    return request<{ archived: boolean }>('PATCH', `/plans/archive.php?id=${planId}`)
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

  /** 月度日历汇总 */
  calendar(planId: number, year: number, month: number) {
    return request<CalendarData>('GET', `/plans/calendar.php?planId=${planId}&year=${year}&month=${month}`)
  },

  /** 预览分配 */
  preview(input: { planId: number; rhythm: number; maxDailyMinutes: number; targetEndDate?: string | null; homeworkOverrides?: Record<string, unknown>[] }) {
    return request<PreviewResult>('POST', '/plans/preview.php', input as unknown as Record<string, unknown>)
  },

  /** 获取逐项调整列表 */
  getHomeworkAdjust(planId: number) {
    return request<{ homework: HomeworkAdjustItem[] }>('GET', `/plans/homework-adjust.php?planId=${planId}`)
  },

  /** 保存逐项调整 */
  saveHomeworkAdjust(planId: number, adjustments: { homeworkId: number; windowStart?: string | null; windowEnd?: string | null; intervalDays?: number; locked?: boolean }[]) {
    return request<{ updated: number }>('PUT', `/plans/homework-adjust.php?planId=${planId}`, { adjustments } as unknown as Record<string, unknown>)
  },

  /** 保存优先级排序 */
  saveHomeworkPriority(planId: number, order: number[]) {
    return request<{ updated: number }>('PUT', `/plans/homework-priority.php?planId=${planId}`, { order } as unknown as Record<string, unknown>)
  },

  /** 获取作业预估时间范围 */
  getHomeworkRanges(planId: number, rhythm: number, maxDailyMinutes: number) {
    return request<{ ranges: { homeworkId: number; subject: string; taskType: string; range: string }[] }>('POST', `/plans/homework-ranges.php?planId=${planId}`, { rhythm, maxDailyMinutes } as unknown as Record<string, unknown>)
  },

  /** 单日任务列表 */
  getDay(planId: number, date: string) {
    return request<{ planId: number; date: string; tasks: TodayTask[] }>('GET', `/plans/day.php?planId=${planId}&date=${date}`)
  },
}
