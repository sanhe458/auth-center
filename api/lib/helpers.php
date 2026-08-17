<?php
/**
 * 公共工具：响应格式、随机 token、哈希
 */

/** 统一 JSON 响应 */
function jsonOut(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok(array $data = []): void
{
    jsonOut(array_merge(['code' => 0, 'message' => 'ok'], ['data' => $data]));
}

function fail(int $code, string $message, int $httpCode = 400): void
{
    jsonOut(['code' => $code, 'message' => $message], $httpCode);
}

/** 读取 JSON body */
function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** 读取请求参数（GET/POST/JSON 统一） */
function param(string $key, $default = null)
{
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key])) return $_GET[$key];
    static $json = null;
    if ($json === null) $json = jsonBody();
    return $json[$key] ?? $default;
}

/** 生成 URL 安全随机串 */
function randToken(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** 生成 sk- 前缀密钥 */
function genSecret(): string
{
    return 'sk-' . randToken(24);
}

/** 密钥哈希（不可逆，用于存储比对） */
function hashSecret(string $secret): string
{
    return hash_hmac('sha256', $secret, cfg('secret_pepper'));
}

/** 生成 client_id */
function genClientId(): string
{
    return strtolower(substr(bin2hex(random_bytes(8)), 0, 8)) . '_' . strtolower(randToken(5));
}

/** 生成公开 uid */
function genUid(): string
{
    return 'u_' . strtolower(randToken(6));
}

/** Bearer token 提取 */
function bearerToken(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $h, $m)) return trim($m[1]);
    return null;
}

/** 请求方 IP */
function clientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** 读 MySQL DATETIME 为时间戳 */
function ts(?string $dt): ?int
{
    return $dt ? strtotime($dt) : null;
}

/** 系统配置读取（后台可配，来自 settings 表）
 *  用法：cfg('github_client_id')；无 DB 时回退到常量/默认值
 *
 *  缓存：优先 Redis（ac:cfg 全表 + ac:cfg:ver 版本号），
 *        避免每次请求全表查 DB；后台保存配置时递增版本号失效缓存。
 */
function cfg(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        // Redis 缓存优先（若 redis.php 已加载）
        try {
            if (function_exists('redis') && function_exists('rk')) {
                $ver = redis()->get(rk('cfg:ver'));
                $cached = ($ver !== false) ? redis()->get(rk('cfg:' . $ver)) : false;
                if ($cached !== false) {
                    $data = json_decode((string)$cached, true);
                    if (is_array($data)) {
                        $cache = $data;
                        return cfgLookup($key, $default, $cache);
                    }
                }
            }
        } catch (Throwable $e) {
            // Redis 不可用降级到 DB
        }
        // 回源 DB
        try {
            foreach (db()->query('SELECT skey, svalue FROM settings') as $row) {
                $cache[$row['skey']] = $row['svalue'];
            }
            // 写回 Redis
            try {
                if (function_exists('redis') && function_exists('rk')) {
                    $ver = redis()->incr(rk('cfg:ver'));
                    redis()->setex(rk('cfg:' . $ver), 3600, json_encode($cache));
                }
            } catch (Throwable $e) {
                // 缓存写失败不影响本次读取
            }
        } catch (Throwable $e) {
            // DB 不可用时静默，靠常量回退
        }
    }
    return cfgLookup($key, $default, $cache);
}

/** cfg() 内部：从缓存数组取值，回退到常量/默认值 */
function cfgLookup(string $key, $default, array $cache)
{
    if (array_key_exists($key, $cache) && $cache[$key] !== null && $cache[$key] !== '') {
        return $cache[$key];
    }
    // 回退到常量（兼容未进 settings 表的历史配置）
    $const = strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $key));
    if (defined($const)) return constant($const);
    return $default;
}
