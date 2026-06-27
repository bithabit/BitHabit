# BitHabit - 技术说明文档

> 最后更新：2026-06-26  
> 状态：设计中 · 持续更新  
> 目标读者：Coder Agent

---

## 一、项目性质

- **类型**：PWA（Progressive Web App）
- **平台**：移动端优先，桌面端兼容
- **离线**：Service Worker + IndexedDB，必选能力

---

## 二、技术栈

### 前端

| 项目 | 选择 |
|------|------|
| 框架 | Vue 3 + TypeScript |
| UI | 待定（自研 / Tailwind CSS / Vant） |
| 状态管理 | Pinia（Vue 3 官方推荐） |
| 路由 | Vue Router |
| 打包 | Vite |

### 后端

| 项目 | 选择 |
|------|------|
| 语言 | PHP（原生，不走框架） |
| 数据库 | MariaDB @ QNAP NAS |
| 部署 | QNAP 新虚拟主机 |
| 认证 | JWT Token（用户名+密码注册/登录） |
| API 风格 | RESTful JSON |

### PWA 能力
- Service Worker（资源缓存 + 离线请求拦截）
- IndexedDB（本地数据存储）
- Web App Manifest（添加到主屏幕）
- Background Sync（离线数据同步，可选）



---

## 三、关键技术要点

### 3.1 离线架构

```
┌─────────────┐     联网      ┌──────────┐
│  前端 PWA   │ ◄──────────► │  后端API  │
│             │              │          │
│ IndexedDB ──┤  自动同步    │  数据库   │
│ (本地存储)  │              │          │
└─────────────┘              └──────────┘
```

- 所有写入操作先写 IndexedDB，后台通过 Background Sync 推送到服务器
- 读取操作优先从 IndexedDB 返回（秒开），后台静默拉取最新数据

### 3.2 计划生成算法（v2）

#### 3.2.1 节奏权重曲线

节奏偏好值 `rhythm ∈ [-2, -1, 0, 1, 2]` 决定每天的分配权重。

核心思路：生成一条权重曲线 w(t)，t∈[0,1]，使得：
- rhythm=-2（极左/前紧后松）：w(t) 早期大后期小，如 w(t) = 1 - 0.7t
- rhythm=0（均匀）：w(t) = 1（常值）
- rhythm=2（极右/前松后紧）：w(t) 早期小后期大，如 w(t) = 0.3 + 0.7t

对每一天 i（1..N），权重 w_i = w(i/N)。

#### 3.2.2 分配步骤

1. 计算可用天数 N = 起止日期内 - 日程约束占用天数 - 休息日
2. 按节奏偏好生成每日权重 w₁, w₂, ..., wₙ
3. 对每项作业：
   - 有时间窗口约束 [start, end]：只在窗口内的天数分配
   - 总权重 = Σ w_i（仅窗口内天数）
   - 第 i 天分配量 = round(totalAmount × w_i / 总权重)，整数取整
   - 余数按权重从大到小排列，依次多分配 1 单位
4. 每天总工时 ≤ 每日上限
   - 超限时：优先将超出的任务挪到相邻有空容量的日期
   - 所有天都满时：记录警告
5. 锁定的 task 跳过，不重新分配

### 3.3 追赶算法（redistribute）

1. 扫描已过日期中 `completed: false` 的任务
2. 汇总未完成量（按 homework 分组）
3. 重新计算剩余天数
4. 按当前节奏偏好 + 时间窗口约束重新编排，追加到今天及之后的日期
5. 锁定的 task 保留不动

---

## 四、PWA 最小配置清单

```
bitHabit/
├── index.html
├── manifest.json          ← PWA 核心：图标、名称、主题色
├── sw.js                  ← Service Worker：缓存 + 离线
├── assets/
│   └── icons/             ← 至少 192x192 + 512x512
├── src/
│   ├── app.js             ← 主应用
│   ├── db.js              ← IndexedDB 封装
│   ├── scheduler.js       ← 计划生成算法
│   └── ...
└── css/
    └── style.css
```

---

## 五、部署架构

### 5.1 对外访问方案

| 项目 | 方案 |
|------|------|
| 域名 | bithabit.dpdns.org |
| 隧道 | Cloudflare Tunnel（Docker 运行在 QNAP 上） |
| 虚拟主机 | QNAP Web 服务器，端口 1110 |
| HTTPS | Cloudflare 自动提供，无需自签证书 |
| 代码目录 | /BitHabit/Web/ |

### 5.2 架构图

```
Internet                          QNAP NAS (局域网)
┌──────────┐      HTTPS      ┌─────────────────────────┐
│ 同学手机  │ ──────────────► │  Cloudflare Tunnel       │
│ 浏览器    │                 │  (Docker)               │
└──────────┘                 │         │               │
                             │    localhost:1110        │
                             │         ▼               │
                             │  Apache/Nginx 虚拟主机   │
                             │  /BitHabit/Web/          │
                             │    ├── index.html        │
                             │    ├── api/ (PHP)        │
                             │    └── assets/           │
                             │         │               │
                             │    MariaDB (3306)        │
                             └─────────────────────────┘
```

### 5.3 MariaDB 连接配置

QNAP 上有两个 MySQL 实例，BitHabit 用的是 MariaDB 10：

| 实例 | 用途 | Socket 路径 |
|------|------|------------|
| MariaDB 5 (Server 1) | QNAP 系统 | `/tmp/mysql.sock` |
| MariaDB 10 (Server 2) | BitHabit | `/var/run/mariadb10.sock` |

