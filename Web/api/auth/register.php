<?php
/**
 * BitHabit API - 用户注册
 * POST /api/auth/register
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

// 参数校验
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';
$nickname = trim($input['nickname'] ?? '');

$errors = [];

if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
    $errors[] = '用户名必须为 3-20 位字母、数字或下划线';
}

if (strlen($password) < 6) {
    $errors[] = '密码至少 6 位';
}

if (mb_strlen($nickname) > 12) {
    $errors[] = '昵称不超过 12 个字符';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => implode('；', $errors), 'code' => 'VALIDATION_ERROR']);
    exit;
}

// 昵称默认 = 用户名
if (empty($nickname)) {
    $nickname = $username;
}

$conn = getDbConnection();

// 检查用户名是否已被使用（二次确认）
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
$stmt->bind_param('s', $username);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->close();
    http_response_code(409);
    echo json_encode(['error' => '该用户名已被使用', 'code' => 'USERNAME_TAKEN']);
    exit;
}
$stmt->close();

// 创建用户
$passwordHash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $conn->prepare("INSERT INTO users (username, nickname, password_hash) VALUES (?, ?, ?)");
$stmt->bind_param('sss', $username, $nickname, $passwordHash);

if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['error' => '注册失败，请稍后重试', 'code' => 'REGISTER_FAILED']);
    exit;
}

$userId = $stmt->insert_id;
$stmt->close();

// 生成 JWT Token
$token = generateJWT([
    'user_id' => $userId,
    'username' => $username,
]);

http_response_code(201);
echo json_encode([
    'userId' => (string)$userId,
    'username' => $username,
    'nickname' => $nickname,
    'token' => $token,
]);
