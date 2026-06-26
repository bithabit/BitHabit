# BitHabit - 任务规格文档

> 最后更新：2026-06-26  
> 当前任务：待定（#3 已完成）
> 用途：Designer → Coder 的任务交付文档  
> 每项任务含技术约束、交互规格、API 对应关系

---

## 任务 #3：PWA 能力补齐 — 添加到主屏幕 + 离线体验

**优先级**：P0  
**状态**：✅ 已完成（2026-06-26）
**目标**：补齐 PWA 核心能力，让同学打开链接 → 添加到主屏幕 → 获得原生 APP 般的全屏体验

### 3.1 manifest.json — 添加到主屏幕的核心

在 `public/manifest.json` 创建：

```json
{
  "name": "BitHabit - 暑假作业计划助手",
  "short_name": "BitHabit",
  "description": "帮助学生制定暑假作业计划",
  "start_url": "/",
  "scope": "/",
  "display": "standalone",
  "orientation": "portrait",
  "theme_color": "#4F46E5",
  "background_color": "#FFFFFF",
  "icons": [
    {
      "src": "/assets/icons/icon-192.png",
      "sizes": "192x192",
      "type": "image/png"
    },
    {
      "src": "/assets/icons/icon-512.png",
      "sizes": "512x512",
      "type": "image/png",
      "purpose": "any maskable"
    }
  ]
}
```

然后在 `index.dev.html` 的 `<head>` 中添加：
```html
<link rel="manifest" href="/manifest.json" />
```

**关键字段说明**：
- `display: standalone` → 打开后无浏览器地址栏，全屏显示，像原生 APP
- `theme_color` → 状态栏颜色（Android）
- `orientation: portrait` → 竖屏锁定
- `purpose: maskable` → Android 自适应图标形状

### 3.2 App 图标生成

需生成两个 PNG 图标，放到 `public/assets/icons/`：

| 尺寸 | 文件名 | 用途 |
|------|--------|------|
| 192×192 | icon-192.png | Android 主屏幕 / PWA 通用 |
| 512×512 | icon-512.png | Android Splash Screen / iOS |

**图标内容建议**：
- 主题色 #4F46E5（靛蓝）背景 + 白色 🏗️ 或简约「BH」文字
- 圆角方形（Android 自适应会裁切）
- 留足安全边距（内容区 ≤ 80% 图标大小）

可用 Canvas / SVG 程序化生成，或 Coder 自行设计。

### 3.3 iOS Safari 专用 Meta 标签

在 `index.dev.html` 的 `<head>` 中补充：

```html
<!-- iOS 添加到主屏幕 -->
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
<meta name="apple-mobile-web-app-title" content="BitHabit" />
<link rel="apple-touch-icon" href="/assets/icons/icon-192.png" />
```

**说明**：
- `apple-mobile-web-app-capable` → iOS Safari 的全屏模式（等同 `display: standalone`）
- `black-translucent` → 状态栏半透明，内容延伸到状态栏下方
- `apple-touch-icon` → iOS 主屏幕图标（用 192 的可）

### 3.4 Service Worker — 离线缓存

在 `public/sw.js` 创建：

```js
// sw.js - BitHabit Service Worker
const CACHE_NAME = 'bithabit-v1'
const STATIC_ASSETS = [
  '/',
  '/manifest.json',
  '/favicon.svg',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  // 构建产出的 JS/CSS（由构建脚本自动注入）
]

// 安装：预缓存静态资源
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
  )
  self.skipWaiting()
})

// 激活：清理旧缓存
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  )
  self.clients.claim()
})

// 请求拦截：缓存优先 + 网络兜底
self.addEventListener('fetch', (event) => {
  // 跳过 API 请求（不要缓存 API）
  if (event.request.url.includes('/api/')) return

  event.respondWith(
    caches.match(event.request).then((cached) => {
      return cached || fetch(event.request).then((response) => {
        // 缓存成功的 GET 请求
        if (event.request.method === 'GET' && response.status === 200) {
          const clone = response.clone()
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone))
        }
        return response
      })
    })
  )
})
```

在 `src/main.ts` 中注册：
```ts
// 注册 Service Worker
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js')
  })
}
```

### 3.5 Vite 构建配置适配

