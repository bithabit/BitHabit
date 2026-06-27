# BitHabit - 任务清单

> 最后更新：2026-06-27  
> 执行者：Coder Agent  
> 来源：Designer Agent  
> 目标 commit：待定

## ⚠️ 执行规则

**Coder 完成任务后必须通过飞书通道直接通知用户验收，不要通过 sessions_send 通知 Designer。**

---

## 📋 重构概述

**目标**：将数据模型从「作业游离，被计划引用」改为「作业隶属于计划」。

**影响范围**：DB schema + 全部 API + 全部前端页面。几乎全量重构。

**策略**：分 3 个 Phase 执行，每个 Phase 独立可部署。

---

## Phase 1: DB Schema + 后端 API 改造

### TASK-101: homework 表增加 plan_id 字段

**文件**：数据库 + `Web/api/homework.php`

**DB 变更**：
```sql
ALTER TABLE homework ADD COLUMN plan_id INT NOT NULL DEFAULT 0 AFTER user_id;
ALTER TABLE homework ADD INDEX idx_plan (plan_id);
```

**旧数据迁移**：
- 将现有 homework 的 `plan_id` 设为第一个 plan 的 id（从 plan_tasks 反向查找）
- 如果找不到对应 plan，归入 plan_id=0（逻辑上属于被删除的遗留计划，前端可过滤掉）

**homework.php API 变更**：
- GET：增加 `?plan_id=X` 参数（必填），只返回该计划的作业
- POST：增加 `plan_id` 必填字段
- PATCH/DELETE：保持不变（已有权限校验）

### TASK-102: 计划列表 API 扩展

**文件**：`Web/api/plans/list.php`

- 返回每个计划的基本信息 + 作业数量 + 进度
- 增加状态字段：`active` / `completed` / `expired`
- 活跃计划排前面，已完成/过期沉底

**返回格式**：
```json
{
  "plans": [
    {
      "id": 1,
      "name": "暑假作业计划",
      "start_date": "2026-07-01",
      "end_date": "2026-08-31",
      "homework_count": 8,
      "total_tasks": 240,
      "completed_tasks": 45,
      "status": "active",
      "created_at": "2026-06-27T10:00:00Z"
    }
  ]
}
```

### TASK-103: 今日任务 API 扩展 — 多计划合并

**文件**：`Web/api/today.php`

- 查询所有 `status='active'` 的计划
- 对每个活跃计划，查询当前日期的 `plan_tasks`
- 按计划分组返回

**返回格式**：
```json
{
  "date": "2026-07-15",
  "plans": [
    {
      "plan_id": 1,
      "plan_name": "暑假作业计划",
      "tasks": [...]
    },
    {
      "plan_id": 2,
      "plan_name": "数学补习班",
      "tasks": [...]
    }
  ]
}
```

### TASK-104: 计划 CRUD API 补齐

**文件**：`Web/api/plans/create.php` / `update.php` / `delete.php` / `archive.php`

- `POST /api/plans/create.php`：创建计划（name + start_date + end_date + daily_hours）
- `PATCH /api/plans/update.php?id=X`：编辑计划基本信息
- `DELETE /api/plans/delete.php?id=X`：删除计划（级联删除 homework + plan_tasks）
- `PATCH /api/plans/archive.php?id=X`：归档计划（不再出现在今日页）

### TASK-105: generate.php 适配新模型

**文件**：`Web/api/plans/generate.php`

- 入参增加 `plan_id`（必填）
- 查询 `homework WHERE plan_id = ?` 获取该计划的作业池
- 其余逻辑不变（整数分配）

---

## Phase 2: 前端页面重构

### TASK-201: 底部导航改为两栏

**文件**：`Web/src/App.vue`

- 去掉「作业」导航项
- 保留「今日 | 计划」两栏
- 日历通过计划详情页进入

### TASK-202: 计划列表页改造

**文件**：`Web/src/views/PlansListView.vue`

**新布局**：
```
┌──────────────────────────┐
│ 📋 我的计划      [+ 新建] │
├──────────────────────────┤
│ 🔵 活跃                   │
│ ┌──────────────────────┐ │
│ │ 📘 暑假作业计划       │ │
│ │ 7/1 - 8/31 · 8 项作业 │ │
│ │ ████████░░ 45/240    │ │
│ │ [查看] [归档]         │ │
│ └──────────────────────┘ │
│ ┌──────────────────────┐ │
│ │ 📙 数学补习班         │ │
│ │ 7/5 - 7/18 · 3 项作业│ │
│ │ ████░░░░░░ 12/45     │ │
│ │ [查看] [归档]         │ │
│ └──────────────────────┘ │
│                          │
│ 📦 已完成/已过期           │
│ ┌──────────────────────┐ │
│ │ 📕 期末复习计划       │ │
│ │ 1/8 - 1/15 · 已完成  │ │
│ │ [查看]                │ │
│ └──────────────────────┘ │
└──────────────────────────┘
```

- 点击计划卡片 → 进入计划详情页
- 点击「新建」→ 计划创建页
- 点击「归档」→ 调 archive API

### TASK-203: 计划创建页改造 — 融入作业录入

