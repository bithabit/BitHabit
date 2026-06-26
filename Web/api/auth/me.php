<?php
/**
 * BitHabit API - 获取当前用户信息
 * GET /api/auth/me
 * Authorization: Bearer <token>
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';

header('Content-Type: application/json');

// 仅接受 GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 GET 请求', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$auth = requireAuth();

$conn = getDbConnection();

$stmt = $conn->prepare("SELECT id, username, nickname, created_at FROM users WHERE id = ?");
$userId = $auth['user_id'];
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => '用户不存在', 'code' => 'USER_NOT_FOUND']);
    exit;
}

echo json_encode([
    'userId' => (string)$user['id'],
    'username' => $user['username'],
    'nickname' => $user['nickname'],
    'createdAt' => $user['created_at'],
]);
