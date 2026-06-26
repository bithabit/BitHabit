# BitHabit - 任务清单

> 最后更新：2026-06-26 23:25 CST  
> 状态：执行中  
> 执行者：Coder Agent

---

## TASK-001: APK 报毒清理（a.gray.devicemaster.a）

**优先级**：P0  
**状态**：✅ P0 完成（2026-06-26）  
**创建**：2026-06-26

### 问题描述
APK 被国产手机安全软件（华为、腾讯等）检测为 `a.gray.devicemaster.a` 病毒/灰产软件。

### 根因分析
APK 构建时（nitron）将整个项目目录打包进 assets，包括：
- `bithabit-release.keystore`（签名证书）
- 全部 PHP 后端源码（13 个文件）
- `deploy.sh`、`build-twa.cjs` 等脚本
- Debug 证书签名

### P0：精简 APK 内容 ✅

**方案**：创建独立 `apk-src/` 目录，仅包含：
- `index.html`（重定向启动页）
- `icons/icon-192.png`、`icons/icon-512.png`
- `package.json` + `app.js`（nitron 构建必需）

**结果**：
| 指标 | 修复前 | 修复后 |
|------|--------|--------|
| APK 大小 | 269KB | 50KB |
| 打包文件数 | 50+ | 3（assets/） |
| 含 keystore | ❌ 是 | ✅ 否 |
| 含 PHP 源码 | ❌ 是 | ✅ 否 |
| 含 shell 脚本 | ❌ 是 | ✅ 否 |
| 含 Vue 源码 | ❌ 是 | ✅ 否 |

**构建命令**：
```bash
cd /home/node/data/BitHabit/apk-src && \
  JAVA_HOME=/home/node/tools/jre21/jdk-21.0.11+10-jre \
  PATH=$JAVA_HOME/bin:$PATH \
  npx nitron build -p . && \
  cp dist/app.apk /home/node/data/BitHabit/Web/dist/app.apk
```

### P1：APK 加固（待执行）
- [ ] 尝试 360 加固 或 腾讯乐固
- [ ] 加固后重新签名

### P2：向安全厂商申诉（待执行）
- [ ] 向华为/腾讯提交误报申诉

---

## 历史任务（已完成）
- [x] MVP：登录/注册、作业录入、日程配置、计划生成
- [x] PWA 能力补齐
- [x] APK 构建流程
- [x] MIUI 证书白名单兼容
- [x] TASK-001 P0：APK 精简清理
