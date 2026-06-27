# TASKS.md — Coder 任务清单

> 创建：2026-06-27 · Designer → Coder · BitHabit 项目

---

## TASK-010：折线图动画增强

**概述**：在 PlanAllocateView 的柱状图 SVG 上增加动画效果，提升交互体验。

**文件**：`Web/src/views/PlanAllocateView.vue`

### 改动点

#### 1. CSS 动画 Token 复用
项目 `style.css` 已有：
```css
--ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
--ease-smooth: cubic-bezier(0.4, 0, 0.2, 1);
```
`PlanAllocateView.vue` 直接引用即可。

#### 2. `<template>` 修改
给 SVG 中的 `<rect>` 增加 `class="bar-rect"`：

```html
<!-- 原 -->
<rect v-for="(bar, i) in bars" :key="i"
  :x="bar.x" :y="bar.y" :width="bar.w" :height="bar.h"
  :fill="bar.over ? '#ef4444' : '#4F46E5'" rx="2" opacity="0.8">

<!-- 改 -->
<rect v-for="(bar, i) in stagedBars" :key="i"
  :x="bar.x" :y="bar.y" :width="bar.w" :height="bar.h"
  :fill="bar.over ? '#ef4444' : '#4F46E5'" rx="2" opacity="0.8"
  class="bar-rect">
```

> 注意：数据源从 `bars` 改为 `stagedBars`，见第 3 点。

#### 3. `<style scoped>` 新增

```css
rect.bar-rect {
  transition: y 0.4s var(--ease-spring),
              height 0.4s var(--ease-spring),
              fill 0.3s var(--ease-smooth);
}
```

#### 4. `<script>` 入场 Stagger 逻辑

新增 `stagedBars` ref，实现首帧柱高为 0 → 下一帧弹起：

```ts
const stagedBars = ref<typeof bars.value>([])
let firstLoad = true

// 在 refreshPreview() 完成后：
// 首次加载 → stagedBars 先设空（柱高全 0），再 requestAnimationFrame 赋真值
// 后续更新 → 直接赋值
const updateStagedBars = () => {
  const target = bars.value
  if (firstLoad && target.length > 0) {
    stagedBars.value = target.map(b => ({ ...b, y: chartH, h: 0 }))
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        stagedBars.value = [...target]
        firstLoad = false
      })
    })
  } else {
    stagedBars.value = [...target]
  }
}
```

在 `refreshPreview()` 的 API 返回后调用 `updateStagedBars()`。

#### 5. miniBars 同样加 transition（可选）

Step 2 的缩小版折线图同理，给 rect 加 `class="bar-rect"` 即可获得值过渡动画（无需 stagger）。

### 验收标准

- [ ] 页面首次进入 → 柱子从左到右逐个弹出（弹簧感）
- [ ] 拖动节奏滑块 → 柱子平滑迁移到新位置，无跳跃
- [ ] 修改每日上限 → 虚线移动 + 柱子同步调整
- [ ] 超限柱子从蓝变红有渐变过渡
- [ ] 切换预设按钮 → 柱子平滑过渡
- [ ] Step 2 缩小版折线图同步有过渡动画
- [ ] 无控制台错误、无布局跳动

### 备注

- 动画实现完全用 CSS transition，不需要 JS requestAnimationFrame（除入场的单次 stagger）
- CSS transition 属性浏览器原生支持 SVG rect 的 x/y/width/height/fill
- 改动量小，约 30 行新增代码

---

> 完成 TASK-010 后直接回复用户告知结果，无需经过 Designer 转发。
