<?php
/**
 * BitHabit - 归档计划（不再出现在今日页）
 *
 * PATCH /api/plans/archive.php?id=X
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';

$user = requireAuth();
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 PATCH 方法', 'code' => 'METHOD_NOT_ALLOWED']);
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

// 将日期改到过去以使其不显示在活跃计划中
// 或者加一个 is_archived 字段。为简单起见，将 end_date 设为昨天
$yesterday = date('Y-m-d', strtotime('-1 day'));
$updStmt = $conn->prepare('UPDATE plans SET end_date = ? WHERE id = ? AND user_id = ?');
$updStmt->bind_param('sii', $yesterday, $planId, $userId);

if ($updStmt->execute()) {
    echo json_encode(['archived' => true, 'effective_end' => $yesterday], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode(['error' => '归档失败', 'code' => 'DB_ERROR']);
}
