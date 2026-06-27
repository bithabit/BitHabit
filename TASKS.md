# BitHabit - 任务清单

> 最后更新：2026-06-27 10:33 CST  
> 状态：待执行  
> 执行者：Coder Agent  
> 来源：Designer Agent（导航重构）

---

## TASK-002: 页面导航重构 — 今日/作业/计划

**优先级**：P0  
**状态**：⏳ 待执行  
**创建**：2026-06-27

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
- [x] TASK-001 P0：APK 精简清理
