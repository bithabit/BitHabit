# BitHabit - 任务清单

> 最后更新：2026-06-27  
> 执行者：Coder Agent  
> 来源：Designer Agent

---

## 当前准确状态（Coder 确认，2026-06-27）

| 模块 | 文件 | 状态 |
|------|------|------|
| 登录/注册 | LoginView / RegisterView + auth API | ✅ |
| 作业录入（手动+AI） | HomeworkView.vue + `/api/ai/parse` | ✅ |
| 日程配置 | ScheduleView.vue + schedule API | ✅ |
| 计划生成（平均分配） | PlanCreateView.vue + generate API | ✅ |
| 计划详情 | PlanDetailView.vue | ✅ |
| 今日任务 | TodayView.vue + today API | ✅ |
| 计划列表 | PlansListView.vue + list API | ✅ |
| 底部导航三栏 | App.vue | ✅ |
| 日历视图 | — | ❌ |
| 拖拽调整 | — | ❌ |
| 追赶/重新分配 | API 规格已有 | ❌ |
| 社交/分享 | — | ❌ |

---

## TASK-002: 页面导航重构 — 今日/作业/计划

**优先级**：P0  
**状态**：✅ 已完成  
**创建**：2026-06-27  
**完成**：2026-06-27  
**commit**：23e67b9

### 需求概述

重构底部导航为三栏："今日" | "作业" | "计划"，登录后默认进入今日学习计划页。

### 子任务

#### 2.1 新建后端 API：计划列表 `GET /api/plans/list.php`

**文件**：`api/plans/list.php`

**接口规格**：
```
GET /api/plans/list.php
Authorization: Bearer <token>

Response (200):
{
  "plans": [
    {
      "id": 1,
      "name": "暑假作业计划",
      "start_date": "2026-07-01",
      "end_date": "2026-08-30",
      "strategy": "average",
      "task_count": 180,
      "completed_count": 45,
      "created_at": "2026-06-27 10:00:00"
    }
  ]
}
```

- 按 `created_at DESC` 排序
- `task_count` = 该计划下 plan_tasks 总数
- `completed_count` = `completed = 1` 的任务数
- 需 JWT 认证，仅返回当前用户的计划

#### 2.2 新建后端 API：今日任务 `GET /api/plans/today.php`

**文件**：`api/plans/today.php`

**接口规格**：
```
GET /api/plans/today.php
Authorization: Bearer <token>

Response (200) - 有今日任务：
{
  "planId": 1,
  "planName": "暑假作业计划",
  "date": "2026-07-15",
  "tasks": [
    {
      "id": 42,
      "subject": "数学",
      "taskType": "模拟卷",
      "amount": 0.5,
      "unit": "套",
      "estimatedMinutes": 45,
      "completed": false
    }
  ]
}

Response (200) - 无计划：
{ "planId": null, "planName": null, "date": "2026-07-15", "tasks": [] }

Response (200) - 有计划但今天没任务（休息日/超范围）：
{ "planId": 1, "planName": "暑假作业计划", "date": "2026-07-15", "tasks": [] }
```

**逻辑**：
1. 取用户最新计划（`created_at DESC LIMIT 1`）
2. 如果没有计划 → 返回空
3. 如果有计划 → 查 `plan_tasks` 中 `plan_id=? AND date=CURDATE()` 的记录
4. JOIN `homework` 获取 `subject, task_type, unit`
5. 返回今天日期（即使没任务）

#### 2.3 新建后端 API：任务打卡切换 `PATCH /api/plans/tasks/toggle.php`

**文件**：`api/plans/tasks/toggle.php`

**接口规格**：
```
PATCH /api/plans/tasks/toggle.php
Authorization: Bearer <token>

Request:
{ "id": 42 }

Response (200):
{ "id": 42, "completed": true, "completedAt": "2026-07-15T10:00:00" }
```

- 切换 `completed` 状态（0→1 或 1→0）
- `completedAt`：完成时设为 `NOW()`，取消完成时设为 null
- 需验证该 task 属于当前用户的计划

#### 2.4 更新前端 API 客户端

**文件**：`src/api/index.ts`

在 `planApi` 中新增三个方法：

```typescript
// --- 类型定义（新增） ---
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

// --- 新增方法 ---
export const planApi = {
  // ... 保留原有 generate(), detail()

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
```

#### 2.5 新建页面：今日任务 `TodayView.vue`

