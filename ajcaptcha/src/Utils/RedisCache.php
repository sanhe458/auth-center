<?php
declare(strict_types=1);

namespace Fastknife\Utils;

/**
 * AJ-Captcha Redis 缓存驱动
 * 桥接 AuthCenter 现有 redis.php（redis() 单例 + rk() 前缀）。
 * 实现 get/set/delete/has，answer点坐标与secretKey 存 Redis，避免落盘。
 */
class RedisCache
{
    /** 默认过期秒数 */
    protected $expire = 7200;

    /** key 前缀（叠加 AuthCenter 的 REDIS_PREFIX） */
    protected $prefix = 'captcha:';

    public function __construct(array $options = [])
    {
        if (isset($options['expire'])) {
            $this->expire = (int) $options['expire'];
        }
        if (isset($options['prefix'])) {
            $this->prefix = (string) $options['prefix'];
        }
        require_once __DIR__ . '/../../../api/lib/redis.php';
    }

    public function has($name): bool
    {
        return redis()->exists($this->key($name)) > 0;
    }

    public function get($name, $default = null)
    {
        $raw = redis()->get($this->key($name));
        if ($raw === false || $raw === null) {
            return $default;
        }
        $data = @unserialize($raw);
        return $data === false ? $default : $data;
    }

    public function set($name, $value, $expire = null): bool
    {
        if ($expire === null) {
            $expire = $this->expire;
        }
        $k = $this->key($name);
        $ok = redis()->set($k, serialize($value));
        if ($ok && $expire > 0) {
            redis()->expire($k, (int) $expire);
        }
        return (bool) $ok;
    }

    public function delete($name): bool
    {
        return redis()->del($this->key($name)) > 0;
    }

    public function clear(): bool
    {
        // 仅清本驱动的前缀键
        $r = redis();
        $it = null;
        $keys = $r->scan($it, $this->key('*'), 200);
        if (is_array($keys) && $keys) {
            $r->del($keys);
        }
        return true;
    }

    protected function key(string $name): string
    {
        // rk() 已带 REDIS_PREFIX，再叠一层业务前缀避免冲突
        return rk($this->prefix . $name);
    }
}
