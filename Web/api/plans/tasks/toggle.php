<?php
/**
 * BitHabit - 任务打卡切换
 *
 * PATCH /api/plans/tasks/toggle.php
 * 切换 plan_tasks 的完成状态
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../jwt.php';

$user = requireAuth();
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 PATCH 方法', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$taskId = (int)($input['id'] ?? 0);

if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => '缺少任务 id', 'code' => 'INVALID_INPUT']);
    exit;
}

$conn = getDbConnection();

// 验证任务属于当前用户的计划
$stmt = $conn->prepare(
    'SELECT pt.id, pt.completed, pt.completed_at, p.user_id
     FROM plan_tasks pt
     JOIN plans p ON pt.plan_id = p.id
     WHERE pt.id = ?'
);
$stmt->bind_param('i', $taskId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => '任务不存在', 'code' => 'NOT_FOUND']);
    exit;
}

$task = $result->fetch_assoc();
if ((int)$task['user_id'] !== $userId) {
    http_response_code(403);
    echo json_encode(['error' => '无权操作此任务', 'code' => 'FORBIDDEN']);
    exit;
}

$isCompleted = (bool)$task['completed'];

if ($isCompleted) {
    // 取消完成
    $stmt = $conn->prepare('UPDATE plan_tasks SET completed = 0, completed_at = NULL WHERE id = ?');
    $stmt->bind_param('i', $taskId);
    $stmt->execute();

    echo json_encode([
        'id' => $taskId,
        'completed' => false,
        'completedAt' => null,
    ], JSON_UNESCAPED_UNICODE);
} else {
    // 标记完成
    $now = date('Y-m-d\TH:i:s');
    $stmt = $conn->prepare('UPDATE plan_tasks SET completed = 1, completed_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $taskId);
    $stmt->execute();

    echo json_encode([
        'id' => $taskId,
        'completed' => true,
        'completedAt' => $now,
    ], JSON_UNESCAPED_UNICODE);
}