由于 `vite.config.ts` 当前使用 `index.dev.html` 作为入口，构建产出在 `dist/`。需要确保：

1. `public/` 下的 `manifest.json`、`sw.js`、`assets/icons/` 在构建时自动复制到 `dist/`（Vite 默认处理）
2. 构建后 `sw.js` 中的 `STATIC_ASSETS` 需要包含构建产出的实际文件名（如 `/assets/index-Deg7chpK.js`）

**方案**：
- `sw.js` 放在 `public/` 下，Vite 不做编译（Service Worker 不能用 ES module import）
- 在 `vite.config.ts` 中通过 Vite 插件，构建后自动替换 `sw.js` 中的资源列表，或使用 Workbox（`vite-plugin-pwa`）

**推荐方案**：使用 `vite-plugin-pwa`（零配置、自动处理 Workbox）：
```bash
npm install -D vite-plugin-pwa
```
```ts
// vite.config.ts
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig({
  plugins: [
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      manifest: {
        name: 'BitHabit - 暑假作业计划助手',
        short_name: 'BitHabit',
        display: 'standalone',
        theme_color: '#4F46E5',
        background_color: '#FFFFFF',
        icons: [
          { src: '/assets/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/assets/icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any maskable' },
        ],
      },
    }),
  ],
})
```
> 如果用 `vite-plugin-pwa`，则步骤 3.1 的 `manifest.json` 改为 `vite.config.ts` 中内联配置，步骤 3.4 的 `sw.js` 由插件自动生成。

### 3.6 验收标准

- [ ] Chrome / Edge Android 打开 `bithabit.dpdns.org` → 地址栏出现「添加到主屏幕」提示
- [ ] 添加后桌面出现 BitHabit 图标（需是 192 图标，不是浏览器默认截图）
- [ ] 点击桌面图标 → 全屏打开（无地址栏、无浏览器 UI）
- [ ] iOS Safari → 分享菜单 → 添加到主屏幕 → 同样全屏
- [ ] 第二次打开时（断网）：页面能正常显示（不白屏），核心 UI 可用
- [ ] Service Worker 注册成功（DevTools → Application → Service Workers）
- [ ] Manifest 可被浏览器识别（DevTools → Application → Manifest）
- [ ] 部署后 `bithabit.dpdns.org` 访问正常

### 3.7 部署须知

- 构建命令：`npm run build`
- 部署到 `/BitHabit/Web/`（覆盖 dist 内容）
- ⚠️ 部署时只覆盖 `dist/` 对应的线上文件，**不要覆盖源码 `index.dev.html`**（见教训 #3）
- 部署完成后，访问 `https://bithabit.dpdns.org` 验证 PWA 能力

### 3.8 分享给同学的实操步骤（完成后告知用户）

1. 用户把链接 `https://bithabit.dpdns.org` 发到微信群/QQ群/私聊
2. 同学用手机浏览器打开（Safari / Chrome / 系统自带浏览器均可）
3. **Android**：浏览器会自动弹出「添加到主屏幕」横幅，点击即可  
   **iOS**：点底部分享按钮 → 「添加到主屏幕」→ 点「添加」
4. 主屏幕出现 BitHabit 图标，点击即全屏打开 🎉

---

---

## 任务 #1：登录/注册系统  ✅ 已完成（2026-06-26）

**优先级**：P0  
**状态**：✅ 已完成 · 4 API 全部通过 · 前端生产部署正常 · 外网可访问
**目标**：实现用户名+密码的注册和登录功能，支持 JWT 自动登录

---

### 1.1 整体流程

```
启动 App → 检查 localStorage token
              ├─ 无 → 登录页
              │        ├─ 输入凭据 → POST /api/auth/login → 首页
              │        └─ 「去注册」 → 注册页
              │                           └─ 注册 → POST /api/auth/register → 自动登录 → 首页
              └─ 有 → 验证 token 有效性
                       ├─ 有效 → 首页
                       └─ 过期 → 清除 token → 登录页
```

### 1.2 登录页 (LoginView.vue)

