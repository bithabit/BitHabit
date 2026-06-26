<?php
/**
 * BitHabit - 轻量 JWT 实现（无 Composer 依赖）
 * 
 * 使用 HMAC-SHA256 签名
 */

define('JWT_SECRET', 'bithabit_jwt_secret_2026_change_in_production');
define('JWT_EXPIRY', 86400 * 7); // 7 天

/**
 * 生成 JWT Token
 */
function generateJWT(array $payload): string {
    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXPIRY;
    $payloadEncoded = base64url_encode(json_encode($payload));
    
    $signature = base64url_encode(
        hash_hmac('sha256', "$header.$payloadEncoded", JWT_SECRET, true)
    );
    
    return "$header.$payloadEncoded.$signature";
}

/**
 * 验证并解析 JWT Token
 * 成功返回 payload 数组，失败返回 null
 */
function verifyJWT(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    
    [$header, $payload, $signature] = $parts;
    
    // 验证签名
    $expectedSig = base64url_encode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
    );
    
    if (!hash_equals($expectedSig, $signature)) {
        return null;
    }
    
    $data = json_decode(base64url_decode($payload), true);
    if (!$data || !isset($data['exp']) || $data['exp'] < time()) {
        return null;
    }
    
    return $data;
}

/**
 * 从请求头中提取并验证 JWT Token
 * 兼容 Apache/FastCGI/Nginx 不同环境
 */
function getAuthUser(): ?array {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? $_SERVER['Authorization']
        ?? '';
    
    // Apache mod_php: apache_request_headers()
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    
    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        return null;
    }
    
    return verifyJWT($matches[1]);
}

/**
 * 要求认证 - 无有效 token 时返回 401
 */
function requireAuth(): array {
    $user = getAuthUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => '未登录或 token 已过期', 'code' => 'UNAUTHORIZED']);
        exit;
    }
    return $user;
}

// --- Base64url 工具 ---

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}
