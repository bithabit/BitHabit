<?php
/**
 * BitHabit - 计划列表
 *
 * GET /api/plans/list.php
 * 返回当前用户所有计划，含任务统计
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

// 获取计划列表，按创建时间倒序
$stmt = $conn->prepare(
    'SELECT id, name, start_date, end_date, strategy, created_at 
     FROM plans WHERE user_id = ? ORDER BY created_at DESC'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();

$plans = [];
while ($plan = $result->fetch_assoc()) {
    $planId = (int)$plan['id'];

    // 获取任务统计
    $statsStmt = $conn->prepare(
        'SELECT COUNT(*) AS task_count, 
                SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_count 
         FROM plan_tasks WHERE plan_id = ?'
    );
    $statsStmt->bind_param('i', $planId);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();

    $plans[] = [
        'id' => $planId,
        'name' => $plan['name'],
        'start_date' => $plan['start_date'],
        'end_date' => $plan['end_date'],
        'strategy' => $plan['strategy'],
        'task_count' => (int)$stats['task_count'],
        'completed_count' => (int)$stats['completed_count'],
        'created_at' => $plan['created_at'],
    ];
}

echo json_encode(['plans' => $plans], JSON_UNESCAPED_UNICODE);
