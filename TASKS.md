# BitHabit - 任务清单

> 最后更新：2026-06-27  
> 执行者：Coder Agent  
> 来源：Designer Agent

## ⚠️ 执行规则

**Coder 完成任务后必须通过飞书通道直接通知用户验收，不要通过 sessions_send 通知 Designer。**

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
| UI 交互动画增强 | 全局动画 Token + 各页面动画 | ✅ |
| 日历视图 | — | ❌ |
| 拖拽调整 | — | ❌ |
| 追赶/重新分配 | API 规格已有 | ❌ |
| 社交/分享 | — | ❌ |

---

## TASK-004: 日历视图 — 计划月视图

**优先级**：P0  
**状态**：⏳ 待执行  
**创建**：2026-06-27

### 需求概述

为计划提供月视图日历，让学生俯瞰整个暑假的任务分布。是后续拖拽调整的前置依赖。

### 子任务

#### 4.1 新建后端 API：月度日历汇总 `GET /api/plans/calendar.php`

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

#### 4.2 新建后端 API：单日任务列表 `GET /api/plans/day.php`

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

#### 4.3 更新前端 API 客户端

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
/** 月度日历汇总 */
calendar(planId: number, year: number, month: number) {
  return request<CalendarData>('GET', `/plans/calendar.php?planId=${planId}&year=${year}&month=${month}`)
},

/** 单日任务列表 */
getDay(planId: number, date: string) {
  return request<{ planId: number; date: string; tasks: TodayTask[] }>('GET', `/plans/day.php?planId=${planId}&date=${date}`)
},
```

#### 4.4 新建页面：日历视图 `CalendarView.vue`

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

**日期格子的状态**：

| 状态 | 视觉 | 条件 |
|------|------|------|
| 有任务 | 数字 + 完成/总数 | `taskCount > 0` |
| 全部完成 | 数字 + ✅，绿色背景 | `taskCount > 0 && completedCount == taskCount` |
| 休息日 | 数字，「休」标记，灰色 | 在计划范围内但无任务 |
| 今日 | 蓝色边框/高亮 | `date === today` |
| 过去 | 降低透明度 | `date < today` |
| 超出范围 | 数字变极淡 | 不在 `startDate ~ endDate` 之间 |

**功能点**：

1. **月份导航**：左右箭头切换月份，不能早于/晚于计划范围
2. **日历网格**：7列×5~6行，周一开头，补齐前置空白
3. **点击日期**：底部弹出半屏面板（Bottom Sheet），显示该日任务列表 + 支持打卡切换
4. **图例**：底部固定一行
5. **路由**：`/plan/:planId/calendar`，支持 `?month=2026-07`

**组件建议**：拆 `DayCell.vue` 子组件（date/dayNumber/taskCount/completedCount/totalMinutes/isToday/isPast/isInRange）

**计算逻辑**（前端构建月网格）：
```typescript
function buildMonthGrid(year: number, month: number, calendarData: CalendarData) {
  const firstDay = new Date(year, month - 1, 1)
  const lastDay = new Date(year, month, 0)
  const startDow = firstDay.getDay()  // 0=周日
  const startCol = startDow === 0 ? 6 : startDow - 1  // 周一为第0列
  
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

#### 4.5 更新路由配置

**文件**：`src/router/index.ts`

```typescript
{
  path: '/plan/:planId/calendar',
  name: 'planCalendar',
  component: () => import('../views/CalendarView.vue'),
  meta: { requiresAuth: true },
}
```

#### 4.6 计划详情页添加入口

**文件**：`src/views/PlanDetailView.vue`

在页面顶部增加「📅 日历视图」按钮：
```html
<router-link :to="`/plan/${plan.id}/calendar`" class="btn-calendar">
  📅 日历视图
</router-link>
```

---

## 任务依赖

```
4.1 (calendar API) ─┐
                    ├──→ 4.3 (前端 API) ──→ 4.4 (CalendarView)
4.2 (day API) ─────┘                        │
                                             ├──→ 4.5 (路由)
                                             └──→ 4.6 (详情页入口)
```

**执行顺序**：4.1 → 4.2 → 4.3 → 4.4 → 4.5 → 4.6

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

---

## 历史任务
- [x] 早期批次：登录/注册、作业录入、日程配置、计划生成、计划详情
- [x] TASK-001 P0：APK 精简清理
- [x] TASK-002 P0：页面导航重构（今日/作业/计划）— commit `23e67b9`
- [x] UI 交互动画增强 — 全局动画系统（弹簧/平滑/按压/stagger），commit `5d20d82`
