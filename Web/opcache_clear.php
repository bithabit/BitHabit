<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo json_encode(['status' => 'ok', 'message' => 'opcache cleared']);
} else {
    echo json_encode(['status' => 'warning', 'message' => 'opcache not enabled']);
}
