<?php
/**
 * BitHabit - 今日任务
 *
 * GET /api/plans/today.php
 * 返回当前用户最新计划中今天的任务列表
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

$conn = getDbConnection();
$today = date('Y-m-d');

// 1. 取用户最新计划
$stmt = $conn->prepare(
    'SELECT id, name FROM plans WHERE user_id = ? ORDER BY created_at DESC LIMIT 1'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$planResult = $stmt->get_result();

if ($planResult->num_rows === 0) {
    // 无计划
    echo json_encode([
        'planId' => null,
        'planName' => null,
        'date' => $today,
        'tasks' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$plan = $planResult->fetch_assoc();
$planId = (int)$plan['id'];

// 2. 查今天的任务
$stmt = $conn->prepare(
    'SELECT pt.id, pt.amount, pt.estimated_minutes, pt.completed, pt.completed_at,
            h.subject, h.task_type, h.unit
     FROM plan_tasks pt
     JOIN homework h ON pt.homework_id = h.id
     WHERE pt.plan_id = ? AND pt.date = ?
     ORDER BY pt.sort_order'
);
$stmt->bind_param('is', $planId, $today);
$stmt->execute();
$tasksResult = $stmt->get_result();

$tasks = [];
while ($task = $tasksResult->fetch_assoc()) {
    $tasks[] = [
        'id' => (int)$task['id'],
        'subject' => $task['subject'],
        'taskType' => $task['task_type'],
        'amount' => (float)$task['amount'],
        'unit' => $task['unit'],
        'estimatedMinutes' => (int)$task['estimated_minutes'],
        'completed' => (bool)$task['completed'],
    ];
}

echo json_encode([
    'planId' => $planId,
    'planName' => $plan['name'],
    'date' => $today,
    'tasks' => $tasks,
], JSON_UNESCAPED_UNICODE);
