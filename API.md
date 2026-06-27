# BitHabit - API 文档

> 最后更新：2026-06-27  
> 状态：持续更新  
> 目标读者：Coder Agent

---

## 概述

本文档定义 BitHabit 的前后端 API 接口。设计讨论完成后将作为 Coder 的实现依据。

---

## 模块一：作业计划 API

### 1.0 AI 自然语言解析作业

```
POST /api/ai/parse

Request:
{
  "text": "数学模拟卷3套，英语阅读理解20篇，语文读后感2篇"
}

Response (200):
{
  "tasks": [
    {
      "subject": "数学",
      "type": "模拟卷",
      "totalAmount": 3,
      "unit": "套",
      "timePerUnit": 90,
      "notes": ""
    },
    {
      "subject": "英语",
      "type": "阅读理解",
      "totalAmount": 20,
      "unit": "篇",
      "timePerUnit": 15,
      "notes": ""
    },
    {
      "subject": "语文",
      "type": "读后感",
      "totalAmount": 2,
      "unit": "篇",
      "timePerUnit": 60,
      "notes": ""
    }
  ]
}

Response (400) - 解析失败:
{
  "error": "AI 解析失败，请检查输入或使用手动录入",
  "code": "AI_PARSE_ERROR"
}
```

- 后端 PHP 代理调用 DeepSeek API，API Key 仅存服务端
- 需加频率限制（如每分钟最多 5 次）
- 解析失败降级提示用户手动录入

### 1.0b AI 自然语言解析日程

```
POST /api/ai/parse-schedule

Request:
{
  "text": "我每周一三五早上8点到下午2点有补习班，周日全天休息。7月15到18号要去旅行。"
}

Response (200):
{
  "weekly": [
    { "dayOfWeek": 1, "startTime": "08:00", "endTime": "14:00", "label": "补习班" },
    { "dayOfWeek": 3, "startTime": "08:00", "endTime": "14:00", "label": "补习班" },
    { "dayOfWeek": 5, "startTime": "08:00", "endTime": "14:00", "label": "补习班" },
    { "dayOfWeek": 0, "startTime": null, "endTime": null, "label": "休息日" }
  ],
  "special": [
    { "dateFrom": "2026-07-15", "dateTo": "2026-07-18", "startTime": null, "endTime": null, "label": "旅行" }
  ]
}

Response (400):
{
  "error": "AI 解析失败，请手动添加日程",
  "code": "AI_PARSE_ERROR"
}
```

- 与作业解析共用 DeepSeek API，但 prompt 模板和输出结构不同
- 同样后端代理，Key 不暴露
- 频率限制共享（共 5 次/分钟）

---

## 模块〇之补充：计划列表 & 今日任务 API

> 2026-06-27：任务 #2 导航重构新增

### 1.7 获取计划列表

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

### 1.8 获取今日任务

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

Response (200) - 有计划但今天无任务（休息日/超范围）：
{ "planId": 1, "planName": "暑假作业计划", "date": "2026-07-15", "tasks": [] }
```

- 取用户最新计划（`created_at DESC LIMIT 1`）
- 查 `plan_tasks` 中 `plan_id=? AND date=CURDATE()` 的记录
- JOIN `homework` 获取 `subject, task_type, unit`

### 1.9 切换任务完成状态

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
- 需验证该 task 属于当前用户的计划（防止跨用户操作）

### 1.10 月度日历汇总

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
    { "date": "2026-07-01", "taskCount": 4, "completedCount": 2, "totalMinutes": 120 },
    { "date": "2026-07-05", "taskCount": 0, "completedCount": 0, "totalMinutes": 0 }
  ]
}
```

- `days` 仅包含有数据的日期（taskCount=0 即休息日）
- `startDate`/`endDate` 供前端判断日期是否在计划范围内
- 需验证 plan 属于当前用户

### 1.11 单日任务列表

```
GET /api/plans/day.php?planId=1&date=2026-07-15
Authorization: Bearer <token>

Response (200):
{
  "planId": 1,
  "date": "2026-07-15",
  "tasks": [
    { "id": 42, "subject": "数学", "taskType": "模拟卷", "amount": 0.5, "unit": "套", "estimatedMinutes": 45, "completed": false }
  ]
}
```

- 查 `plan_tasks` JOIN `homework`
- 需验证 plan 属于当前用户

### 1.12 移动任务到指定日期

```
PATCH /api/plans/tasks/move.php
Authorization: Bearer <token>

Request: { "id": 42, "targetDate": "2026-07-16" }
Response (200): { "id": 42, "date": "2026-07-16" }
```

