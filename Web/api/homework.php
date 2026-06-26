<?php
/**
 * BitHabit - 作业 CRUD
 *
 * GET    /api/homework.php          → 列出当前用户所有作业
 * POST   /api/homework.php          → 添加单条作业
 * PATCH  /api/homework.php?id=X     → 编辑作业
 * DELETE /api/homework.php?id=X     → 删除作业
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

$user = requireAuth();
$userId = (int)$user['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$conn = getDbConnection();

// 默认耗时：不填则按 60 分钟（1 小时）计
$defaultTimePerUnit = [
    '练习册' => 10,
    '模拟卷' => 90,
    '单词' => 1,
    '阅读理解' => 15,
    '作文' => 60,
    '读后感' => 60,
    '背诵' => 20,
];
const DEFAULT_TIME_PER_UNIT = 60; // 兜底：1 小时

// --- GET: 列出作业 ---
if ($method === 'GET') {
    $stmt = $conn->prepare(
        'SELECT id, subject, task_type, total_amount, unit, time_per_unit, notes, created_at 
         FROM homework WHERE user_id = ? ORDER BY created_at DESC'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $homework = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['total_amount'] = (float)$row['total_amount'];
        $row['time_per_unit'] = $row['time_per_unit'] !== null ? (int)$row['time_per_unit'] : null;
        $homework[] = $row;
    }

    echo json_encode(['homework' => $homework], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- POST: 添加作业 ---
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $subject = trim($input['subject'] ?? '');
    $taskType = trim($input['type'] ?? $input['task_type'] ?? '');
    $totalAmount = $input['totalAmount'] ?? $input['total_amount'] ?? null;
    $unit = trim($input['unit'] ?? '');
    $timePerUnit = $input['timePerUnit'] ?? $input['time_per_unit'] ?? null;
    $notes = trim($input['notes'] ?? '');

    // 校验
    if (empty($subject) || empty($taskType) || $totalAmount === null || $totalAmount === '' || empty($unit)) {
        http_response_code(400);
        echo json_encode(['error' => '科目、类型、总量、单位均为必填', 'code' => 'INVALID_INPUT']);
        exit;
    }

    $totalAmount = (float)$totalAmount;
    if ($totalAmount <= 0) {
        http_response_code(400);
        echo json_encode(['error' => '总量必须大于 0', 'code' => 'INVALID_INPUT']);
        exit;
    }

    // 未填耗时则用默认值（优先按类型匹配，否则默认 60 分钟 = 1 小时）
    if ($timePerUnit === null || $timePerUnit === '' || $timePerUnit === 0) {
        $timePerUnit = $defaultTimePerUnit[$taskType] ?? DEFAULT_TIME_PER_UNIT;
    } else {
        $timePerUnit = (int)$timePerUnit;
    }

    if ($timePerUnit === null) {
        $stmt = $conn->prepare(
            'INSERT INTO homework (user_id, subject, task_type, total_amount, unit, time_per_unit, notes) 
             VALUES (?, ?, ?, ?, ?, NULL, ?)'
        );
        $stmt->bind_param('issdss', $userId, $subject, $taskType, $totalAmount, $unit, $notes);
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO homework (user_id, subject, task_type, total_amount, unit, time_per_unit, notes) 
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issdsis', $userId, $subject, $taskType, $totalAmount, $unit, $timePerUnit, $notes);
    }

    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode([
            'id' => $stmt->insert_id,
            'createdAt' => date('c'),
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['error' => '添加失败', 'code' => 'DB_ERROR']);
    }
    exit;
}

// --- PATCH: 编辑作业 ---
if ($method === 'PATCH' || $method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => '缺少作业 id', 'code' => 'INVALID_INPUT']);
        exit;
    }

    // 检查所有权
    $stmt = $conn->prepare('SELECT id FROM homework WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => '作业不存在', 'code' => 'NOT_FOUND']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $updates = [];
    $params = [];
    $types = '';

    foreach (['subject', 'task_type', 'unit', 'notes'] as $field) {
        if (isset($input[$field]) && $input[$field] !== '') {
            $updates[] = "$field = ?";
            $params[] = $input[$field];
            $types .= 's';
        }
    }

    if (isset($input['totalAmount']) || isset($input['total_amount'])) {
        $val = (float)($input['totalAmount'] ?? $input['total_amount']);
        if ($val <= 0) {
            http_response_code(400);
            echo json_encode(['error' => '总量必须大于 0', 'code' => 'INVALID_INPUT']);
            exit;
        }
        $updates[] = 'total_amount = ?';
        $params[] = $val;
        $types .= 'd';
    }

    if (isset($input['timePerUnit']) || isset($input['time_per_unit'])) {
        $val = $input['timePerUnit'] ?? $input['time_per_unit'];
        $updates[] = 'time_per_unit = ?';
        $params[] = ($val === null || $val === '' || $val === 0) ? null : (int)$val;
        $types .= 'i';
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => '没有要更新的字段', 'code' => 'NO_UPDATES']);
        exit;
    }

    $params[] = $id;
    $params[] = $userId;
    $types .= 'ii';

    $sql = 'UPDATE homework SET ' . implode(', ', $updates) . ' WHERE id = ? AND user_id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        echo json_encode(['updated' => true], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['error' => '更新失败', 'code' => 'DB_ERROR']);
    }
    exit;
}

// --- DELETE: 删除作业 ---
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => '缺少作业 id', 'code' => 'INVALID_INPUT']);
        exit;
    }

    $stmt = $conn->prepare('DELETE FROM homework WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $userId);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['deleted' => true], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => '作业不存在或无权删除', 'code' => 'NOT_FOUND']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => '不支持的请求方法', 'code' => 'METHOD_NOT_ALLOWED']);