**文件**：`src/views/TodayView.vue`

**页面设计**：

```
┌─────────────────────────────────┐
│  BitHabit              [退出]   │  顶部栏
├─────────────────────────────────┤
│                                 │
│  📅 7月15日 周三                │  日期头
│                                 │
│  ┌─────────────────────────┐   │
│  │ ▢ 📐 数学 · 模拟卷      │   │  任务卡片
│  │     0.5 套 · 约 45 分钟  │   │
│  └─────────────────────────┘   │
│  ┌─────────────────────────┐   │
│  │ ✅ 🇬🇧 英语 · 阅读理解   │   │  已完成（变灰）
│  │     2 篇 · 约 30 分钟    │   │
│  └─────────────────────────┘   │
│  ┌─────────────────────────┐   │
│  │ ▢ 📖 语文 · 读后感       │   │
│  │     0.5 篇 · 约 30 分钟  │   │
│  └─────────────────────────┘   │
│                                 │
│  总计：3 项 · 约 105 分钟       │  摘要
│                                 │
└─────────────────────────────────┘
```

**空状态**（无计划时）：
```
┌─────────────────────────────────┐
│          📋                      │
│      还没有学习计划               │
│   去「计划」页面生成一个吧 →      │
└─────────────────────────────────┘
```

**今日无任务**（有计划但今天休息日/超范围）：
```
┌─────────────────────────────────┐
│          🎉                      │
│        今天没有任务              │
│      好好休息一下吧～             │
└─────────────────────────────────┘
```

**功能点**：
- `onMounted` 调用 `planApi.today()` 获取今日任务
- 点击任务卡片左侧 ▢/✅ 切换完成状态（调 `planApi.toggleTask(id)`）
- 已完成任务显示为灰色 + 划线 + ✅ 图标
- 底部显示任务总数和总预估时间
- 顶部显示日期和星期（中文）
- Subscribe `useAuthStore` 获取用户信息、退出登录
- 页面在底部导航栏上方，需要 `padding-bottom: 70px` 避免被遮挡

**日期格式化**：
```typescript
const dayNames = ['周日', '周一', '周二', '周三', '周四', '周五', '周六']
function formatDate(dateStr: string): string {
  const d = new Date(dateStr + 'T00:00:00')
  const month = d.getMonth() + 1
  const day = d.getDate()
  const dow = dayNames[d.getDay()]
  return `${month}月${day}日 ${dow}`
}
```

#### 2.6 新建页面：计划列表 `PlansListView.vue`

**文件**：`src/views/PlansListView.vue`

**页面设计**：

```
┌─────────────────────────────────┐
│  🎯 我的计划                     │  标题
├─────────────────────────────────┤
│                                 │
│  ┌─────────────────────────┐   │
│  │ 暑假作业计划             │   │  计划卡片（可点击）
│  │ 7/1 - 8/30 · 180 项任务  │   │
│  │ ████████░░ 45/180 已完成 │   │  进度条
│  │ 2026-06-27 创建          │   │
│  └─────────────────────────┘   │
│                                 │
│  ┌─────────────────────────┐   │
│  │ 数学专项                  │   │
│  │ 7/5 - 7/20 · 30 项任务   │   │
│  │ ██████████ 30/30 已完成  │   │
│  │ 2026-06-27 创建          │   │
│  └─────────────────────────┘   │
│                                 │
│  ┌─────────────────────────┐   │
│  │ ＋ 新建计划               │   │  新建按钮
│  └─────────────────────────┘   │
│                                 │
└─────────────────────────────────┘
```

**空状态**（无计划时）：
```
┌─────────────────────────────────┐
│          🎯                      │
│       还没有计划                  │
│    点击下方按钮生成第一个计划     │
│                                 │
│     ┌───────────────────┐      │
│     │  ✨ 生成我的计划    │      │
│     └───────────────────┘      │
└─────────────────────────────────┘
```

**功能点**：
- `onMounted` 调用 `planApi.list()` 获取计划列表
- 点击计划卡片 → `router.push('/plan/' + plan.id)` 查看详情
- 进度条：`completed_count / task_count * 100%`
- 「+ 新建计划」按钮 → 跳转到计划生成流程
  - 如果已有作业数据 → 直接跳 `/plan/create`
  - 如果还没有作业 → 弹窗提示「请先在作业页面录入暑假作业」
- 页面 `padding-bottom: 70px`

#### 2.7 更新路由配置

