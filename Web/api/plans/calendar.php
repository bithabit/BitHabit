<?php
/**
 * BitHabit - 月度日历汇总
 *
 * GET /api/plans/calendar.php?planId=1&year=2026&month=7
 * 返回某月内有任务日期的汇总数据，前端自行构建完整月网格
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
$year = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);

if ($planId <= 0 || $year <= 0 || $month < 1 || $month > 12) {
    http_response_code(400);
    echo json_encode(['error' => '参数错误', 'code' => 'INVALID_INPUT']);
    exit;
}

$conn = getDbConnection();

// 验证计划属于当前用户
$stmt = $conn->prepare('SELECT id, name, start_date, end_date FROM plans WHERE id = ? AND user_id = ?');
$stmt->bind_param('ii', $planId, $userId);
$stmt->execute();
$planResult = $stmt->get_result();

if ($planResult->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => '计划不存在', 'code' => 'NOT_FOUND']);
    exit;
}

$plan = $planResult->fetch_assoc();

// 查询该月有任务日期的汇总
$stmt = $conn->prepare(
    'SELECT date, COUNT(*) AS task_count, 
            SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_count,
            SUM(estimated_minutes) AS total_minutes
     FROM plan_tasks 
     WHERE plan_id = ? AND YEAR(date) = ? AND MONTH(date) = ?
     GROUP BY date
     ORDER BY date'
);
$stmt->bind_param('iii', $planId, $year, $month);
$stmt->execute();
$result = $stmt->get_result();

$days = [];
while ($row = $result->fetch_assoc()) {
    $days[] = [
        'date' => $row['date'],
        'taskCount' => (int)$row['task_count'],
        'completedCount' => (int)$row['completed_count'],
        'totalMinutes' => (int)$row['total_minutes'],
    ];
}

echo json_encode([
    'planId' => $planId,
    'planName' => $plan['name'],
    'year' => $year,
    'month' => $month,
    'startDate' => $plan['start_date'],
    'endDate' => $plan['end_date'],
    'days' => $days,
], JSON_UNESCAPED_UNICODE);
