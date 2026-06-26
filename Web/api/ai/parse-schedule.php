<?php
/**
 * BitHabit - AI 自然语言解析日程
 *
 * POST /api/ai/parse-schedule.php
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
    echo json_encode(['error' => '请输入至少 2 个字符的日程描述', 'code' => 'INVALID_INPUT']);
    exit;
}

if (mb_strlen($text) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => '输入内容过长，请精简到 2000 字以内', 'code' => 'INPUT_TOO_LONG']);
    exit;
}

$user = getAuthUser();
$userId = $user ? (int)$user['user_id'] : crc32($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

if (!checkAIRateLimit($userId)) {
    http_response_code(429);
    echo json_encode(['error' => 'AI 调用太频繁，请稍后再试', 'code' => 'RATE_LIMITED']);
    exit;
}

// 推断当前年份用于日期解析
$currentYear = date('Y');
// 暑假通常在 7-8 月，如果当前是 1-6 月，年份可能仍然是当前年
$year = $currentYear;

$systemPrompt = <<<PROMPT
你是一个日程解析助手。用户会用自然语言描述暑假的可用/不可用时间段。
请解析出日程信息，返回严格的 JSON 格式。

规则：
1. 识别每周固定时段（weekly）：哪几天、什么时间、标签
2. 识别特殊日期（special）：从哪天到哪天、什么时间、标签
3. dayOfWeek: 0=周日, 1=周一, 2=周二, 3=周三, 4=周四, 5=周五, 6=周六
4. 时间格式 HH:MM（24小时制），全天则为 null
5. 日期格式 YYYY-MM-DD，单日则 dateTo 为 null
6. 默认年份：{$year} 年
7. 中文数字转阿拉伯数字：一→1，周一→dayOfWeek=1
8. "全天"意味着 startTime=null, endTime=null（整天不可用/休息）
9. "休息"标签统一为"休息日"

返回格式：
{
  "weekly": [
    { "dayOfWeek": 1, "startTime": "08:00", "endTime": "14:00", "label": "补习班" }
  ],
  "special": [
    { "dateFrom": "{$year}-07-15", "dateTo": "{$year}-07-18", "startTime": null, "endTime": null, "label": "旅行" }
  ]
}

注意：
- 如果用户没有提及某一类（weekly/special），返回空数组
- 只输出 JSON，不要有任何额外文本
PROMPT;

$result = callDeepSeek($systemPrompt, $text);

if ($result === null) {
    http_response_code(400);
    echo json_encode([
        'error' => 'AI 解析失败，请手动添加日程',
        'code' => 'AI_PARSE_ERROR',
    ]);
    exit;
}

// 确保返回结构完整
$weekly = $result['weekly'] ?? [];
$special = $result['special'] ?? [];

// 规范化
$weekly = array_map(function ($w) {
    return [
        'dayOfWeek' => (int)($w['dayOfWeek'] ?? $w['day_of_week'] ?? 0),
        'startTime' => $w['startTime'] ?? $w['start_time'] ?? null,
        'endTime' => $w['endTime'] ?? $w['end_time'] ?? null,
        'label' => $w['label'] ?? '',
    ];
}, is_array($weekly) ? $weekly : []);

$special = array_map(function ($s) {
    return [
        'dateFrom' => $s['dateFrom'] ?? $s['date_from'] ?? '',
        'dateTo' => $s['dateTo'] ?? $s['date_to'] ?? null,
        'startTime' => $s['startTime'] ?? $s['start_time'] ?? null,
        'endTime' => $s['endTime'] ?? $s['end_time'] ?? null,
        'label' => $s['label'] ?? '',
    ];
}, is_array($special) ? $special : []);

echo json_encode([
    'weekly' => $weekly,
    'special' => $special,
], JSON_UNESCAPED_UNICODE);
