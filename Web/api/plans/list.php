<?php
/**
 * BitHabit - 计划列表 (v2)
 *
 * GET /api/plans/list.php
 * 返回当前用户所有计划，含作业数量 + 任务进度 + 状态
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

    // 作业数量
    $hwStmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM homework WHERE plan_id = ?');
    $hwStmt->bind_param('i', $planId);
    $hwStmt->execute();
    $hwCount = (int)$hwStmt->get_result()->fetch_assoc()['cnt'];

    // 任务统计
    $statsStmt = $conn->prepare(
        'SELECT COUNT(*) AS task_count, 
                SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_count 
         FROM plan_tasks WHERE plan_id = ?'
    );
    $statsStmt->bind_param('i', $planId);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();

    $taskCount = (int)$stats['task_count'];
    $completedCount = (int)$stats['completed_count'];

    // 状态判断
    $startDate = $plan['start_date'];
    $endDate = $plan['end_date'];
    $status = 'active';
    if ($today > $endDate) {
        $status = ($completedCount > 0 && $completedCount >= $taskCount && $taskCount > 0) ? 'completed' : 'expired';
    } elseif ($today < $startDate) {
        $status = 'pending';
    }

    $plans[] = [
        'id' => $planId,
        'name' => $plan['name'],
        'start_date' => $startDate,
        'end_date' => $endDate,
        'strategy' => $plan['strategy'],
        'homework_count' => $hwCount,
        'task_count' => $taskCount,
        'completed_count' => $completedCount,
        'status' => $status,
        'created_at' => $plan['created_at'],
    ];
}

// 排序：活跃→待开始→已完成→已过期
$statusOrder = ['active' => 0, 'pending' => 1, 'completed' => 2, 'expired' => 3];
usort($plans, function ($a, $b) use ($statusOrder) {
    $oa = $statusOrder[$a['status']] ?? 99;
    $ob = $statusOrder[$b['status']] ?? 99;
    if ($oa !== $ob) return $oa - $ob;
    return strcmp($b['created_at'], $a['created_at']); // 同状态最新在前
});

echo json_encode(['plans' => $plans], JSON_UNESCAPED_UNICODE);