**UI 元素**：
```
┌──────────────────────────┐
│                          │
│       🏗️ BitHabit        │
│     暑假作业计划助手       │
│                          │
│  ┌────────────────────┐  │
│  │ 👤 用户名           │  │
│  └────────────────────┘  │
│  ┌────────────────────┐  │
│  │ 🔒 密码       [👁]  │  │
│  └────────────────────┘  │
│  ┌────────────────────┐  │
│  │      登  录         │  │
│  └────────────────────┘  │
│     还没有账号？去注册 →   │
└──────────────────────────┘
```

**交互状态表**：

| 状态 | 条件 | 表现 |
|------|------|------|
| 初始 | 用户名密码均为空 | 登录按钮置灰（灰色，不可点击） |
| 可提交 | 用户名 ≥ 3 字符 且 密码 ≥ 1 字符 | 按钮亮色，可点击 |
| 提交中 | 点击登录后 | 按钮文字变为「登录中...」+ loading 动画，按钮 disabled |
| 错误-验证失败 | API 返回 401 | 表单顶部红色提示：「用户名或密码错误」 |
| 错误-网络 | fetch 失败/超时(8s) | 提示：「网络好像不太好，再试试？」 |
| 成功 | API 返回 200 + token | token 存入 localStorage → 跳转首页 |

### 1.3 注册页 (RegisterView.vue)

**UI 元素**：
```
┌──────────────────────────┐
│  ← 返回          注册    │
│                          │
│  ┌────────────────────┐  │
│  │ 😊 昵称（选填）     │  │
│  └────────────────────┘  │
│  ┌────────────────────┐  │
│  │ 👤 用户名 *        │  │
│  └────────────────────┘  │
│  ┌────────────────────┐  │
│  │ 🔒 密码 *          │  │
│  └────────────────────┘  │
│  ┌────────────────────┐  │
│  │ 🔒 确认密码 *       │  │
│  └────────────────────┘  │
│  ┌────────────────────┐  │
│  │      注  册         │  │
│  └────────────────────┘  │
│     已有账号？去登录 →    │
└──────────────────────────┘
```

**校验规则**：

| 字段 | 规则 | 校验时机 |
|------|------|----------|
| 昵称 | 选填，≤ 12 字符；不填默认=用户名 | 提交时 |
| 用户名 | 必填，3-20 字符，仅字母/数字/下划线 | 输入时（debounce 500ms 后调 API 查重） |
| 密码 | 必填，≥ 6 字符 | 输入时 |
| 确认密码 | 必填，与密码一致 | 输入时（实时对比） |

**用户名查重**：`GET /api/auth/check?username=xxx`
- debounce 500ms 后调用
- 输入框下方实时显示：「✅ 可用」或「❌ 已被使用」

**注册提交**：全部校验通过后，`POST /api/auth/register`
- 成功 → 自动登录（直接用返回的 token）→ 首页
- 失败 → 红色提示错误信息

### 1.4 前端技术约束

| 项 | 要求 |
|----|------|
| 框架 | Vue 3 Composition API |
| 路由 | Vue Router，登录页 `/login`，注册页 `/register`，首页 `/` |
| 状态 | Pinia store 管理登录状态（token, userInfo） |
| 存储 | JWT token 存 localStorage key: `bithabit_token` |
| 样式 | 移动端优先，375px 基准 |

### 1.5 路由守卫

```typescript
// router guard 伪代码
router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('bithabit_token')
  if (!token && to.path !== '/login' && to.path !== '/register') {
    next('/login')
  } else if (token && (to.path === '/login' || to.path === '/register')) {
    next('/')
  } else {
    next()
  }
})
```

### 1.6 对应 API

| 功能 | 方法 | 端点 | 详情见 |
|------|------|------|--------|
| 登录 | POST | `/api/auth/login` | API.md 0.2 |
| 注册 | POST | `/api/auth/register` | API.md 0.1 |
| 查重 | GET | `/api/auth/check?username=xxx` | API.md（待补充） |
| 获取用户 | GET | `/api/auth/me` | API.md 0.3 |

### 1.7 后端须知

- PHP 连接 MariaDB 10 必须指定 socket：`/var/run/mariadb10.sock`
- 数据库：`BitHabit`，用户：`Openclaw`
- 密码 hash：使用 `password_hash()` / `password_verify()`
- JWT：自行实现或使用 Firebase/JWT 库
- 密码在数据库字段 `password_hash VARCHAR(255)`

