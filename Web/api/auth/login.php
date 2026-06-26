<?php
/**
 * BitHabit API - 用户登录
 * POST /api/auth/login
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';

header('Content-Type: application/json');

// 仅接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 POST 请求', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => '请输入用户名和密码', 'code' => 'MISSING_FIELDS']);
    exit;
}

$conn = getDbConnection();

$stmt = $conn->prepare("SELECT id, username, nickname, password_hash FROM users WHERE username = ?");
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['error' => '用户名或密码错误', 'code' => 'INVALID_CREDENTIALS']);
    exit;
}

// 生成 JWT Token
$token = generateJWT([
    'user_id' => (int)$user['id'],
    'username' => $user['username'],
]);

echo json_encode([
    'userId' => (string)$user['id'],
    'username' => $user['username'],
    'nickname' => $user['nickname'],
    'token' => $token,
]);