**文件**：`src/router/index.ts`

变更：
```typescript
// 新增路由
{
  path: '/plans',
  name: 'plans',
  component: () => import('../views/PlansListView.vue'),
  meta: { requiresAuth: true },
},

// 修改：首页指向今日任务（原 HomeView）
// 方案：直接改 / 路由的 component 指向 TodayView
// HomeView.vue 文件保留但不再使用（后续可删除）
{
  path: '/',
  name: 'today',      // 改名
  component: () => import('../views/TodayView.vue'),  // 改组件
  meta: { requiresAuth: true },
},
```

#### 2.8 更新底部导航栏

**文件**：`src/App.vue`

变更底部导航 `<nav class="bottom-nav">` 为：

```html
<nav class="bottom-nav" v-if="auth.token">
  <router-link to="/" class="nav-item" active-class="nav-active" :class="{ 'nav-active': isTodayActive }">
    <span class="nav-icon">📋</span>
    <span class="nav-label">今日</span>
  </router-link>
  <router-link to="/homework" class="nav-item" active-class="nav-active">
    <span class="nav-icon">📚</span>
    <span class="nav-label">作业</span>
  </router-link>
  <router-link to="/plans" class="nav-item" active-class="nav-active">
    <span class="nav-icon">🎯</span>
    <span class="nav-label">计划</span>
  </router-link>
</nav>
```

相应更新 `isTodayActive` 计算属性（已经是 `route.path === '/'`，不需要改）。

#### 2.9 调整计划生成页跳转

**文件**：`src/views/PlanCreateView.vue`

`viewPlan()` 函数中，生成成功后跳转路径改为：
```typescript
function viewPlan() {
  router.push('/plans')  // 原来是 /plan/${result.value.planId}
}
```

同时在生成成功结果区增加「查看详情」按钮跳转到 `/plan/${result.value.planId}`，以保留直接查看计划详情的入口。

---

## 任务依赖关系

```
2.1 (list API) ──┐
2.2 (today API) ─┼──→ 2.4 (前端 API 客户端) ──→ 2.5 (TodayView)
2.3 (toggle API) ─┘                             2.6 (PlansListView)
                                                     │
2.7 (路由) ←──────────────────────────────────────────┘
2.8 (底部导航) ←──────────────────────────────────────┘
2.9 (计划生成页跳转)
```

**推荐执行顺序**：2.1 → 2.2 → 2.3 → 2.4 → 2.5 → 2.6 → 2.7 → 2.8 → 2.9

---

## 测试要点

- [ ] 新用户（无计划）登录 → 今日页显示空状态引导
- [ ] 有计划用户登录 → 今日页显示当天任务
- [ ] 点击任务 ▢ → 切换为 ✅，再次点击恢复
- [ ] 今日无任务（休息日）→ 显示休息提示
- [ ] 计划列表显示所有计划 + 进度条
- [ ] 空计划列表显示新建引导
- [ ] 底部导航三个 tab 切换正常
- [ ] 生成计划后正确跳转到计划列表

---

## 历史任务
- [x] 早期批次：登录/注册、作业录入、日程配置、计划生成、计划详情
- [x] TASK-001 P0：APK 精简清理
- [x] TASK-002 P0：页面导航重构（今日/作业/计划）— commit `23e67b9`

---

## TASK-003: 日历视图 — 计划月视图

**优先级**：P0  
**状态**：⏳ 待设计  
**创建**：2026-06-27

### 需求概述

为计划提供月视图日历，让学生俯瞰整个暑假的任务分布。是后续拖拽调整的前置依赖。

### 子任务

#### 3.1 新建后端 API：月度日历汇总 `GET /api/plans/calendar.php`

**文件**：`api/plans/calendar.php`

```
GET /api/plans/calendar.php?planId=1&year=2026&month=7
Authorization: Bearer <token>

Response (200):
{
  "planId": 1,
  "planName": "暑假作业计划",
  "year": 2026,
  "month": 7,
  "startDate": "2026-07-01",
  "endDate": "2026-08-30",
  "days": [
    {
      "date": "2026-07-01",
      "taskCount": 4,
      "completedCount": 2,
      "totalMinutes": 120
    },
    {
      "date": "2026-07-05",
      "taskCount": 0,
      "completedCount": 0,
      "totalMinutes": 0
    }
  ]
}
```

