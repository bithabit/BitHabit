<?php
/**
 * BitHabit - 逐项作业调整
 *
 * GET  /api/plans/homework-adjust.php?planId=X  → 返回作业调整列表
 * PUT  /api/plans/homework-adjust.php?planId=X  → 保存调整
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';

$user = requireAuth();
$userId = (int)$user['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$conn = getDbConnection();

$planId = (int)($_GET['planId'] ?? 0);
if ($planId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => '缺少 planId', 'code' => 'INVALID_INPUT']);
    exit;
}

// 验证计划归属
$planCheck = $conn->prepare('SELECT id FROM plans WHERE id = ? AND user_id = ?');
$planCheck->bind_param('ii', $planId, $userId);
$planCheck->execute();
if ($planCheck->get_result()->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => '计划不存在', 'code' => 'NOT_FOUND']);
    exit;
}

// --- GET: 返回作业调整列表 ---
if ($method === 'GET') {
    $stmt = $conn->prepare(
        'SELECT id, subject, task_type, total_amount, unit, time_per_unit, window_start, window_end, locked
         FROM homework WHERE plan_id = ? AND user_id = ? ORDER BY subject'
    );
    $stmt->bind_param('ii', $planId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $homework = [];
    while ($row = $result->fetch_assoc()) {
        $homework[] = [
            'id' => (int)$row['id'],
            'subject' => $row['subject'],
            'taskType' => $row['task_type'],
            'totalAmount' => (float)$row['total_amount'],
            'unit' => $row['unit'],
            'timePerUnit' => $row['time_per_unit'] !== null ? (int)$row['time_per_unit'] : null,
            'windowStart' => $row['window_start'],
            'windowEnd' => $row['window_end'],
            'locked' => (bool)(int)$row['locked'],
        ];
    }

    echo json_encode(['homework' => $homework], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- PUT: 保存逐项调整 ---
if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    $adjustments = $input['adjustments'] ?? [];
    if (empty($adjustments)) {
        http_response_code(400);
        echo json_encode(['error' => '缺少 adjustments', 'code' => 'INVALID_INPUT']);
        exit;
    }

    $updated = 0;
    $updateStmt = $conn->prepare(
        'UPDATE homework SET window_start = ?, window_end = ?, locked = ? WHERE id = ? AND plan_id = ? AND user_id = ?'
    );

    foreach ($adjustments as $adj) {
        $hwId = (int)($adj['homeworkId'] ?? 0);
        if ($hwId <= 0) continue;

        $ws = $adj['windowStart'] ?? null;
        $we = $adj['windowEnd'] ?? null;
        $locked = isset($adj['locked']) ? (int)(bool)$adj['locked'] : 0;

        $updateStmt->bind_param('ssiiii', $ws, $we, $locked, $hwId, $planId, $userId);
        if ($updateStmt->execute() && $updateStmt->affected_rows > 0) {
            $updated++;
        }
    }

    echo json_encode(['updated' => $updated], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['error' => '仅支持 GET/PUT', 'code' => 'METHOD_NOT_ALLOWED']);
