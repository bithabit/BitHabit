<?php
/**
 * BitHabit - 日程 CRUD
 *
 * GET    /api/schedule.php                           → 列出所有日程
 * POST   /api/schedule.php?type=weekly               → 添加每周固定
 * POST   /api/schedule.php?type=special              → 添加特殊日期
 * PATCH  /api/schedule.php?id=X&type=weekly|special  → 编辑
 * DELETE /api/schedule.php?id=X&type=weekly|special  → 删除
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

$user = requireAuth();
$userId = (int)$user['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$conn = getDbConnection();

// --- GET: 列出所有日程 ---
if ($method === 'GET') {
    // 查每周固定
    $stmt = $conn->prepare(
        'SELECT id, day_of_week, start_time, end_time, label 
         FROM schedule_weekly WHERE user_id = ? ORDER BY day_of_week, start_time'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $weekly = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['day_of_week'] = (int)$row['day_of_week'];
        $weekly[] = $row;
    }

    // 查特殊日期
    $stmt = $conn->prepare(
        'SELECT id, date_from, date_to, start_time, end_time, label 
         FROM schedule_special WHERE user_id = ? ORDER BY date_from'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $special = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $special[] = $row;
    }

    echo json_encode(['weekly' => $weekly, 'special' => $special], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- POST: 添加日程 ---
if ($method === 'POST') {
    $type = $_GET['type'] ?? 'weekly';
    $input = json_decode(file_get_contents('php://input'), true);

    if ($type === 'weekly') {
        $dayOfWeek = (int)($input['dayOfWeek'] ?? $input['day_of_week'] ?? -1);
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            http_response_code(400);
            echo json_encode(['error' => 'dayOfWeek 需在 0-6 之间', 'code' => 'INVALID_INPUT']);
            exit;
        }

        $startTime = $input['startTime'] ?? $input['start_time'] ?? null;
        $endTime = $input['endTime'] ?? $input['end_time'] ?? null;
        $label = $input['label'] ?? '';

        $stmt = $conn->prepare(
            'INSERT INTO schedule_weekly (user_id, day_of_week, start_time, end_time, label) 
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iisss', $userId, $dayOfWeek, $startTime, $endTime, $label);

    } elseif ($type === 'special') {
        $dateFrom = $input['dateFrom'] ?? $input['date_from'] ?? '';
        $dateTo = $input['dateTo'] ?? $input['date_to'] ?? null;
        $startTime = $input['startTime'] ?? $input['start_time'] ?? null;
        $endTime = $input['endTime'] ?? $input['end_time'] ?? null;
        $label = $input['label'] ?? '';

        if (empty($dateFrom)) {
            http_response_code(400);
            echo json_encode(['error' => 'dateFrom 为必填', 'code' => 'INVALID_INPUT']);
            exit;
        }

        $stmt = $conn->prepare(
            'INSERT INTO schedule_special (user_id, date_from, date_to, start_time, end_time, label) 
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssss', $userId, $dateFrom, $dateTo, $startTime, $endTime, $label);

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'type 须为 weekly 或 special', 'code' => 'INVALID_TYPE']);
        exit;
    }

    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(['id' => (string)$stmt->insert_id], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['error' => '添加失败', 'code' => 'DB_ERROR']);
    }
    exit;
}

// --- PATCH / PUT: 编辑日程 ---
if ($method === 'PATCH' || $method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    $type = $_GET['type'] ?? 'weekly';

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => '缺少 id', 'code' => 'INVALID_INPUT']);
        exit;
    }

    $table = $type === 'special' ? 'schedule_special' : 'schedule_weekly';
    $input = json_decode(file_get_contents('php://input'), true);

    // 检查所有权
    $stmt = $conn->prepare("SELECT id FROM $table WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => '日程不存在', 'code' => 'NOT_FOUND']);
        exit;
    }

    $updates = [];
    $params = [];
    $types = '';

    if ($type === 'weekly') {
        if (isset($input['dayOfWeek']) || isset($input['day_of_week'])) {
            $dow = (int)($input['dayOfWeek'] ?? $input['day_of_week']);
            if ($dow < 0 || $dow > 6) {
                http_response_code(400);
                echo json_encode(['error' => 'dayOfWeek 需在 0-6 之间', 'code' => 'INVALID_INPUT']);
                exit;
            }
            $updates[] = 'day_of_week = ?';
            $params[] = $dow;
            $types .= 'i';
        }
    } else {
        if (isset($input['dateFrom']) || isset($input['date_from'])) {
            $updates[] = 'date_from = ?';
            $params[] = $input['dateFrom'] ?? $input['date_from'];
            $types .= 's';
        }
        if (isset($input['dateTo']) || isset($input['date_to'])) {
            $updates[] = 'date_to = ?';
            $params[] = $input['dateTo'] ?? $input['date_to'];
            $types .= 's';
        }
    }

    $timeFields = ['startTime' => 'start_time', 'endTime' => 'end_time'];
    foreach ($timeFields as $inputKey => $colKey) {
        if (array_key_exists($inputKey, $input)) {
            $updates[] = "$colKey = ?";
            $params[] = $input[$inputKey];
            $types .= 's';
        } elseif (array_key_exists($colKey, $input)) {
            $updates[] = "$colKey = ?";
            $params[] = $input[$colKey];
            $types .= 's';
        }
    }

    if (isset($input['label'])) {
        $updates[] = 'label = ?';
        $params[] = $input['label'];
        $types .= 's';
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => '没有要更新的字段', 'code' => 'NO_UPDATES']);
        exit;
    }

    $params[] = $id;
    $params[] = $userId;
    $types .= 'ii';

    $sql = "UPDATE $table SET " . implode(', ', $updates) . ' WHERE id = ? AND user_id = ?';
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

// --- DELETE: 删除日程 ---
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $type = $_GET['type'] ?? 'weekly';

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => '缺少 id', 'code' => 'INVALID_INPUT']);
        exit;
    }

    $table = $type === 'special' ? 'schedule_special' : 'schedule_weekly';
    $stmt = $conn->prepare("DELETE FROM $table WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $id, $userId);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['deleted' => true], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => '日程不存在或无权删除', 'code' => 'NOT_FOUND']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => '不支持的请求方法', 'code' => 'METHOD_NOT_ALLOWED']);
