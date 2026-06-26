<?php
/**
 * BitHabit - AI 自然语言解析作业
 *
 * POST /api/ai/parse.php
 * 不要求登录（用户可能在录入前先试用 AI）
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../deepseek.php';
require_once __DIR__ . '/../jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 POST 方法', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$text = trim($input['text'] ?? '');

if (empty($text) || mb_strlen($text) < 2) {
    http_response_code(400);
    echo json_encode(['error' => '请输入至少 2 个字符的作业描述', 'code' => 'INVALID_INPUT']);
    exit;
}

if (mb_strlen($text) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => '输入内容过长，请精简到 2000 字以内', 'code' => 'INPUT_TOO_LONG']);
    exit;
}

// 频率限制（如已登录则按用户限制，否则按 IP）
$user = getAuthUser();
$userId = $user ? (int)$user['user_id'] : crc32($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

if (!checkAIRateLimit($userId)) {
    http_response_code(429);
    echo json_encode(['error' => 'AI 调用太频繁，请稍后再试', 'code' => 'RATE_LIMITED']);
    exit;
}

$systemPrompt = <<<'PROMPT'
你是一个暑假作业解析助手。用户会用自然语言描述他们的暑假作业。
请解析出每项作业的具体信息，返回严格的 JSON 格式。

规则：
1. 识别每项作业的科目（subject）、类型（task_type）、总量（totalAmount）、单位（unit）
2. 科目识别：数学、英语、语文、物理、化学、生物、历史、地理、政治。若无法判断，填"其他"
3. 类型识别：练习册、模拟卷、单词、阅读理解、作文、读后感、背诵、其他
4. 总量为数字（如 3、20、60），单位从：页、套、篇、个、章、节、遍 中选择最合适的
5. 每项作业的备注（notes）如有额外描述则填入，否则空字符串
6. 不要估算耗时，timePerUnit 始终为 null

返回格式：
{
  "tasks": [
    {
      "subject": "数学",
      "type": "模拟卷",
      "totalAmount": 3,
      "unit": "套",
      "timePerUnit": null,
      "notes": ""
    }
  ]
}

注意：
- 中文数字转阿拉伯数字：三→3，二十→20
- 同一科目可有多项不同类型作业
- 英文字母数量（如 3A, 3B）理解为第几册，忽略字母，仅取数字
PROMPT;

$result = callDeepSeek($systemPrompt, $text);

if ($result === null || !isset($result['tasks']) || !is_array($result['tasks'])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'AI 解析失败，请检查输入或使用手动录入',
        'code' => 'AI_PARSE_ERROR',
    ]);
    exit;
}

// 规范化每项任务，应用默认耗时
$defaultTimePerUnit = [
    '练习册' => 10,
    '模拟卷' => 90,
    '单词' => 1,
    '阅读理解' => 15,
    '作文' => 60,
    '读后感' => 60,
    '背诵' => 20,
];

$tasks = [];
foreach ($result['tasks'] as $task) {
    $taskType = $task['type'] ?? $task['task_type'] ?? '其他';
    $tasks[] = [
        'subject' => $task['subject'] ?? '其他',
        'type' => $taskType,
        'totalAmount' => is_numeric($task['totalAmount'] ?? $task['total_amount'] ?? null) ? (float)$task['totalAmount'] : (float)($task['total_amount'] ?? 0),
        'unit' => $task['unit'] ?? '个',
        'timePerUnit' => $defaultTimePerUnit[$taskType] ?? 60,
        'notes' => $task['notes'] ?? '',
    ];
}

echo json_encode(['tasks' => $tasks], JSON_UNESCAPED_UNICODE);
