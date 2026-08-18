<?php
/**
 * 图床核心库
 * ------------------------------------------------------
 * 复用 imgbb 上传图片，可选过期时间。
 * 过期档位：1天 / 7天 / 30天 / 90天 / 180天 / 永久
 *   - 免费用户可用：1/7/30 天
 *   - 永久、90、180 天需 10 元解锁（永久解锁一次，终身有效）
 * 开发者可通过应用凭证(client_id+secret+image权限)调用上传接口，
 * 上传归属到该用户自己的图床。
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

/** 过期档位定义：key => [标签, 秒数(null=永久), 是否需解锁] */
function imageTiers(): array
{
    return [
        '1d'    => ['1 天',   86400,     false],
        '7d'    => ['7 天',   604800,    false],
        '30d'   => ['30 天',  2592000,   false],
        '90d'   => ['90 天',  7776000,   true ],
        '180d'  => ['180 天', 15552000,  true ],
        'forever' => ['永久', null,      true ],
    ];
}

/** 查用户是否已解锁永久 */
function imageIsPermanent(int $userId): bool
{
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $st = db()->prepare('SELECT 1 FROM image_permanent WHERE user_id = ? AND paid = 1 LIMIT 1');
    $st->execute([$userId]);
    $cache[$userId] = (bool)$st->fetchColumn();
    return $cache[$userId];
}

/**
 * 校验过期档位，返回 [seconds|null, tierKey]
 * @throws RuntimeException 当该档位需解锁而用户未解锁
 */
function imageResolveTier(?string $tierKey, int $userId): array
{
    $tiers = imageTiers();
    if (!$tierKey || !isset($tiers[$tierKey])) {
        throw new RuntimeException('无效的过期时间选项');
    }
    [$label, $seconds, $needUnlock] = $tiers[$tierKey];
    if ($needUnlock && !imageIsPermanent($userId)) {
        throw new RuntimeException('该选项（' . $label . '）需解锁图床永久权限，10 元一次，终身有效');
    }
    return [$seconds, $tierKey];
}

/**
 * 调 imgbb 上传图片
 * @param string $base64Image base64 图片数据（不含 data: 前缀）
 * @param string $name 文件名
 * @param int|null $expirySeconds 过期秒数（null=永久）
 * @return array ['url','page_url','delete_hash','delete_url','size','mime']
 * @throws RuntimeException
 */
function imageUploadToImgbb(string $base64Image, string $name, ?int $expirySeconds): array
{
    $key = cfg('imgbb_key');
    if (!$key) throw new RuntimeException('图床服务未配置');

    $post = [
        'key'   => $key,
        'image' => $base64Image,
        'name'  => $name ?: 'image.jpg',
    ];
    if ($expirySeconds !== null) {
        $post['expiration'] = $expirySeconds;
    }

    $ch = curl_init('https://api.imgbb.com/1/upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) throw new RuntimeException('上传失败：' . $err);
    $data = json_decode((string)$resp, true);
    if (!$data || !isset($data['data'])) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $http);
        throw new RuntimeException('图床上传失败：' . $msg);
    }

    $d = $data['data'];
    return [
        'url'         => $d['url'] ?? '',
        'page_url'    => $d['url_viewer'] ?? $d['page_url'] ?? '',
        'delete_hash' => $d['deletehash'] ?? '',
        'delete_url'  => $d['delete_url'] ?? '',
        'size'        => (int)($d['size'] ?? 0),
        'mime'        => $d['image']['mime'] ?? $d['image']['extension'] ?? '',
    ];
}

/**
 * 通用图片记录写入（用户上传 / 开发者 API 共用）
 * @return array 记录的图片信息
 */
function imageRecordSave(
    int $userId,
    ?int $appId,
    string $name,
    array $imgbb,
    ?int $expirySeconds,
    bool $isPermanent,
    string $tierKey
): array {
    $expiresAt = null;
    if ($expirySeconds !== null) {
        $expiresAt = date('Y-m-d H:i:s', time() + $expirySeconds);
    }
    $db = db();
    $db->prepare(
        'INSERT INTO images (user_id, app_id, name, url, page_url, delete_hash, delete_url, size, mime, expires_at, is_permanent) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $userId, $appId, mb_substr($name, 0, 200), $imgbb['url'], $imgbb['page_url'],
        $imgbb['delete_hash'], $imgbb['delete_url'], $imgbb['size'], $imgbb['mime'],
        $expiresAt, $isPermanent ? 1 : 0,
    ]);
    $id = (int)$db->lastInsertId();

    return [
        'id'          => $id,
        'name'        => $name,
        'url'         => $imgbb['url'],
        'page_url'    => $imgbb['page_url'],
        'delete_url'  => $imgbb['delete_url'],
        'size'        => $imgbb['size'],
        'mime'        => $imgbb['mime'],
        'expires_at'  => $expiresAt,
        'is_permanent'=> $isPermanent,
        'tier'        => $tierKey,
    ];
}

/**
 * 图床 永久解锁（10 元），返回 [是否成功, 解锁前状态]
 * 调用方负责：创建图床支付订单并确保到账后再调此函数
 */
function imageUnlockPermanent(int $userId): bool
{
    if (imageIsPermanent($userId)) return false;
    $db = db();
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT 1 FROM image_permanent WHERE user_id = ? AND paid = 1 FOR UPDATE');
        $st->execute([$userId]);
        if ($st->fetchColumn()) { $db->rollBack(); return false; }
        $db->prepare('INSERT INTO image_permanent (user_id, paid, unlocked_at) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE paid=1, unlocked_at=NOW()')
            ->execute([$userId]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
