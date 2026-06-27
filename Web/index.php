<?php
/**
 * BitHabit 前端控制器
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ── API 路由 ──
if (preg_match('#^/api/(.+)$#', $uri, $matches)) {
    $apiFile = __DIR__ . '/api/' . $matches[1] . '.php';
    if (file_exists($apiFile)) { require $apiFile; exit; }
    http_response_code(404);
    echo json_encode(['error' => 'API endpoint not found']);
    exit;
}

// ── 静态文件 ──
$distFile = __DIR__ . '/dist' . $uri;
if (file_exists($distFile) && is_file($distFile)) {
    $ext = strtolower(pathinfo($distFile, PATHINFO_EXTENSION));
    $mimeTypes = [
        'json' => 'application/json', 'webmanifest' => 'application/manifest+json',
        'js' => 'application/javascript', 'css' => 'text/css',
        'png' => 'image/png', 'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon', 'html' => 'text/html',
        'woff' => 'font/woff', 'woff2' => 'font/woff2',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext] . '; charset=utf-8');
    }
    header('Cache-Control: public, max-age=3600');
    readfile($distFile);
    exit;
}

// ── SPA fallback ──
$distIndex = __DIR__ . '/dist/index.html';
if (!file_exists($distIndex)) $distIndex = __DIR__ . '/dist/index.dev.html';
if (file_exists($distIndex)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($distIndex);
} else {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.dev.html');
}
