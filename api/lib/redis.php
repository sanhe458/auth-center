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

/** 限流：滑动窗口，返回是否放行 */
function rateLimit(string $bucket, int $max, int $windowSec): bool
{
    $r = redis();
    $key = rk('rl:' . $bucket);
    $now = time();
    $pipe = $r->multi(Redis::PIPELINE);
    $pipe->zRemRangeByScore($key, 0, $now - $windowSec);
    $pipe->zAdd($key, $now, uniqid('', true));
    $pipe->zCard($key);
    $pipe->expire($key, $windowSec);
    $res = $pipe->exec();
    return ($res[2] ?? 0) <= $max;
}