- 验证 task 属于当前用户
- `sort_order` 变为目标日最大值+1（放末尾）

### 1.13 批量重排任务

```
PATCH /api/plans/tasks/reorder.php
Authorization: Bearer <token>

Request: { "date": "2026-07-15", "order": [42, 45, 43, 44] }
Response (200): { "updated": 4 }
```

- 验证所有 task 属于当前用户
- `sort_order` = 数组索引

### 1.14 编辑任务

```
PATCH /api/plans/tasks/update.php
Authorization: Bearer <token>

Request: { "id": 42, "amount": 1, "estimatedMinutes": 90 }
Response (200): { "id": 42, "amount": 1, "estimatedMinutes": 90 }
```

- 部分更新，字段：`amount`, `estimatedMinutes`

### 1.15 撤销移动

```
PATCH /api/plans/tasks/undo-move.php
Authorization: Bearer <token>

Request: { "id": 42, "originalDate": "2026-07-15", "originalOrder": 2 }
Response (200): { "id": 42, "date": "2026-07-15" }

---

## 模块〇：日程配置 API

用户可独立管理自己的日程约束，供计划生成时自动引用。

### 0.5 获取我的日程配置

```
GET /api/schedule
Authorization: Bearer <token>

Response (200):
{
  "weekly": [
    { "id": "w1", "dayOfWeek": 1, "startTime": "08:00", "endTime": "14:00", "label": "补习班" },
    { "id": "w2", "dayOfWeek": 3, "startTime": "08:00", "endTime": "14:00", "label": "补习班" },
    { "id": "w3", "dayOfWeek": 5, "startTime": "08:00", "endTime": "14:00", "label": "补习班" },
    { "id": "w4", "dayOfWeek": 0, "startTime": null, "endTime": null, "label": "休息日" }
  ],
  "special": [
    { "id": "s1", "dateFrom": "2026-07-15", "dateTo": "2026-07-18", "startTime": null, "endTime": null, "label": "旅行" },
    { "id": "s2", "dateFrom": "2026-07-20", "dateTo": null, "startTime": "14:00", "endTime": "18:00", "label": "有事" }
  ]
}
```

- `dayOfWeek`：0=周日, 1=周一, …, 6=周六
- `startTime`/`endTime` 为 null 表示「全天」
- `dateTo` 为 null 表示仅单日

### 0.6 添加每周固定时段

```
POST /api/schedule/weekly
Authorization: Bearer <token>

Request:
{
  "dayOfWeek": 1,
  "startTime": "08:00",
  "endTime": "14:00",
  "label": "补习班"
}

Response (201):
{ "id": "w5" }
```

### 0.7 添加特殊日期

```
POST /api/schedule/special
Authorization: Bearer <token>

Request:
{
  "dateFrom": "2026-07-15",
  "dateTo": "2026-07-18",
  "startTime": null,
  "endTime": null,
  "label": "旅行"
}

Response (201):
{ "id": "s3" }
```

### 0.8 删除日程项

```
DELETE /api/schedule/:id
Authorization: Bearer <token>

Response (200):
{ "deleted": true }
```

### 0.9 更新日程项

```
PATCH /api/schedule/:id
Authorization: Bearer <token>

Request: (同添加接口，部分字段可选)

Response (200):
{ "updated": true }
```

### 1.1 创建作业条目

```
POST /api/tasks

Request:
{
  "subject": "数学",           // 科目
  "type": "练习册",            // 任务类型
  "totalAmount": 60,          // 总量
  "unit": "页",               // 单位
  "timePerUnit": 10,          // 每单位耗时（分钟），可选
  "notes": ""                 // 备注，可选
}

Response:
{
  "id": "task_001",
  "createdAt": "2026-06-26T08:00:00Z"
}
```

### 1.2 设定计划约束

```
POST /api/plans/constraints

Request:
{
  "startDate": "2026-07-01",
  "endDate": "2026-08-30",
  "maxDailyMinutes": 180,
  "restDays": [0],             // 0=周日, 1=周一...
  "blockedDates": [
    { "from": "2026-07-15", "to": "2026-07-18" }
  ],
  "strategy": "smart_weight"   // average | front_heavy | smart_weight
}
```

### 1.3 生成计划

```
POST /api/plans/generate

