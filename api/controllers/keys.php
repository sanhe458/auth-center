<?php
/**
 * API 密钥管理接口：列表 / 创建 / 吊销
 * 需要登录态（session）
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/redis.php';
require_once __DIR__ . '/../lib/helpers.php';

/**
 * GET /keys 密钥列表（按应用分组）
 * ?client_id= 可选筛选
 */
function keysList(): void
{
    $userId = requireUser();
    $clientId = param('client_id', '');

    $sql = 'SELECT k.*, a.name AS app_name, a.client_id FROM api_keys k
            JOIN apps a ON a.id = k.app_id
            WHERE a.owner_id = ?';
    $args = [$userId];
    if ($clientId) {
        $sql .= ' AND a.client_id = ?';
        $args[] = $clientId;
    }
    $sql .= ' ORDER BY k.created_at DESC';

    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'          => (int)$r['id'],
            'app_name'    => $r['app_name'],
            'client_id'   => $r['client_id'],
            'key_prefix'  => $r['key_prefix'],
            'name'        => $r['name'],
            'status'      => (int)$r['status'],
            'last_used_at'=> $r['last_used_at'],
            'created_at'  => $r['created_at'],
        ];
    }
    ok(['keys' => $out]);
}

/**
 * POST /keys/create 生成新密钥
 * { client_id, name? }
 * 返回完整密钥（只显示一次）
 */
function keysCreate(): void
{
    $userId = requireUser();
    $clientId = param('client_id', '');
    $name = trim(param('name', ''));

    $app = db()->prepare('SELECT id FROM apps WHERE client_id = ? AND owner_id = ? LIMIT 1');
    $app->execute([$clientId, $userId]);
    $app = $app->fetch();
    if (!$app) fail(42004, '应用不存在或无权操作', 404);

    // 每应用最多 5 个有效密钥
    $cnt = db()->prepare('SELECT COUNT(*) c FROM api_keys WHERE app_id = ? AND status = 1');
    $cnt->execute([$app['id']]);
    if ((int)$cnt->fetch()['c'] >= 5) {
        fail(43001, '每应用最多 5 个有效密钥，请先吊销旧密钥', 400);
    }

    $secret = genSecret(); // sk-xxx
    $prefix = substr($secret, 0, 10);

    $st = db()->prepare('INSERT INTO api_keys (app_id, key_prefix, key_hash, name) VALUES (?,?,?,?)');
    $st->execute([$app['id'], $prefix, hashSecret($secret), $name]);

    ok([
        'key'       => $secret,  // 只此一次
        'key_prefix'=> $prefix,
    ]);
}

/**
 * POST /keys/revoke 吊销密钥
 * { key_id }
 */
function keysRevoke(): void
{
    $userId = requireUser();
    $keyId = (int)param('key_id', 0);

    $st = db()->prepare('SELECT k.id FROM api_keys k JOIN apps a ON a.id = k.app_id
                         WHERE k.id = ? AND a.owner_id = ? LIMIT 1');
    $st->execute([$keyId, $userId]);
    if (!$st->fetch()) fail(43002, '密钥不存在或无权操作', 404);

    db()->prepare('UPDATE api_keys SET status = 0 WHERE id = ?')->execute([$keyId]);
    ok();
}