---

---

## 任务 #5：TWA APK 签名升级 + 消除误报

**优先级**：P1
**状态**：📋 待交付
**目标**：将 APK 改为 Google Trusted Web Activity (TWA) 模式，消除国产手机安全软件的「DeviceMaster」误报警告

---

### 5.1 背景

任务 #4 生成的 APK（nitron + debug 签名）被华为/小米/OPPO/vivo 等国产手机安全软件误报为「DeviceMaster」风险应用。实际上是假阳性——APK 仅有 INTERNET 权限，无恶意代码。但同学收到分享后会害怕，影响分发。

TWA 是 Google 官方推荐的 PWA→APK 方案，通过密码学证明「域名和包名属于同一个所有者」，Google Play Protect 识别后不再报毒。

### 5.2 技术方案

```
┌─────────────┐     assetlinks.json      ┌──────────────┐
│ bithabit.   │ ◄────────────────────── ► │ com.bithabit │
│ dpdns.org   │    SHA256 指纹验证         │ .app (APK)   │
└─────────────┘                           └──────────────┘
```

### 5.3 步骤

#### Step 1: 生成 Release Keystore

```bash
# JRE 21 已在 /home/node/tools/jre21/jdk-21.0.11+10-jre/
export JAVA_HOME=/home/node/tools/jre21/jdk-21.0.11+10-jre
export PATH=$JAVA_HOME/bin:$PATH

keytool -genkey -v \
  -keystore /home/node/data/BitHabit/Web/bithabit-release.keystore \
  -alias bithabit \
  -keyalg RSA -keysize 2048 -validity 10000 \
  -storepass <设定密码> -keypass <设定密码> \
  -dname "CN=BitHabit, OU=BitHabit, O=BitHabit, L=Shanghai, ST=Shanghai, C=CN"
```

#### Step 2: 创建 assetlinks.json

在 `Web/public/.well-known/assetlinks.json`（Vite 构建时会复制到 dist/）：

```json
[
  {
    "relation": ["delegate_permission/common.handle_all_urls"],
    "target": {
      "namespace": "android_app",
      "package_name": "com.bithabit.app",
      "sha256_cert_fingerprints": [
        "<RELEASE_CERT_SHA256>"
      ]
    }
  }
]
```

SHA256 指纹获取：
```bash
keytool -list -v -keystore bithabit-release.keystore -alias bithabit | grep SHA256
```

> ⚠️ 必须是**大写且无冒号**格式，如 `AB:CD:...` → `ABCD...`

#### Step 3: 构建 TWA APK

**推荐方案**：修改 nitron 构建产物，或使用 TWA 兼容模板。

AndroidManifest.xml 需要包含：
```xml
<meta-data
  android:name="asset_statements"
  android:resource="@string/asset_statements" />
```

并在 `res/values/strings.xml` 中声明 asset_statements（内容指向 assetlinks.json）。

**备选方案**：如果 nitron 不支持 TWA，可在现有 APK 的 AndroidManifest 中手工注入 TWA meta-data，然后用 release keystore 重新签名。

#### Step 4: 签名 APK

```bash
# 用 release keystore 签名（替换 debug 签名）
jarsigner -verbose -sigalg SHA256withRSA -digestalg SHA-256 \
  -keystore bithabit-release.keystore \
  app.apk bithabit

# zipalign（可选但推荐）
zipalign -v 4 app.apk app-aligned.apk
```

如果 jarsigner 不可用（JRE 不含），用 `apksigner` 或 Android SDK tools。
也可以尝试用 Python 或 Node.js 的 APK 签名工具。

#### Step 5: 部署

1. `assetlinks.json` 部署到 `https://bithabit.dpdns.org/.well-known/assetlinks.json`（需验证 HTTPS 可访问）
2. 替换 `Web/app.apk` 和 `dist/app.apk`
3. 更新 `deploy.sh` 确保 release keystore **不被提交到 git**

### 5.4 验收标准

