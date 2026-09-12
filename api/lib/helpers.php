<?php
/**
 * 公共工具：响应格式、随机 token、哈希
 */

/**
 * API 层 CSRF 防护：仅对「已登录会话（cookie）」的写操作强制校验。
 * - 未登录 / Bearer token / client 凭证类端点在各自接口内鉴权，不在此拦截
 * - 已登录会话的 POST/PUT/DELETE/PATCH 必须带匹配的 csrf_token（表单字段或 X-CSRF-Token）
 */
function csrfGuardApi(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) return;
    // Bearer 凭证请求不走 cookie 会话，CSRF 不适用（且是标准跨域调用场景）
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (stripos($auth, 'Bearer ') === 0) return;
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['user_id'])) return; // 未登录 → 由各接口自行返回 401
    $sent = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $mine = (string)($_SESSION['csrf_token'] ?? '');
    if ($mine === '' || $sent === '' || !hash_equals($mine, $sent)) {
        securityLog('api.csrf_blocked', ['path' => $_SERVER['REQUEST_URI'] ?? '']);
        fail(40012, 'CSRF 校验失败，请刷新页面后重试', 403);
    }
}

/** 统一安全响应头（防 XSS / 点击劫持 / MIME 嗅探，并强制 HTTPS） */
function securityHeaders(): void
{
    if (headers_sent()) return;
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // 全站 HTTPS（含子域）2 年；首次部署即生效，保留 includeSubDomains 便于后续 preload
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
    // CSP：默认同源；图片/样式允许 data: 与 inline（mdui 组件内联样式）；连接仅同源
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; font-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

/** 统一 JSON 响应 */
function jsonOut(array $data, int $httpCode = 200): void
{
    securityHeaders();
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 安全事件日志（认证异常/密钥不匹配等），便于事后审计告警。
 * 走 error_log，落 nginx/php-fpm 日志，不写敏感明文。
 */
function securityLog(string $event, array $ctx = []): void
{
    $ip = function_exists('clientIp') ? clientIp() : ($_SERVER['REMOTE_ADDR'] ?? '-');
    $ctx['ip'] = $ip;
    $ctx['ua'] = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120);
    error_log('[auth-security] ' . $event . ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** 行为验证码服务端校验（AJ-Captcha 一次验证），失败抛异常 */
function captchaVerify(): void
{
    $base = dirname(__DIR__, 2); // /var/www/auth.sanhe.com.mp
    require_once $base . '/ajcaptcha/vendor/autoload.php';
    $config = require $base . '/ajcaptcha/src/config.php';
    $svc = new \Fastknife\Service\BlockPuzzleCaptchaService($config);
    $token = (string)param('captcha_token', '');
    $pointJson = (string)param('captcha_pointJson', '');
    if ($token === '' || $pointJson === '') {
        throw new RuntimeException('缺少验证码参数');
    }
    // check() 会写入一次性消费键（setEncryptCache）；为避免重放，
    // 校验通过后立即删除题目答案键 captcha:<token>，token 即失效。
    $svc->check($token, $pointJson);
    try {
        redis()->del(rk('captcha:' . $token));
    } catch (Throwable $e) {
        // Redis 异常不影响主流程
    }
}

function ok(array $data = []): void
{
    // 标准格式：成功直接顶层返回数据，HTTP 200
    jsonOut($data);
}

function fail(int $code, string $message, int $httpCode = 400): void
{
    // 标准错误格式：{ error, message } + HTTP 状态码
    jsonOut(['error' => $message, 'code' => $code], $httpCode);
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
    // 缓存放 $GLOBALS，便于 cfgClear() 主动失效（OIDC 密钥生成等场景）
    $cache = &$GLOBALS['__ac_cfg_cache'];
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
/** 清空配置缓存并递增 Redis 版本号（写入 settings 后调用） */
function cfgClear(): void
{
    $GLOBALS['__ac_cfg_cache'] = null;
    try {
        if (function_exists('redis') && function_exists('rk')) {
            redis()->incr(rk('cfg:ver'));
        }
    } catch (Throwable $e) {
        // Redis 不可用时忽略，下次读 DB 即可
    }
}

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
