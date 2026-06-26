<?php
/**
 * BitHabit APK 下载端点
 * 
 * 绕过 Cloudflare 缓存问题：直接输出 APK 并设置正确的 MIME 和缓存头。
 * 用法: https://bithabit.dpdns.org/download-app.php
 */

$apk = __DIR__ . '/dist/app.apk';

if (!file_exists($apk)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'APK 文件不存在，请联系管理员。';
    exit;
}

// 禁止 Cloudflare 缓存
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// 正确的 APK MIME 类型
header('Content-Type: application/vnd.android.package-archive');
header('Content-Length: ' . filesize($apk));
header('Content-Disposition: attachment; filename="BitHabit.apk"');

readfile($apk);