PHP 连接时必须指定 socket：
```php
$conn = mysqli_connect("localhost", "Openclaw", "vK3*eR7/", "BitHabit", null, "/var/run/mariadb10.sock");
```
> ⚠️ 不指定 socket 会默认走 `/tmp/mysql.sock`（MariaDB 5），导致连接失败。

### 5.4 注意事项
- PHP API 和前端静态资源部署在同一个虚拟主机下，同源访问，无跨域问题
- Tunnel 由 Cloudflare 维护，QNAP 重启后 Docker 需自动启动 cloudflared 容器
- 用户名密码目前存储在代码中（后续应移到配置文件）

---

## 六、APK / TWA 构建

### 6.1 概述

BitHabit 提供两种安装方式：
1. **PWA 添加到主屏幕**：浏览器打开 → 添加到主屏幕 → 全屏体验
2. **APK 安装**：下载 APK 直接安装（解决 PWA 在国内浏览器支持差的问题）

APK 基于 nitron 工具构建，使用 Android WebView 加载应用。

### 6.2 构建流程

```bash
# 1. 确保 index.html（重定向页）存在于项目根目录
# 2. 确保 app.js（nitron 配置）存在
# 3. 移除旧的 app.apk（防止嵌套打包）
rm -f app.apk dist/app.apk

# 4. 运行 nitron 构建（使用 uber-apk-signer 内置 Debug 证书）
JAVA_HOME=/home/node/tools/jre21/jdk-21.0.11+10-jre \
PATH=$JAVA_HOME/bin:$PATH \
node /path/to/nitron/dist/cli.js build -p /home/node/data/BitHabit/Web

# 5. 复制输出
cp dist/app.apk app.apk
```

### 6.3 app.js 配置

```js
import { app } from 'nitron'

app.init({
  name: 'BitHabit',
  packageId: 'com.bithabit.app',
  version: '1.0.1',
  entry: 'index.html',
  orientation: 'portrait',
  permissions: ['INTERNET'],
  icon: 'dist/assets/icons/icon-512.png',
})
```

### 6.4 关键设计：重定向启动页

APK 中的 `index.html` 是一个轻量重定向页面，打开后立即跳转到线上 HTTPS 地址：

```html
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BitHabit</title>
  <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#4F46E5;color:#fff}</style>
</head>
<body>
  <p>正在加载 BitHabit...</p>
  <script>window.location.replace('https://bithabit.dpdns.org')</script>
</body>
</html>
```

**为什么不直接打包 dist/ 文件？**
- Vite 构建产出是 ES Module（`<script type="module">`）
- Android WebView 加载 `file://` 时，CORS 策略**禁止执行 ES Module**
- 导致 JS 完全不运行 → 白屏
- 解决：跳转到线上 HTTPS，WebView 正常加载

### 6.5 下载接口

```php
// download-app.php — 独立于 index.php
// 强制 no-cache，解决 Cloudflare 缓存旧版 APK 的问题
header('Content-Type: application/vnd.android.package-archive');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
readfile(__DIR__ . '/dist/app.apk');
```

> ⚠️ 不要用 `/app.apk` 直链——Cloudflare 会缓存（cf-cache-status: HIT），用户下载到旧版。
> 始终用 `/download-app.php`（cf-cache-status: DYNAMIC）。

---

## 七、已知踩坑记录

### 7.1 ES Module + file:// 白屏

| 项 | 详情 |
|----|------|
| 现象 | APK 安装成功，打开后白屏（无任何内容） |
| 根因 | WebView 加载 `file:///android_asset/index.html`，HTML 中的 `<script type="module">` 被 CORS 策略阻止执行 |
| 修复 | APK 内 index.html 改为重定向页，跳转到线上 HTTPS 地址 |
| 影响 | 需要网络连接才能使用（可接受，PWA 本身就需要联网加载） |

### 7.2 MIUI 证书白名单

| 项 | 详情 |
|----|------|
| 现象 | 国产手机（MIUI）安装 APK 时报「不兼容」 |
| 根因 | MIUI 对 APK 签名证书有白名单机制，只认标准 Debug 证书 |
| 关键发现 | uber-apk-signer 内置的 Debug 证书（`CN=Android Debug, OU=Android, O=US...`）在白名单内 |
| 修复 | 必须使用 **uber-apk-signer 的默认 Debug 签名**，不能用 keytool 手动生成的证书 |
| 教训 | 不要自己生成 keystore，让 uber-apk-signer 自动签名即可 |

### 7.3 APK 嵌套打包

| 项 | 详情 |
|----|------|
| 现象 | APK 内部 `assets/app.apk` 包含旧版 APK |
| 根因 | 构建时项目根目录有 `app.apk`，被 nitron 当作项目文件打包进去 |
| 修复 | 构建前 `rm -f app.apk dist/app.apk` |

### 7.4 Cloudflare 缓存旧版 APK

| 项 | 详情 |
|----|------|
| 现象 | `/app.apk` 直链下载到旧版本 |
| 根因 | Cloudflare 缓存了旧响应（cf-cache-status: HIT） |
| 修复 | 用 `download-app.php` 提供下载（强制 no-cache），不要依赖 `/app.apk` 直链 |

### 7.5 build-twa.cjs 不可用

`build-twa.cjs` 是手动 APK 构建脚本，已废弃。问题：
- 生成的 APK 结构不被 MIUI 接受
- 签名证书不是 uber-apk-signer 原生的
- 请直接使用 nitron 构建（见 6.2）

---

## 八、待讨论

- [ ] UI 组件库选择（自研 / Tailwind / Vant）
- [ ] 代码仓库
