<?php
/**
 * BitHabit - 单日任务列表
 *
 * GET /api/plans/day.php?planId=1&date=2026-07-15
 * 返回某天该计划下的所有任务
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';

$user = requireAuth();
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 GET 方法', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$planId = (int)($_GET['planId'] ?? 0);
$date = $_GET['date'] ?? '';

if ($planId <= 0 || empty($date)) {
    http_response_code(400);
    echo json_encode(['error' => '参数错误', 'code' => 'INVALID_INPUT']);
    exit;
}

$conn = getDbConnection();

// 验证计划属于当前用户
$stmt = $conn->prepare('SELECT id FROM plans WHERE id = ? AND user_id = ?');
$stmt->bind_param('ii', $planId, $userId);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => '计划不存在', 'code' => 'NOT_FOUND']);
    exit;
}

// 查询当天任务
$stmt = $conn->prepare(
    'SELECT pt.id, pt.amount, pt.estimated_minutes, pt.completed, pt.completed_at,
            h.subject, h.task_type, h.unit
     FROM plan_tasks pt
     JOIN homework h ON pt.homework_id = h.id
     WHERE pt.plan_id = ? AND pt.date = ?
     ORDER BY pt.sort_order'
);
$stmt->bind_param('is', $planId, $date);
$stmt->execute();
$result = $stmt->get_result();

$tasks = [];
while ($row = $result->fetch_assoc()) {
    $tasks[] = [
        'id' => (int)$row['id'],
        'subject' => $row['subject'],
        'taskType' => $row['task_type'],
        'amount' => (float)$row['amount'],
        'unit' => $row['unit'],
        'estimatedMinutes' => (int)$row['estimated_minutes'],
        'completed' => (bool)$row['completed'],
    ];
}

echo json_encode([
    'planId' => $planId,
    'date' => $date,
    'tasks' => $tasks,
], JSON_UNESCAPED_UNICODE);
