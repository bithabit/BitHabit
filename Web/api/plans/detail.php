<?php
/**
 * BitHabit - 计划详情
 *
 * GET /api/plans/detail.php?id=X
 * 返回计划元信息 + 每日任务分组
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

$planId = (int)($_GET['id'] ?? 0);
if ($planId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => '缺少计划 id', 'code' => 'INVALID_INPUT']);
    exit;
}

$conn = getDbConnection();

// 获取计划元信息
$stmt = $conn->prepare(
    'SELECT id, name, start_date, end_date, daily_start_time, daily_end_time, strategy, created_at 
     FROM plans WHERE id = ? AND user_id = ?'
);
$stmt->bind_param('ii', $planId, $userId);
$stmt->execute();
$planResult = $stmt->get_result();

if ($planResult->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => '计划不存在', 'code' => 'NOT_FOUND']);
    exit;
}

$plan = $planResult->fetch_assoc();
$plan['id'] = (int)$plan['id'];

// 获取每日任务
$stmt = $conn->prepare(
    'SELECT pt.id, pt.homework_id, pt.date, pt.amount, pt.estimated_minutes, pt.sort_order, 
            pt.completed, pt.completed_at,
            h.subject, h.task_type, h.unit
     FROM plan_tasks pt
     JOIN homework h ON pt.homework_id = h.id
     WHERE pt.plan_id = ?
     ORDER BY pt.date, pt.sort_order'
);
$stmt->bind_param('i', $planId);
$stmt->execute();
$tasksResult = $stmt->get_result();

$days = [];
while ($task = $tasksResult->fetch_assoc()) {
    $date = $task['date'];
    if (!isset($days[$date])) {
        $days[$date] = ['date' => $date, 'slots' => []];
    }
    $days[$date]['slots'][] = [
        'id' => (int)$task['id'],
        'homeworkId' => (int)$task['homework_id'],
        'subject' => $task['subject'],
        'taskType' => $task['task_type'],
        'amount' => (float)$task['amount'],
        'unit' => $task['unit'],
        'estimatedMinutes' => (int)$task['estimated_minutes'],
        'completed' => (bool)$task['completed'],
        'completedAt' => $task['completed_at'],
    ];
}

$plan['days'] = array_values($days);

echo json_encode($plan, JSON_UNESCAPED_UNICODE);
