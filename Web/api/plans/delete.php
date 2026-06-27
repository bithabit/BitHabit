<?php
/**
 * BitHabit - 删除计划（级联删除 homework + plan_tasks）
 *
 * DELETE /api/plans/delete.php?id=X
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';

$user = requireAuth();
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 DELETE 方法', 'code' => 'METHOD_NOT_ALLOWED']);
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

$conn->begin_transaction();
try {
    $delTasks = $conn->prepare('DELETE FROM plan_tasks WHERE plan_id = ?');
    $delTasks->bind_param('i', $planId);
    $delTasks->execute();

    $delHomework = $conn->prepare('DELETE FROM homework WHERE plan_id = ? AND user_id = ?');
    $delHomework->bind_param('ii', $planId, $userId);
    $delHomework->execute();

    $delPlan = $conn->prepare('DELETE FROM plans WHERE id = ? AND user_id = ?');
    $delPlan->bind_param('ii', $planId, $userId);
    $delPlan->execute();

    $conn->commit();
    echo json_encode(['deleted' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => '删除失败: ' . $e->getMessage(), 'code' => 'DB_ERROR']);
}