- [ ] `.well-known/assetlinks.json` 可通过 HTTPS 正常访问（200 + application/json）
- [ ] APK 使用 release keystore 签名（非 debug 签名）
- [ ] 在国产手机（华为/小米）上安装时，不再报「DeviceMaster」病毒
- [ ] 安装后打开，正常加载 bithabit.dpdns.org 并可使用
- [ ] Google Play 的 TWA 验证工具通过（可选：https://play.google.com/store/apps/details?id=com.bithabit.app 的 Digital Asset Links 验证）

### 5.5 约束

- 无 Android SDK、无 Docker、无 root
- JRE 21 路径：`/home/node/tools/jre21/jdk-21.0.11+10-jre/`
- release keystore 密码需妥善保存（写入 NOTES 或 MEMORY.md，不对外公开）
- 如果 keytool/jarsigner 缺失，可下载 JDK（非 JRE）或使用纯 Node.js 签名工具

### 5.6 参考

- Google TWA 文档：https://developers.google.com/web/android/trusted-web-activity
- Digital Asset Links：https://developers.google.com/digital-asset-links
- nitron：https://github.com/ALightbolt4G/nitron

---

## 模板（后续任务使用）

### 任务 #N：任务名称

**优先级**：P0/P1/P2  
**目标**：一句话描述

**UI/交互**：页面草图 / 状态表

**技术约束**：框架、组件、数据流

**API**：涉及的接口

**后端须知**：PHP/DB 特定要求

---

## 任务 #6：APK 下载修复

**优先级**：P0  
**状态**：📋 待交付  
**目标**：修复通过域名下载 APK 的问题，让同学能正常安装

### 6.1 问题

`https://bithabit.dpdns.org/app.apk` 被 Cloudflare 缓存了旧的 HTML 响应（cf-cache-status: HIT，content-type: text/html），导致下载到的是 HTML 而非 APK 二进制。手机安装时报「不兼容」。

本地直连 `http://192.168.1.22:1110/app.apk` 正常（347KB，正确 MIME）。

### 6.2 修复方案

在 `Web/` 根目录新建 `download-app.php`：

```php
<?php
/**
 * APK 下载接口
 * 独立于 index.php，强制 no-cache，解决 Cloudflare 缓存 HTML 的问题
 */
$apk = __DIR__ . '/dist/app.apk';

if (!file_exists($apk)) {
    http_response_code(404);
    echo 'APK not found';
    exit;
}

header('Content-Type: application/vnd.android.package-archive');
header('Content-Length: ' . filesize($apk));
header('Content-Disposition: attachment; filename="BitHabit.apk"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

readfile($apk);
```

### 6.3 验收标准

- [ ] `https://bithabit.dpdns.org/download-app.php` 返回 347KB APK 文件，content-type 正确
- [ ] Cloudflare 响应的 `cf-cache-status` 为 `DYNAMIC` 或 `MISS`（非 HIT）
- [ ] 同时保留 `https://bithabit.dpdns.org/app.apk` 直链（等 Cloudflare 缓存过期自动修复）
- [ ] deploy.sh 不需要额外修改（`download-app.php` 在源码目录即可）

### 6.4 通知

完成后通知 Designer Agent（当前会话）。

---

## 任务 #7：APK 重建 — 消除嵌套套娃

**优先级**：P0  
**状态**：📋 待交付  
**目标**：重建干净 APK（不含嵌套的旧 APK），消除小米安装错误 -124

### 7.1 问题

当前 APK（347KB）内部 `assets/app.apk` 嵌套了一个 192KB 的旧版 APK。
小米安全扫描器检测到嵌套结构（恶意软件常见手法），报解析失败 -124。

根因：nitron 构建时 `dist/app.apk` 已存在，被打进新 APK 里了。

### 7.2 修复步骤

**Step 1:** 删除 dist/app.apk
```bash
rm /home/node/data/BitHabit/Web/dist/app.apk
```

**Step 2:** 重新构建 APK
```bash
cd /home/node/data/BitHabit/Web
npx nitron
```

**Step 3:** 复制 APK 到 dist/
```bash
cp app.apk dist/app.apk
```

**Step 4:** 修改 deploy.sh，在 build 前清理旧 APK

在 `# 1.5. 备份 APK` 段之后加入：
```bash
# 1.6. 清理 dist/ 中的旧 APK（防止 nitron 重建时嵌套）
rm -f dist/app.apk
```