**SQL 逻辑**：
```sql
-- 仅返回有数据的日期；前端自行生成完整月网格
SELECT date, COUNT(*) as task_count, 
       SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) as completed_count,
       SUM(estimated_minutes) as total_minutes
FROM plan_tasks 
WHERE plan_id = ? AND YEAR(date) = ? AND MONTH(date) = ?
GROUP BY date
ORDER BY date
```

- 需验证 plan 属于当前用户
- `days` 数组仅包含有任务的日期（taskCount=0 代表休息日）
- 无任务的日期不出现在数组中，前端渲染为空
- `startDate`/`endDate` 来自 plans 表，前端用于判断日期是否在计划范围内

#### 3.2 新建后端 API：单日任务列表 `GET /api/plans/day.php`

**文件**：`api/plans/day.php`

```
GET /api/plans/day.php?planId=1&date=2026-07-15
Authorization: Bearer <token>

Response (200):
{
  "planId": 1,
  "date": "2026-07-15",
  "tasks": [
    {
      "id": 42,
      "subject": "数学",
      "taskType": "模拟卷",
      "amount": 0.5,
      "unit": "套",
      "estimatedMinutes": 45,
      "completed": false
    }
  ]
}
```

- 查 `plan_tasks` WHERE `plan_id=? AND date=?`，JOIN `homework` 获取科目/类型
- 需验证 plan 属于当前用户
- 空任务返回 `tasks: []`

#### 3.3 更新前端 API 客户端

**文件**：`src/api/index.ts`

```typescript
// 新增类型
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

// planApi 新增方法
export const planApi = {
  // ... 保留现有方法

  /** 月度日历汇总 */
  calendar(planId: number, year: number, month: number) {
    return request<CalendarData>('GET', `/plans/calendar.php?planId=${planId}&year=${year}&month=${month}`)
  },

  /** 单日任务列表 */
  getDay(planId: number, date: string) {
    return request<{ planId: number; date: string; tasks: TodayTask[] }>('GET', `/plans/day.php?planId=${planId}&date=${date}`)
  },
}
```

#### 3.4 新建页面：日历视图 `CalendarView.vue`

**文件**：`src/views/CalendarView.vue`

**页面布局**：

```
┌─────────────────────────────────┐
│  ← 2026年 7月 →                 │  月份导航
│  暑假作业计划                    │  计划名称（小字）
├─────────────────────────────────┤
│  一  二  三  四  五  六  日      │  星期头
├─────────────────────────────────┤
│      1   2   3   4   5   6      │
│      4/2 3/0 休  2/1 6/3  —     │  月网格
│                                 │
│  7   8   9  10  11  12  13      │
│ 4/2 5/3 3/1 2/2 休  4/0 1/1     │
│                                 │
│ 14  15  16  17  18  19  20      │
│ 3/1  🏖  2/2 4/3 1/0  —   —     │
│                                 │
│ 21  22  23  24  25  26  27      │
│ 5/2 3/1 1/1 休  2/0 4/3 3/2     │
│                                 │
│ 28  29  30  31                  │
│ 2/1 1/1 3/2  —                  │
├─────────────────────────────────┤
│ 图例：完成/总数  休=休息日  —=不在计划范围  │
└─────────────────────────────────┘
```

**日期格子的三种状态**：

| 状态 | 视觉 | 条件 |
|------|------|------|
| 有任务 | 数字 + 完成/总数，彩色背景 | `taskCount > 0` |
| 全部完成 | 数字 + ✅，绿色背景 | `taskCount > 0 && completedCount == taskCount` |
| 休息日 | 数字，「休」标记，灰色 | 在计划范围内但无任务 |
| 今日 | 蓝色边框/高亮 | `date === today` |
| 过去 | 整体降低透明度 | `date < today` |
| 未来 | 正常显示 | `date > today` |
| 超出范围 | 数字变极淡 | 不在 `startDate ~ endDate` 之间 |

**功能点**：

1. **月份导航**
   - 左右箭头切换月份
   - 月份显示格式：`2026年 7月`
   - 不能早于计划 `startDate` 所在月，不能晚于 `endDate` 所在月

2. **日历网格**
   - 7 列 × 5~6 行
   - 周一为每周第一天（中国习惯）
   - 每月第一天对齐到正确的星期几
   - 上/下月补白格子留空（不渲染内容）