**文件**：`Web/src/views/PlanCreateView.vue`

**新流程**（单页三步）：

```
Step 1: 基本信息
  ┌──────────────────────────┐
  │ 创建新计划                │
  │ 名称：[暑假作业计划____]  │
  │ 起止：[7/1] → [8/31]     │
  │ 每日可用：[8:00] → [22:00]│
  │ 分配策略：○ 平均分配      │
  │         [下一步：录入作业] │
  └──────────────────────────┘

Step 2: 录入作业
  ┌──────────────────────────┐
  │ 暑假作业计划 · 作业录入   │
  │                          │
  │ [手动录入表单区域]        │
  │ 或 [AI 自然语言录入]     │
  │                          │
  │ 已录入 3 条：            │
  │ · 数学 模拟卷 10 套      │
  │ · 英语 阅读理解 20 篇    │
  │ · 语文 读后感 2 篇       │
  │                          │
  │ [+ 继续添加] [生成计划]  │
  └──────────────────────────┘

Step 3: 生成结果 → 跳转计划详情/日历
```

- 创建计划后不立即生成，先录入作业
- 作业录入完点「生成计划」→ 调 generate API
- 生成后跳转到计划详情页

### TASK-204: 计划详情页

**文件**：新建 `Web/src/views/PlanDetailView.vue`

**布局**：
```
┌──────────────────────────┐
│ ← 暑假作业计划            │
├──────────────────────────┤
│ 7/1 - 8/31 · 8 项作业    │
│ ████████░░ 45/240 (19%) │
├──────────────────────────┤
│ 📚 作业列表              │
│ ┌──────────────────────┐ │
│ │ 📐 数学 | 模拟卷      │ │
│ │ 10 套 · 90 分/套  [⋯]│ │
│ │ ██████░░░░ 3/10 30%  │ │
│ └──────────────────────┘ │
│ ┌──────────────────────┐ │
│ │ 🇬🇧 英语 | 阅读理解    │ │
│ │ 20 篇 · 15 分/篇 [⋯]│ │
│ │ ████░░░░░░ 8/20 40%  │ │
│ └──────────────────────┘ │
│                          │
│ [+ 添加作业]              │
├──────────────────────────┤
│ 📅 [查看日历] [重新生成]  │
└──────────────────────────┘
```

**功能**：
- 作业列表 + 进度条（从 HomeworkView 迁移过来）
- ⋯ 菜单：编辑 / 删除（不再有「添加到计划」「重新计划」）
- 「添加作业」按钮 → 行内弹出录入表单
- 「查看日历」→ 跳转 CalendarView（传 plan_id）
- 「重新生成」→ 调 generate API 重新分配（覆盖现有 plan_tasks）
- 作业进度 = `SUM(plan_tasks.amount WHERE homework_id=X AND completed=1) / total_amount`

### TASK-205: 今日任务页适配多计划

**文件**：`Web/src/views/TodayView.vue`

- 按计划分组显示任务
- 每个计划一个折叠区
- 打卡逻辑不变（按 task 打卡）
- 底部统计所有计划合计项数和耗时

### TASK-206: 日历视图适配

**文件**：`Web/src/views/CalendarView.vue`

- 日弹窗内按计划分组显示任务
- 不同计划的任务用不同颜色/标签区分
- 拖拽和移动逻辑不变（TASK-005 规格仍适用）

### TASK-207: 移除独立的 HomeworkView

**文件**：删除或重定向 `Web/src/views/HomeworkView.vue`

- 作业列表改为在计划详情页内展示
- 路由 `/homework` 可重定向到 `/plans` 或移除

---

## Phase 3: 拖拽调整（TASK-005 内容）

Phase 2 稳定后执行 TASK-005 的全部 11 个子任务（规格不变，适配新模型即可）。

---

## 执行顺序

```
Phase 1（后端）：
  101 → 102 → 103 → 104 → 105
  （可部分并行：101+104 同时做，102+103 同时做）

Phase 2（前端）：
  201（导航）→ 202（计划列表）→ 203（创建页）→ 204（详情页）→ 205（今日页）→ 206（日历）→ 207（清理）

Phase 3（拖拽）：
  等 206 稳定后，执行原 TASK-005
```

---

## 影响范围总览

| 层级 | 新增 | 修改 | 删除 |
|------|------|------|------|
| DB | homework.plan_id 列 | — | — |
| API | plans/create/update/delete/archive | homework.php, today.php, plans/list.php, generate.php | — |
| 前端页面 | PlanDetailView | PlansListView, PlanCreateView, TodayView, CalendarView, App.vue | HomeworkView（合并到 PlanDetail） |
| 路由 | /plan/:id | /plan-create, /plans, /today, /calendar | /homework |

---

## 测试要点

- [ ] 创建计划 → 录入作业 → 生成 → 每日任务正确
- [ ] 两个活跃计划的今日任务合并显示，按计划分组
- [ ] 归档计划后今日页不再出现该计划任务
- [ ] 旧数据迁移后作业归属正确
- [ ] 删除计划 → 级联删除该计划的所有作业和任务
- [ ] 计划详情页的作业进度条正确
- [ ] 日历视图区分不同计划的任务