### 7.3 验收
- [ ] 新 APK 大小 < 200KB（无嵌套）
- [ ] 新 APK 内部无 `assets/app.apk`
- [ ] `download-app.php` 返回正确 APK
- [ ] deploy.sh 已修改

### 7.4 通知

完成后通知 Designer Agent。

---

## 任务 #2：作业录入 + 日程配置 + 计划生成

**优先级**：P0  
**状态**：🚧 已交付 Coder  
**目标**：实现作业录入（手动+AI）、日程配置（每周固定+特殊日期+AI）、平均分配计划生成
**不包含**：日历视图、拖拽调整、计划编辑

---

### 2.1 数据库建表

```sql
-- 作业条目
CREATE TABLE homework (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  subject VARCHAR(50) NOT NULL,
  task_type VARCHAR(50) NOT NULL,
  total_amount DECIMAL(10,2) NOT NULL,
  unit VARCHAR(20) NOT NULL,
  time_per_unit INT DEFAULT NULL,  -- 分钟/单位，null 用默认值
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- 每周固定时段
CREATE TABLE schedule_weekly (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  day_of_week TINYINT NOT NULL,  -- 0=周日...6=周六
  start_time TIME DEFAULT NULL,   -- null=全天
  end_time TIME DEFAULT NULL,
  label VARCHAR(50) DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- 特殊日期
CREATE TABLE schedule_special (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  date_from DATE NOT NULL,
  date_to DATE DEFAULT NULL,      -- null=仅单日
  start_time TIME DEFAULT NULL,
  end_time TIME DEFAULT NULL,
  label VARCHAR(50) DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- 计划
CREATE TABLE plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(100) DEFAULT '暑假作业计划',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  daily_start_time TIME DEFAULT '08:00:00',
  daily_end_time TIME DEFAULT '22:00:00',
  strategy VARCHAR(20) DEFAULT 'average',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- 计划中的每日任务
CREATE TABLE plan_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  homework_id INT NOT NULL,
  date DATE NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  estimated_minutes INT NOT NULL,
  sort_order INT DEFAULT 0,
  completed TINYINT(1) DEFAULT 0,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (plan_id) REFERENCES plans(id),
  FOREIGN KEY (homework_id) REFERENCES homework(id)
);
```

---

### 2.2 页面：作业录入页 `/homework`

路由 `/homework`，需登录后访问。

#### 手动录入区

```
┌──────────────────────────────────┐
│  📚 我的作业                  [+  ]│
├──────────────────────────────────┤
│                                  │
│  已录入 (N 条)：                  │
│  ┌────────────────────────────┐  │
│  │ 数学 | 模拟卷 | 3套 | 90分  │  │
│  │ 备注：五年高考三年模拟    [✕]│  │
│  └────────────────────────────┘  │
│  ┌────────────────────────────┐  │
│  │ 英语 | 阅读理解 | 20篇     │  │
│  │                    [✕]     │  │
│  └────────────────────────────┘  │
│                                  │
│  ┌─ 录入新作业 ─────────────────┐ │
│  │ 科目: [数学 ▾]               │ │
│  │ 类型: [模拟卷 ▾]             │ │
│  │ 总量: [___] 单位: [套 ▾]     │ │
│  │ 耗时/单位: [___] 分钟(选填)   │ │
│  │ 备注: [__________________]   │ │
│  │ [继续添加] [保存]            │ │
│  └────────────────────────────┘  │
└──────────────────────────────────┘
```

**科目预设**：数学、英语、语文、物理、化学、生物、历史、地理、政治 + 可自定义输入
**类型预设**：练习册、模拟卷、单词、阅读理解、作文、读后感、背诵、自定义
**单位预设**：页、套、篇、个、章、节、遍 + 可自定义

**默认单位耗时**（用户不填时使用）：

| 类型 | 默认耗时 |
|------|---------|
| 练习册 | 10 分钟/页 |
| 模拟卷 | 90 分钟/套 |
| 单词 | 1 分钟/个 |
| 阅读理解 | 15 分钟/篇 |
| 作文 | 60 分钟/篇 |
| 读后感 | 60 分钟/篇 |
| 背诵 | 20 分钟/篇 |

#### AI 录入区（可折叠）

