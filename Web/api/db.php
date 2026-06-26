<?php
/**
 * BitHabit - 数据库连接
 * 
 * MariaDB 10 通过 Unix Socket 连接
 * ⚠️ 必须指定 socket 路径，否则会连到 MariaDB 5
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'Openclaw');
define('DB_PASS', 'vK3*eR7/');
define('DB_NAME', 'BitHabit');
define('DB_SOCKET', '/var/run/mariadb10.sock');

function getDbConnection(): mysqli {
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, null, DB_SOCKET);
    
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['error' => '数据库连接失败', 'code' => 'DB_CONNECT_ERROR']);
        exit;
    }
    
    $conn->set_charset('utf8mb4');
    return $conn;
}
