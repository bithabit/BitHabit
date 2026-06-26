#!/bin/bash
# BitHabit 部署脚本
# 用法: bash deploy.sh
#
# 流程:
#   1. npm run build (Vite 读取 index.dev.html → 输出到 dist/)
#   2. 归一化 HTML 输出名（dist/index.dev.html → dist/index.html）
#   3. 复制 dist/assets/* → assets/ (含 icons/ 子目录)
#   4. 复制 PWA 文件到根目录（Apache 直接 serve，不经过 index.php）
#   5. index.php 作为前端控制器 (API路由 + SPA fallback)
#
# ⚠️ 重要:
# - index.dev.html = Vite 构建入口 (vite.config.ts 已配置)
# - index.php = 前端控制器 (API路由 + SPA fallback)
# - PWA 文件必须放在根目录（QNAP AllowOverride=None，无 URL 重写）

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

echo "📦 BitHabit 部署开始..."
echo ""

# 1. 确保 Vite 构建入口 index.dev.html 存在
if [ ! -f index.dev.html ]; then
    echo "❌ 缺少 index.dev.html（Vite 构建入口）!"
    exit 1
fi
if ! grep -q 'src/main.ts' index.dev.html; then
    echo "❌ index.dev.html 不是有效的 Vite 入口!"
    exit 1
fi

# 2. 构建 (Vite 读取 index.dev.html 作为入口)
# ⚠️ 必须设置 NODE_ENV=development，否则 npm 跳过 devDependencies
echo "🔨 构建前端..."
NODE_ENV=development npm run build

# 3. 归一化 HTML 输出名（Vite 使用 index.dev.html 入口 → 输出 dist/index.dev.html）
if [ -f dist/index.dev.html ] && [ ! -f dist/index.html ]; then
    mv dist/index.dev.html dist/index.html
fi

# 4. 部署静态资源到 Apache 可访问的根目录
echo "📋 复制静态资源..."
rm -rf assets/
mkdir -p assets/
cp -r dist/assets/* assets/
cp dist/favicon.svg assets/ 2>/dev/null || true

# 5. 复制 PWA 文件到根目录（Apache 直接 serve，不经过 index.php）
echo "📋 复制 PWA 文件到根目录..."
cp dist/manifest.webmanifest . 2>/dev/null || true
cp dist/sw.js . 2>/dev/null || true
cp dist/registerSW.js . 2>/dev/null || true
cp dist/workbox-*.js . 2>/dev/null || true

# 6. 清理旧的 workbox 文件（避免累积）
for old_wb in workbox-*.js; do
    if [ ! -f "dist/$old_wb" ]; then
        rm -f "$old_wb"
    fi
done

# 7. 输出清单（供验证）
echo ""
echo "📁 根目录 PWA 文件:"
ls -la manifest.webmanifest sw.js registerSW.js workbox-*.js 2>/dev/null || echo "  (无)"
echo ""
echo "📁 assets/ 内容 ($(find assets/ -type f | wc -l) 个文件):"
ls -la assets/ | grep -v '^total' | head -20
echo ""

# 8. 快速验证
echo "✅ 部署完成！"
echo "   前端控制器: index.php"
echo "   生产入口: dist/index.html (由 index.php serve)"
echo "   PWA: manifest + SW + 图标 全部就绪"
echo ""
echo "🧪 验证:"
echo "   curl -so /dev/null -w '%{http_code}' http://192.168.1.22:1110/"
echo "   curl -s http://192.168.1.22:1110/manifest.webmanifest | head -3"