```
┌─ 🤖 AI 智能录入 ─────────────────┐
│                                  │
│  💬 用自然语言说说你的作业...     │
│  ┌────────────────────────────┐  │
│  │ 数学模拟卷3套，英语阅读     │  │
│  │ 理解20篇，语文读后感2篇     │  │
│  │                            │  │
│  └────────────────────────────┘  │
│  [🔮 AI 解析]                    │
│                                  │
│  --- 解析结果（解析后显示）---    │
│  ✅ 数学 | 模拟卷 | 3套 | 270分   │
│  ✅ 英语 | 阅读理解 | 20篇 | 300分 │
│  ✅ 语文 | 读后感 | 2篇 | 120分   │
│  [全部确认]                      │
└──────────────────────────────────┘
```

**交互**：
- 点击「AI 解析」→ 调 `/api/ai/parse` → 展示结果
- 解析结果可逐条编辑/删除，也可手动补加
- 「全部确认」→ 多条作业同时写入数据库
- 失败降级：提示「解析失败，请手动录入」，保留输入文本

---

### 2.3 页面：日程配置页 `/schedule`

路由 `/schedule`，需登录。

#### 每周固定

```
┌──────────────────────────────────┐
│  📅 每周固定                     │
│                                  │
│  ┌────────────────────────────┐  │
│  │ 周一  8:00 - 14:00  补习班 │  │
│  │                        [✕] │  │
│  └────────────────────────────┘  │
│  ┌────────────────────────────┐  │
│  │ 周日  全天           休息日 │  │
│  │                        [✕] │  │
│  └────────────────────────────┘  │
│                                  │
│  [+ 添加时段]  →  弹出选择器：    │
│  星期: [周一 ▾]                  │
│  □ 全天                          │
│  开始: [08:00]  结束: [14:00]    │
│  标签: [补习班]                  │
│  [添加]                          │
└──────────────────────────────────┘
```

#### 特殊日期

```
┌──────────────────────────────────┐
│  🗓️ 特殊日期                    │
│                                  │
│  ┌────────────────────────────┐  │
│  │ 7/15 - 7/18  全天   旅行   │  │
│  │                        [✕] │  │
│  └────────────────────────────┘  │
│                                  │
│  [+ 添加日期]  →  弹出选择器：    │
│  日期从: [07/15]  到: [07/18]    │
│  □ 全天                          │
│  开始: [14:00]  结束: [18:00]    │
│  标签: [旅行]                    │
│  [添加]                          │
└──────────────────────────────────┘
```

#### AI 录入区（可折叠）

```
┌─ 🤖 AI 智能录入 ─────────────────┐
│  用法同作业录入，调 /api/ai/parse-schedule
└──────────────────────────────────┘
```

---

### 2.4 页面：生成计划 `/plan/create`

路由 `/plan/create`，需登录。

```
┌──────────────────────────────────┐
│  🎯 生成作业计划                  │
│                                  │
│  计划名称: [暑假作业计划        ] │
│                                  │
│  暑假区间:                        │
│  开始: [2026-07-01]              │
│  结束: [2026-08-30]              │
│                                  │
│  每日可用时段:                    │
│  [08:00] - [22:00]              │
│                                  │
│  分配策略:                        │
│  ● 平均分配  ○ 前紧后松(即将到来) │
│                                  │
│  ⚠️ 当前作业：6 条，总计约 980 分钟│
│  ⚠️ 可用天数：约 52 天           │
│                                  │
│  ┌─ 日程配置引用 ───────────────┐ │
│  │ 📋 已应用你的日程：          │ │
│  │ · 每周一/三/五 8:00-14:00   │ │
│  │ · 周日 全天 休息             │ │
│  │ · 7/15-7/18 旅行            │ │
│  │ [修改日程配置 →]            │ │
│  └────────────────────────────┘  │
│                                  │
│  [生成计划]                      │
└──────────────────────────────────┘
```

**生成按钮前置条件**：至少有一条作业记录。
**生成后**：调 `/api/plans/generate`，成功后跳转计划详情页。

---

### 2.5 API 清单

