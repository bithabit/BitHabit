<?php
/**
 * BitHabit 前端控制器
 * 
 * 由于 QNAP 虚拟主机未启用 AllowOverride，.htaccess 不生效，
 * 此文件作为 Apache DirectoryIndex 的入口，负责：
 * 1. API 路由映射（/api/xxx → api/xxx.php）
 * 2. 静态文件从 dist/ 映射到根路径（manifest.json、sw.js、图标等）
 * 3. SPA fallback（所有页面路由 → dist/index.html）
 */



$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ── API 路由 ──────────────────────────────────────────
// /api/xxx → api/xxx.php
if (preg_match('#^/api/(.+)$#', $uri, $matches)) {
    $apiFile = __DIR__ . '/api/' . $matches[1] . '.php';
    if (file_exists($apiFile)) {
        require $apiFile;
        exit;
    }
    // API 文件不存在
    http_response_code(404);
    echo json_encode(['error' => 'API endpoint not found']);
    exit;
}

// ── 静态文件从 dist/ 映射到根路径 ───────────────────
// dist/ 中的文件可直接通过根路径访问，例如：
//   /manifest.webmanifest → dist/manifest.webmanifest
//   /sw.js               → dist/sw.js
//   /assets/icons/xxx    → dist/assets/icons/xxx
$distFile = __DIR__ . '/dist' . $uri;
if (file_exists($distFile) && is_file($distFile)) {
    $ext = strtolower(pathinfo($distFile, PATHINFO_EXTENSION));
    $mimeTypes = [
        'json'          => 'application/json',
        'webmanifest'   => 'application/manifest+json',
        'js'            => 'application/javascript',
        'css'           => 'text/css',
        'png'           => 'image/png',
        'svg'           => 'image/svg+xml',
        'ico'           => 'image/x-icon',
        'html'          => 'text/html',
        'woff'          => 'font/woff',
        'woff2'         => 'font/woff2',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext] . '; charset=utf-8');
    }
    // 静态资源缓存 1 小时
    header('Cache-Control: public, max-age=3600');
    readfile($distFile);
    exit;
}

// ── SPA fallback ──────────────────────────────────────
// 所有非 API、非静态文件请求 → 输出生产构建的 index.html
$distIndex = __DIR__ . '/dist/index.html';
if (!file_exists($distIndex)) {
    $distIndex = __DIR__ . '/dist/index.dev.html';
}
if (file_exists($distIndex)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($distIndex);
} else {
    // 降级：使用开发版 index.html
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.dev.html');
}
