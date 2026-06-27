<?php
/**
 * BitHabit - 编辑计划基本信息
 *
 * PATCH /api/plans/update.php?id=X
 * Request: { name?, startDate?, endDate?, dailyStartTime?, dailyEndTime? }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';

$user = requireAuth();
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 PATCH 方法', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$planId = (int)($_GET['id'] ?? 0);
if ($planId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => '缺少计划 id', 'code' => 'INVALID_INPUT']);
    exit;
}

$conn = getDbConnection();

$stmt = $conn->prepare('SELECT id FROM plans WHERE id = ? AND user_id = ?');
$stmt->bind_param('ii', $planId, $userId);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => '计划不存在', 'code' => 'NOT_FOUND']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$updates = [];
$params = [];
$types = '';

$fieldMap = [
    'name' => 's',
    'startDate' => ['field' => 'start_date', 'type' => 's'],
    'endDate' => ['field' => 'end_date', 'type' => 's'],
    'dailyStartTime' => ['field' => 'daily_start_time', 'type' => 's'],
    'dailyEndTime' => ['field' => 'daily_end_time', 'type' => 's'],
];

foreach ($fieldMap as $key => $def) {
    $field = is_array($def) ? $def['field'] : $key;
    $type = is_array($def) ? $def['type'] : 's';
    $val = $input[$key] ?? null;
    if ($val !== null && $val !== '') {
        $updates[] = "$field = ?";
        $params[] = $val;
        $types .= $type;
    }
}

if (empty($updates)) {
    http_response_code(400);
    echo json_encode(['error' => '没有要更新的字段', 'code' => 'NO_UPDATES']);
    exit;
}

$params[] = $planId;
$params[] = $userId;
$types .= 'ii';

$sql = 'UPDATE plans SET ' . implode(', ', $updates) . ' WHERE id = ? AND user_id = ?';
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    echo json_encode(['updated' => true], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode(['error' => '更新失败', 'code' => 'DB_ERROR']);
}