| 功能 | 方法 | 端点 | 详情 |
|------|------|------|------|
| 作业列表 | GET | `/api/homework` | 返回当前用户所有作业 |
| 添加作业 | POST | `/api/homework` | 单条添加 |
| 删除作业 | DELETE | `/api/homework/:id` | |
| 编辑作业 | PATCH | `/api/homework/:id` | |
| AI 解析作业 | POST | `/api/ai/parse` | API.md 1.0 |
| AI 解析日程 | POST | `/api/ai/parse-schedule` | API.md 1.0b |
| 日程-列表 | GET | `/api/schedule` | API.md 0.5 |
| 日程-添加周 | POST | `/api/schedule/weekly` | API.md 0.6 |
| 日程-添加日 | POST | `/api/schedule/special` | API.md 0.7 |
| 日程-删除 | DELETE | `/api/schedule/:id` | API.md 0.8 |
| 日程-编辑 | PATCH | `/api/schedule/:id` | API.md 0.9 |
| 生成计划 | POST | `/api/plans/generate` | API.md 1.3 |
| 计划详情 | GET | `/api/plans/:id` | 返回每日任务列表 |

---

### 2.6 平均分配算法规格

```
输入：
  - 作业列表 (homework[])
  - 计划参数 (startDate, endDate, dailyStart, dailyEnd, schedule[])

输出：
  - plan_tasks[] 每日任务分配表

算法：
1. 总工时 = Σ(每科 total_amount × time_per_unit)
2. 生成日期列表 between(startDate, endDate)
3. 过滤：排除全天休息日、排除全天占用日期
4. 对每日期，计算当日可用分钟 = dailyEnd - dailyStart - 该日部分时段占用
5. 总可用分钟 = Σ 每日可用分钟
6. 如 总工时 > 总可用分钟 → 返回错误
7. 每天配额 = 总工时 / 可用天数（精确到分钟）
8. 按科目轮询分配：
   - 维护每科的剩余量
   - 逐天遍历，每天从各科等比例取量，填入 plan_tasks
   - 每天内的任务顺序 = 按 subject 字母序（稳定）
9. 写入 plan_tasks 表
10. 返回计划 ID
```

**边界情况**：
- 某天可用分钟极小（如仅剩 30 分钟）→ 配额也小，可能分到极少任务
- 部分时段占用导致可用分钟被打散 → v1 不处理打散，累计当天总可用分钟即可

---

### 2.7 DeepSeek API 配置

后端新建文件 `/api/deepseek.php`，封装调用逻辑：

```php
// /api/deepseek.php
// 调用 DeepSeek Chat Completions API
// 被 /api/ai/parse 和 /api/ai/parse-schedule 共用

function callDeepSeek(string $systemPrompt, string $userText): ?array {
    // POST https://api.deepseek.com/chat/completions
    // model: deepseek-chat
    // response_format: { type: "json_object" }
    // 返回解析后的 JSON array
}
```

**频率限制**：同一用户每分钟最多 5 次 AI 调用（作业+日程共享限额）
**降级**：API 超时/失败/返回格式异常 → 返回 400 + `AI_PARSE_ERROR`

---

### 2.8 前端技术约束

| 项 | 要求 |
|----|------|
| 路由 | `/homework` `/schedule` `/plan/create` 三条新路由 |
| 导航 | 首页添加底部导航栏（作业 / 日程 / 计划）或汉堡菜单 |
| 状态 | 作业列表、日程列表用 Pinia store 管理 |
| 样式 | 移动端优先，输入框和按钮大（≥44px 触摸目标） |
| 无网络 | 暂不处理离线；API 错误直接提示 |

---

### 2.9 验收标准

- [ ] 可手动逐条录入作业（至少 6 条不同科目）
- [ ] AI 解析一段自然语言描述 → 正确拆分多条作业
- [ ] AI 解析失败时有友好提示
- [ ] 作业可删除、编辑
- [ ] 每周固定时段可添加/删除
- [ ] 特殊日期可添加/删除
- [ ] AI 解析日程 → 正确识别每周+特殊
- [ ] 生成计划：输入日期范围+策略 → 返回每日任务分配
- [ ] 总工时超出可用时间时返回错误提示
- [ ] 所有 API 需 JWT 认证（除 AI 解析外）
- [ ] 外网 `bithabit.dpdns.org` 可正常访问

