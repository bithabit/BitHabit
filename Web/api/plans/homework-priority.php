<?php
/**
 * BitHabit - 保存作业优先级排序 (v3)
 *
 * PUT /api/plans/homework-priority.php?planId=X
 *
 * 接收 order 数组，按数组索引更新 priority 字段
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';

$user = requireAuth();
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 PUT', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$conn = getDbConnection();

$planId = (int)($_GET['planId'] ?? 0);
if ($planId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => '缺少 planId', 'code' => 'INVALID_INPUT']);
    exit;
}

// 验证计划归属
$planCheck = $conn->prepare('SELECT id FROM plans WHERE id = ? AND user_id = ?');
$planCheck->bind_param('ii', $planId, $userId);
$planCheck->execute();
if ($planCheck->get_result()->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => '计划不存在', 'code' => 'NOT_FOUND']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$order = $input['order'] ?? [];

if (empty($order)) {
    http_response_code(400);
    echo json_encode(['error' => '缺少 order 数组', 'code' => 'INVALID_INPUT']);
    exit;
}

$updated = 0;
$stmt = $conn->prepare('UPDATE homework SET priority = ? WHERE id = ? AND plan_id = ? AND user_id = ?');

foreach ($order as $index => $homeworkId) {
    $priority = (int)$index;
    $hwId = (int)$homeworkId;
    if ($hwId <= 0) continue;
    $stmt->bind_param('iiii', $priority, $hwId, $planId, $userId);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $updated++;
    }
}

echo json_encode(['updated' => $updated], JSON_UNESCAPED_UNICODE);
