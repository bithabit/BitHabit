# BitHabit - 任务清单

> 最后更新：2026-06-27 11:36 CST  
> 状态：执行中  
> 执行者：Coder Agent  
> 来源：Designer Agent

---

## TASK-003: UI 交互动画增强

**优先级**：P0  
**状态**：⏳ 待执行  
**创建**：2026-06-27

### 需求概述

所有可点击的 UI 元素添加反馈动画：按钮按压、卡片点击、任务打卡、列表项操作等。让应用操作手感更「实」。

### 统一动画规范

| 动画类型 | 参数 | 用途 |
|----------|------|------|
| 按压反馈 | `transform: scale(0.97)` + `transition: 0.15s` | 所有可点击卡片、按钮 |
| 弹簧进入 | `cubic-bezier(0.34, 1.56, 0.64, 1)` | 元素出现时（打卡✅、新卡片） |
| 淡入上移 | `opacity 0→1` + `translateY(8px)→0` | 列表项依次入场 |
| 震动反馈 | 短促 `translateX` ±3px | 删除确认、错误提示 |

### 子任务

---

#### 3.1 全局按钮按压效果

**方式**：在 `style.css` 添加全局 CSS 类，各页面引用。

```css
/* 按压缩放 */
.btn-press {
  transition: transform 0.15s cubic-bezier(0.4, 0, 0.2, 1);
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}
.btn-press:active {
  transform: scale(0.97);
}

/* 可点击卡片 */
.card-press {
  transition: transform 0.15s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.15s ease;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}
.card-press:active {
  transform: scale(0.98);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}
```

**执行范围**：不需要修改每个页面，改为在 `App.vue` 添加全局规则覆盖常见可点击元素。

---

#### 3.2 今日任务页打卡动画

**文件**：`src/views/TodayView.vue`

| 交互 | 当前 | 目标 |
|------|------|------|
| 点击任务卡片切换完成 | 瞬时变化 | ✅ 弹簧弹出 + 卡片微动画 |
| 已完成任务 | 静态灰色 | 添加划线渐变动画 |
| 无任务空状态 | 静态 | 图标轻微浮动 |

**打卡时动画**：
```css
/* ✅ 打卡弹簧动画 */
.task-check-bounce {
  animation: checkBounce 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes checkBounce {
  0% { transform: scale(0); }
  60% { transform: scale(1.3); }
  100% { transform: scale(1); }
}
```

**空状态浮动**：
```css
@keyframes float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-6px); }
}
.empty-icon-float {
  animation: float 3s ease-in-out infinite;
}
```

---

#### 3.3 作业页交互动画

**文件**：`src/views/HomeworkView.vue`

| 交互 | 当前 | 目标 |
|------|------|------|
| 作业卡片出现/消失 | 瞬时 | 淡入上移 / 滑出收缩 |
| 删除确认 | 原生 `confirm()` | 底部弹出确认面板 |
| 表单展开/折叠 | 瞬时 | 高度过渡展开 |

**作业卡片入场**（新添加时）：
```css
.homework-card-enter {
  animation: fadeInUp 0.35s ease both;
}
@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}
```

**表单展开**：给 `.form-section` 加 `transition: all 0.3s ease`

---

#### 3.4 日程配置页动画

**文件**：`src/views/ScheduleView.vue`

| 交互 | 当前 | 目标 |
|------|------|------|
| 日程项添加/删除 | 瞬时 | 淡入上移 / 淡出左移 |
| 添加表单展开 | `<details>` 原生展开 | 高度过渡 + 图标旋转 |
| AI 解析结果出现 | 瞬时 | 依次淡入 |

---

#### 3.5 计划列表页动画

**文件**：`src/views/PlansListView.vue`

| 交互 | 当前 | 目标 |
|------|------|------|
| 计划卡片列表 | 静态 | 依次淡入上移（stagger） |
| 进度条 | 静态 | 宽度过渡 |
| 新建卡片 | 静态 | 按压缩放 |

**进度条已有 transition，无需改动。**

**列表 stagger 动画**：
```css
.plan-card {
  animation: fadeInUp 0.4s ease both;
}
.plan-card:nth-child(1) { animation-delay: 0s; }
.plan-card:nth-child(2) { animation-delay: 0.08s; }
.plan-card:nth-child(3) { animation-delay: 0.16s; }
.plan-card:nth-child(4) { animation-delay: 0.24s; }
.plan-card:nth-child(5) { animation-delay: 0.32s; }
```

---

#### 3.6 计划生成页动画

**文件**：`src/views/PlanCreateView.vue`

| 交互 | 当前 | 目标 |
|------|------|------|
| 生成按钮 | 静态 | 加载时 pulse + 完成后弹簧确认 |
| 预览卡片出现 | 静态 | 淡入上移 |
| 结果卡片 | 静态 | 从下方滑入 |

**生成按钮加载态**（已有 spinner，增强）：
- 按钮文字切换为「生成中...」时加轻微 pulse glow
- 生成成功 → 按钮瞬间变绿色 + ✅ 再恢复

---

#### 3.7 底部导航栏增强

**文件**：`src/App.vue`

| 交互 | 当前 | 目标 |
|------|------|------|
| Tab 切换回弹 | 仅有颜色+指示条 | 图标缩放弹簧 |
| 长按效果 | 无 | 无（触摸目标不需要） |

```css
/* 导航图标点击弹簧 */
.nav-item:active .nav-icon {
  transform: scale(1.2);
  transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1);
}
```

---

### 全局动画 Token（建议加入 `style.css` 的 `:root`）

```css
:root {
  --ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
  --ease-smooth: cubic-bezier(0.4, 0, 0.2, 1);
  --duration-fast: 0.15s;
  --duration-normal: 0.25s;
  --duration-slow: 0.35s;
}
```

---

### 执行要点

1. **先读 `style.css`**，在 `:root` 添加动画 Token
2. **按 3.1 → 3.2 → 3.3 → 3.4 → 3.5 → 3.6 → 3.7 顺序**
3. 每个页面改完后 `vite build` 验证不报错
4. 不改动业务逻辑，只加动画 CSS + 少量 class 绑定
5. 保持移动端性能：只用 `transform` 和 `opacity`，避免 `height/width` 动画

---

## 历史任务
- [x] TASK-001 P0：APK 精简清理
- [x] TASK-002：页面导航重构 — 今日/作业/计划
