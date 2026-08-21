<?php
/**
 * Redis 连接（单例）+ 简单封装
 */
require_once __DIR__ . '/../config.php';

function redis(): Redis
{
    static $r = null;
    if ($r === null) {
        $r = new Redis();
        $r->connect(REDIS_HOST, REDIS_PORT);
    }
    return $r;
}

/** 带前缀的 key */
function rk(string $key): string
{
    return REDIS_PREFIX . $key;
}

/** 限流：固定窗口计数（INCR + EXPIRE），返回是否放行 */
function rateLimit(string $bucket, int $max, int $windowSec): bool
{
    $r = redis();
    $key = rk('rl:' . $bucket);
    $c = $r->incr($key);
    if ($c === 1) {
        // 首次计数，设置窗口过期
        $r->expire($key, $windowSec);
    } elseif ($r->ttl($key) < 0) {
        // 上次 expire 因进程中断未生效 → 补设，防 key 永久残留
        $r->expire($key, $windowSec);
    }
    return $c <= $max;
}
