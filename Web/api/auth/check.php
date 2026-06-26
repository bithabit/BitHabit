<?php
/**
 * BitHabit API - 检查用户名是否可用
 * GET /api/auth/check?username=xxx
 */

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// 仅接受 GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 GET 请求', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$username = trim($_GET['username'] ?? '');

if (empty($username) || !preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
    http_response_code(400);
    echo json_encode(['error' => '无效的用户名', 'code' => 'INVALID_USERNAME']);
    exit;
}

$conn = getDbConnection();

$stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
$stmt->bind_param('s', $username);
$stmt->execute();
$stmt->store_result();

$available = $stmt->num_rows === 0;
$stmt->close();

echo json_encode(['available' => $available]);
