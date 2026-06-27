<?php
/**
 * BitHabit - 今日任务 (v2)
 *
 * GET /api/plans/today.php
 * 返回当前用户所有活跃计划的今日任务，按计划分组
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

// 查询所有活跃计划（进行中且未过期）
$stmt = $conn->prepare(
    'SELECT id, name FROM plans 
     WHERE user_id = ? AND start_date <= ? AND end_date >= ?
     ORDER BY created_at DESC'
);
$stmt->bind_param('iss', $userId, $today, $today);
$stmt->execute();
$plansResult = $stmt->get_result();

$plans = [];
$totalTasks = 0;
$totalMinutes = 0;

while ($plan = $plansResult->fetch_assoc()) {
    $planId = (int)$plan['id'];

    // 查今天的任务
    $taskStmt = $conn->prepare(
        'SELECT pt.id, pt.amount, pt.estimated_minutes, pt.completed, pt.completed_at,
                h.subject, h.task_type, h.unit
         FROM plan_tasks pt
         JOIN homework h ON pt.homework_id = h.id
         WHERE pt.plan_id = ? AND pt.date = ?
         ORDER BY pt.sort_order'
    );
    $taskStmt->bind_param('is', $planId, $today);
    $taskStmt->execute();
    $tasksResult = $taskStmt->get_result();

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
        $totalTasks++;
        $totalMinutes += (int)$task['estimated_minutes'];
    }

    $plans[] = [
        'planId' => $planId,
        'planName' => $plan['name'],
        'tasks' => $tasks,
    ];
}

echo json_encode([
    'date' => $today,
    'plans' => $plans,
    'totalTasks' => $totalTasks,
    'totalMinutes' => $totalMinutes,
], JSON_UNESCAPED_UNICODE);