3. **点击日期**
   - 点有任务的日期 → 底部弹出半屏面板（Bottom Sheet）
   - 面板显示该日任务列表（与今日页卡片同款样式）
   - 面板内支持点击切换完成状态（调 `toggleTask`）
   - 面板外点击或下滑关闭
   - 点休息日/无任务日 → 不弹出

4. **图例**
   - 底部固定一行图例说明

5. **路由参数**
   - 路由：`/plan/:planId/calendar`
   - 支持 query `?month=2026-07` 指定初始月份
   - 默认展示当前月份（如果是暑假期间）或 startDate 所在月

**组件设计**：

建议拆出一个 `DayCell.vue` 子组件，接收：
```typescript
props: {
  date: string           // "2026-07-15"
  dayNumber: number      // 15
  taskCount: number
  completedCount: number
  totalMinutes: number
  isToday: boolean
  isPast: boolean
  isInRange: boolean     // 是否在计划日期范围内
  isFirstDayOfMonth: boolean  // 是否需要显示月份标签
}
emits: ['click']
```

**计算逻辑**（前端）：
```typescript
function buildMonthGrid(year: number, month: number, calendarData: CalendarData) {
  const firstDay = new Date(year, month - 1, 1)
  const lastDay = new Date(year, month, 0)
  const startDow = firstDay.getDay()  // 0=周日
  // 调整为周一开头: 周日 → 第7列 (index 6)
  const startCol = startDow === 0 ? 6 : startDow - 1
  
  const daysMap = new Map(calendarData.days.map(d => [d.date, d]))
  const today = new Date().toISOString().slice(0, 10)
  const cells = []
  
  // 前置空白
  for (let i = 0; i < startCol; i++) cells.push(null)
  
  // 月内日期
  for (let d = 1; d <= lastDay.getDate(); d++) {
    const dateStr = `${year}-${String(month).padStart(2,'0')}-${String(d).padStart(2,'0')}`
    const dayData = daysMap.get(dateStr)
    cells.push({
      date: dateStr,
      dayNumber: d,
      taskCount: dayData?.taskCount ?? 0,
      completedCount: dayData?.completedCount ?? 0,
      totalMinutes: dayData?.totalMinutes ?? 0,
      isToday: dateStr === today,
      isPast: dateStr < today,
      isInRange: dateStr >= calendarData.startDate && dateStr <= calendarData.endDate,
    })
  }
  
  return cells
}
```

#### 3.5 更新路由配置

**文件**：`src/router/index.ts`

```typescript
{
  path: '/plan/:planId/calendar',
  name: 'planCalendar',
  component: () => import('../views/CalendarView.vue'),
  meta: { requiresAuth: true },
}
```

#### 3.6 计划详情页添加入口

**文件**：`src/views/PlanDetailView.vue`

在页面顶部（计划名称旁边）增加一个「📅 日历视图」按钮：
```html
<router-link :to="`/plan/${plan.id}/calendar`" class="btn-calendar">
  📅 日历视图
</router-link>
```

样式：小型次要按钮，与页面标题同行右侧。

#### 3.7 计划列表页添加入口

**文件**：`src/views/PlansListView.vue`

每张计划卡片增加一个日历图标按钮（或点击卡片进入日历，长按/右滑进详情）。

**方案 A**（推荐）：卡片右侧增加小图标按钮 📅，点击进入日历
**方案 B**：点击卡片→详情页（现有），详情页内→日历

先用方案 B（3.6 已覆盖），方案 A 作为可选增强。

---

## 任务依赖

```
3.1 (calendar API) ─┐
                    ├──→ 3.3 (前端 API) ──→ 3.4 (CalendarView)
3.2 (day API) ─────┘                        │
                                             ├──→ 3.5 (路由)
                                             └──→ 3.6 (详情页入口)
```

**推荐执行顺序**：3.1 → 3.2 → 3.3 → 3.4 → 3.5 → 3.6

---

## 测试要点

- [ ] 月视图正确显示所有日期（对齐到周一）
- [ ] 有任务日期显示完成/总数
- [ ] 全部完成日期显示绿色 ✅
- [ ] 休息日显示「休」标记
- [ ] 今日日期蓝色高亮
- [ ] 计划范围外日期灰色淡化
- [ ] 左右箭头切换月份，边界正确
- [ ] 点击有任务日期 → 弹出任务列表
- [ ] 弹窗内点击切换完成状态
- [ ] 弹窗外点击关闭
- [ ] 计划详情页「日历视图」按钮可点击进入
