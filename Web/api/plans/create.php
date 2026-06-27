<?php
/**
 * BitHabit - 创建计划
 *
 * POST /api/plans/create.php
 * Request: { name, startDate, endDate, dailyStartTime?, dailyEndTime?, strategy? }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';

$user = requireAuth();
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 POST 方法', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$conn = getDbConnection();

$name = trim($input['name'] ?? '未命名计划');
$startDate = $input['startDate'] ?? $input['start_date'] ?? '';
$endDate = $input['endDate'] ?? $input['end_date'] ?? '';
$dailyStartTime = $input['dailyStartTime'] ?? $input['daily_start_time'] ?? '08:00:00';
$dailyEndTime = $input['dailyEndTime'] ?? $input['daily_end_time'] ?? '22:00:00';
$strategy = $input['strategy'] ?? 'average';

if (empty($startDate) || empty($endDate)) {
    http_response_code(400);
    echo json_encode(['error' => '开始和结束日期为必填', 'code' => 'INVALID_INPUT']);
    exit;
}

if (strlen($name) > 100) {
    http_response_code(400);
    echo json_encode(['error' => '名称过长', 'code' => 'INVALID_INPUT']);
    exit;
}

$stmt = $conn->prepare(
    'INSERT INTO plans (user_id, name, start_date, end_date, daily_start_time, daily_end_time, strategy) 
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param(
    'issssss',
    $userId, $name, $startDate, $endDate,
    $dailyStartTime, $dailyEndTime, $strategy
);

if ($stmt->execute()) {
    http_response_code(201);
    echo json_encode([
        'id' => $stmt->insert_id,
        'name' => $name,
        'startDate' => $startDate,
        'endDate' => $endDate,
    ], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode(['error' => '创建计划失败', 'code' => 'DB_ERROR']);
}