Response:
{
  "planId": "plan_001",
  "days": [
    {
      "date": "2026-07-01",
      "slots": [
        {
          "taskId": "task_001",
          "subject": "数学",
          "type": "练习册",
          "progress": "第1-2页",
          "amount": 2,
          "unit": "页",
          "estimatedMinutes": 20,
          "timeSlot": "08:00-08:20",
          "completed": false
        }
      ]
    }
  ]
}
```

### 1.4 修改单项任务时间

```
PATCH /api/plans/:planId/days/:date/slots/:slotId

Request:
{
  "timeSlot": "09:00-09:20",   // 修改时间段
  "amount": 3                  // 修改数量
}
```

### 1.5 打卡完成

```
POST /api/plans/:planId/days/:date/slots/:slotId/check

Response:
{
  "completed": true,
  "completedAt": "2026-07-01T08:20:00Z"
}
```

### 1.6 追赶：重新分配未完成任务

```
POST /api/plans/:planId/redistribute

Response:
{
  "unfinishedTasks": 3,
  "daysUpdated": 12,
  "planId": "plan_001"
}
```

---

## 模块三：分配策略 & 生成 API（v3）

> 2026-06-27：v3 重构——三步式（优先级排序 → 节奏约束 → 逐项调整），新增优先级/间隔/集中分配

### 数据库变更（需 Coder 执行）

```sql
ALTER TABLE homework ADD COLUMN plan_id INT NOT NULL AFTER user_id;
ALTER TABLE homework ADD INDEX idx_plan (plan_id);
ALTER TABLE homework ADD COLUMN window_start DATE DEFAULT NULL AFTER time_per_unit;
ALTER TABLE homework ADD COLUMN window_end DATE DEFAULT NULL AFTER window_start;
ALTER TABLE homework ADD COLUMN locked TINYINT(1) DEFAULT 0 AFTER window_end;
ALTER TABLE homework ADD COLUMN priority INT DEFAULT 0 AFTER locked;           -- v3: 优先级（越小越优先）
ALTER TABLE homework ADD COLUMN interval_days INT DEFAULT 0 AFTER priority;   -- v3: 间隔天数（0=每天）
ALTER TABLE homework ADD COLUMN allocated_range VARCHAR(50) DEFAULT NULL AFTER interval_days;  -- v3: 预估时间快照
```

- `priority`：用户拖拽排序的优先级（0 最高，1 次之……）
- `interval_days`：间隔天数，0=每天分配，1=隔天，2=隔两天……
- `allocated_range`：生成时写入的预估日期范围（如 "2026-07-10 ~ 2026-07-20"），只读展示

### 3.1 预览分配（实时折线图数据）

```
POST /api/plans/preview
Authorization: Bearer <token>

Request:
{
  "planId": 1,
  "rhythm": 0,              // -2=极左  -1=偏左  0=均匀  1=偏右  2=极右
  "maxDailyMinutes": 300,
  "targetEndDate": null,    // v3 新增：null=不调整，传日期=压缩/拉伸到该日完成
  "homeworkOverrides": [    // 逐项调整（Step 2/3 传入）
    {
      "homeworkId": 5,
      "windowStart": "2026-07-10",
      "windowEnd": "2026-08-15",
      "intervalDays": 1,    // v3 新增
      "locked": false
    }
  ]
}

Response (200):
{
  "daily": [
    {
      "date": "2026-07-01",
      "totalMinutes": 240,
      "overLimit": false,
      "tasks": [
        {
          "homeworkId": 1,
          "subject": "数学",
          "taskType": "模拟卷",
          "amount": 1,
          "unit": "套",
          "estimatedMinutes": 90
        }
      ]
    },
    ...
  ],
  "allocatedRanges": [       // v3 新增：每项作业的预估日期范围
    { "homeworkId": 1, "range": "2026-07-01 ~ 2026-07-25" },
    { "homeworkId": 5, "range": "2026-08-10 ~ 2026-08-25" }
  ],
  "warnings": []
}
```

- 纯预览，不写入数据库
- **v3 新增 `allocatedRanges`** 字段，返回每项作业被分配的最早和最晚日期
- homeworkOverrides 可选，包含 `intervalDays` 字段
- 使用 v3 多阶段算法：优先级排序 → 间隔过滤 → 集中检测 → 节奏权重 → 顺序分配 → 溢出

### 3.2 确认生成（写入 plan_tasks）

```
POST /api/plans/generate
Authorization: Bearer <token>

Request:
{
  "planId": 1,
  "rhythm": 0,
  "maxDailyMinutes": 300,
  "targetEndDate": null,    // v3 新增
  "homeworkOverrides": [...]
}

