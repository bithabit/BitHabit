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

### 3.2 计划生成算法

核心是**智能权重调度**：

1. 输入：N 个任务 × 各自总量 × 单位耗时 + 可用天数 + 每日时长上限
2. 去除非学习日，得到 M 个有效学习日
3. 对每个任务计算每日基准量 = 总 / M
4. 对每日按权重轮询填充任务槽：
   - 优先填充当天尚未安排的任务类型（多样性）
   - 检查是否超出每日时长上限
   - 交错编排：难题型后跟简单题型
5. 输出 M 天的任务排程 + 时间槽分配

### 3.3 追赶算法（redistribute）

1. 扫描已过日期中 `completed: false` 的任务
2. 汇总未完成量
3. 重新计算剩余天数
4. 按原分配策略重新编排，追加到当前日及之后的计划中

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

## 六、待讨论

- [ ] UI 组件库选择（自研 / Tailwind / Vant）
- [ ] 代码仓库
