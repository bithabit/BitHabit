<?php
/**
 * BitHabit - DeepSeek API 代理层
 *
 * 封装 DeepSeek Chat Completions API 调用
 * 共用频率限制：同一用户每分钟最多 5 次 AI 调用
 */

define('DEEPSEEK_API_KEY', getenv('DEEPSEEK_API_KEY') ?: 'sk-0666139518254518b5878dfa99d1b340');
define('DEEPSEEK_API_URL', 'https://api.deepseek.com/chat/completions');
define('AI_RATE_LIMIT', 5);       // 每分钟最多调用次数
define('AI_RATE_WINDOW', 60);     // 窗口 60 秒

/**
 * 频率限制检查（基于内存简单实现，生产环境应改用 Redis/DB）
 * 记录文件：/tmp/bithabit_ai_ratelimit_{user_id}.json
 */
function checkAIRateLimit(int $userId): bool {
    $file = "/tmp/bithabit_ai_ratelimit_{$userId}.json";
    $now = time();

    $calls = [];
    if (file_exists($file)) {
        $calls = json_decode(file_get_contents($file), true) ?: [];
    }

    // 清理窗口外的记录
    $calls = array_filter($calls, fn($t) => $t > ($now - AI_RATE_WINDOW));
    $calls = array_values($calls);

    if (count($calls) >= AI_RATE_LIMIT) {
        return false; // 超限
    }

    $calls[] = $now;
    file_put_contents($file, json_encode($calls), LOCK_EX);
    return true;
}

/**
 * 调用 DeepSeek API
 *
 * @param string $systemPrompt 系统指令
 * @param string $userText 用户输入
 * @return array|null 解析后的 JSON 数组，失败返回 null
 */
function callDeepSeek(string $systemPrompt, string $userText): ?array {
    $payload = [
        'model' => 'deepseek-chat',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userText],
        ],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.1,
        'max_tokens' => 2048,
    ];

    $ch = curl_init(DEEPSEEK_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . DEEPSEEK_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200) {
        error_log("DeepSeek API error: http={$httpCode}, error={$error}, body=" . ($response ?: 'null'));
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['choices'][0]['message']['content'])) {
        error_log("DeepSeek API: unexpected response structure");
        return null;
    }

    $content = $data['choices'][0]['message']['content'];
    $parsed = json_decode($content, true);

    if (!is_array($parsed)) {
        error_log("DeepSeek API: response is not valid JSON array/object");
        return null;
    }

    return $parsed;
}