Response (201):
{
  "planId": 1,
  "createdTasks": 180,
  "targetEndDate": "2026-08-20",  // v3 新增：实际最后有作业的日期
  "allocatedRanges": [       // v3 新增
    { "homeworkId": 1, "range": "2026-07-01 ~ 2026-07-25" }
  ],
  "warnings": []
}
```

- 与 preview 参数一致，写入 DB
- 生成前删除非锁定/未完成的 plan_tasks
- **生成后写回 allocated_range 到 homework 表**，供 Step 1 排序列表展示

### 3.3 获取作业逐项调整列表（v3 扩展）

```
GET /api/plans/:planId/homework-adjust
Authorization: Bearer <token>

Response (200):
{
  "homework": [
    {
      "id": 5,
      "subject": "语文",
      "taskType": "作文",
      "totalAmount": 5,
      "unit": "篇",
      "timePerUnit": 60,
      "windowStart": null,
      "windowEnd": null,
      "locked": false,
      "priority": 3,          // v3 新增
      "intervalDays": 0,       // v3 新增
      "allocatedRange": null   // v3 新增（上次生成的快照）
    }
  ]
}
```

### 3.4 保存逐项调整（v3 扩展）

```
PUT /api/plans/:planId/homework-adjust
Authorization: Bearer <token>

Request:
{
  "adjustments": [
    {
      "homeworkId": 5,
      "windowStart": "2026-08-01",
      "windowEnd": null,
      "intervalDays": 2,     // v3 新增
      "locked": true
    }
  ]
}

Response (200):
{ "updated": 1 }
```

### 3.5 保存优先级排序（v3 新增）

```
PUT /api/plans/:planId/homework-priority
Authorization: Bearer <token>

Request:
{
  "order": [5, 3, 1, 4, 2]   // homeworkId 数组，按新顺序排列
}

Response (200):
{ "updated": 5 }             // 更新数量
```

- 接收完整排序数组，批量更新 priority 字段
- priority = 数组索引（0 最高优先级）
- Step 1 拖拽排序后调用

### 3.6 获取作业预览时间范围（v3 新增）

```
POST /api/plans/:planId/homework-ranges
Authorization: Bearer <token>

Request:
{
  "rhythm": 0,
  "maxDailyMinutes": 300
  // 不需要传 overrides，直接用当前 homework 表的 priority/interval/window 值
}

Response (200):
{
  "ranges": [
    { "homeworkId": 1, "subject": "物理", "taskType": "模拟卷", "range": "2026-07-01 ~ 2026-07-25" },
    { "homeworkId": 5, "subject": "语文", "taskType": "作文", "range": "2026-08-15 ~ 2026-08-25" }
  ]
}
```

- 轻量预览，仅返回每项作业的预估日期范围（不含每日明细）
- Step 1 排序列表使用，拖拽后刷新预估时间

---

## 通用约定

## 模块零：用户系统 API

### 0.1 注册

```
POST /api/auth/register

Request:
{
  "username": "zhangsan",      // 用户名，3-20位字母数字
  "password": "********",      // 密码，6位以上
  "nickname": "张三"           // 昵称，选填
}

Response (201):
{
  "userId": "u_001",
  "username": "zhangsan",
  "token": "eyJ..."
}
```

### 0.2 登录

```
POST /api/auth/login

Request:
{
  "username": "zhangsan",
  "password": "********"
}

Response (200):
{
  "userId": "u_001",
  "username": "zhangsan",
  "nickname": "张三",
  "token": "eyJ..."
}
```

### 0.3 检查用户名是否存在

```
GET /api/auth/check?username=zhangsan

Response (200) - 可用:
{ "available": true }

Response (200) - 已被使用:
{ "available": false }
```

### 0.4 获取当前用户信息

```
GET /api/auth/me
Authorization: Bearer <token>

Response:
{
  "userId": "u_001",
  "username": "zhangsan",
  "nickname": "张三",
  "createdAt": "2026-06-26T08:00:00Z"
}
```

---

## 通用约定

- 所有时间戳使用 ISO 8601 UTC 格式
- 错误响应格式：`{ "error": "string", "code": "ERROR_CODE" }`
- 认证方式：JWT Bearer Token，登录/注册外所有接口需携带 `Authorization: Bearer <token>`

---

## 待补充

- [ ] 社交/分享 API
- [ ] 统计数据 API
- [ ] 离线同步机制
- [ ] 验证码/防刷机制
