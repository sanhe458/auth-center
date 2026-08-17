<?php
/**
 * 资源接口（OAuth 保护）：用户信息 / 授权管理
 * Bearer token 校验，Redis 优先
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/redis.php';
require_once __DIR__ . '/../lib/helpers.php';

/**
 * Bearer token 校验，返回 token 上下文
 * 优先查 Redis 缓存，未命中回源数据库
 */
function requireToken(): array
{
    $token = bearerToken();
    if (!$token) {
        fail(40005, '缺少访问令牌', 401);
    }
    $hash = hash('sha256', $token);

    // Redis 缓存命中
    $cached = redis()->get(rk('tok:' . $hash));
    if ($cached !== false) {
        $ctx = json_decode($cached, true);
        if (is_array($ctx) && tokenStillValid($ctx)) {
            return $ctx;
        }
        // 缓存失效（用户被禁/应用被吊销/授权被撤回）→ 删缓存走数据库校验
        redis()->del(rk('tok:' . $hash));
    }

    // 回源数据库
    $st = db()->prepare('SELECT * FROM oauth_tokens WHERE access_token_hash = ? AND revoked = 0 LIMIT 1');
    $st->execute([$hash]);
    $row = $st->fetch();
    if (!$row || strtotime($row['access_expires_at']) < time()) {
        fail(40005, '访问令牌无效或已过期', 401);
    }

    $ctx = [
        'user_id' => (int)$row['user_id'],
        'app_id'  => (int)$row['app_id'],
        'scope'   => $row['scopes'],
    ];
    redis()->setex(rk('tok:' . $hash), max(60, strtotime($row['access_expires_at']) - time()), json_encode($ctx));
    return $ctx;
}

/**
 * GET /api/user 当前用户信息（Bearer token）
 */
function apiUserInfo(): void
{
    $ctx = requireToken();
    $scopes = explode(',', $ctx['scope']);

    $st = db()->prepare('SELECT uid, nickname, avatar, email, created_at FROM users WHERE id = ? LIMIT 1');
    $st->execute([$ctx['user_id']]);
    $u = $st->fetch();
    if (!$u) fail(41008, '用户不存在', 404);

    $data = [
        'id'        => $u['uid'],
        'nickname'  => $u['nickname'],
        'avatar'    => $u['avatar'],
        'created_at'=> $u['created_at'],
    ];
    // email 需要 basic 权限
    if (in_array('basic', $scopes, true)) {
        $data['email'] = $u['email'];
    }
    // UserInfo 端点按标准 OAuth 顶层返回用户字段（new-api 等客户端在此取 id/nickname/email）
    jsonOut($data);
}

/**
 * GET /api/authorizations 我的授权列表（登录态）
 */
function authList(): void
{
    $userId = requireUser();
    $st = db()->prepare('SELECT a.*, ap.name AS app_name, ap.client_id FROM authorizations a
                         JOIN apps ap ON ap.id = a.app_id
                         WHERE a.user_id = ? ORDER BY a.updated_at DESC');
    $st->execute([$userId]);
    $rows = $st->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'         => (int)$r['id'],
            'app_name'   => $r['app_name'],
            'client_id'  => $r['client_id'],
            'scopes'     => explode(',', $r['scopes']),
            'status'     => (int)$r['status'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }
    ok(['authorizations' => $out]);
}

/**
 * POST /api/authorizations/revoke 撤回授权
 * { authorization_id }
 */
function authRevoke(): void
{
    $userId = requireUser();
    $authId = (int)param('authorization_id', 0);

    $st = db()->prepare('SELECT id FROM authorizations WHERE id = ? AND user_id = ? LIMIT 1');
    $st->execute([$authId, $userId]);
    if (!$st->fetch()) fail(44001, '授权记录不存在', 404);

    db()->prepare('UPDATE authorizations SET status = 0, updated_at = NOW() WHERE id = ?')->execute([$authId]);
    // 同时吊销该应用给此用户的所有令牌（含 Redis 缓存清理）
    $app = db()->prepare('SELECT app_id FROM authorizations WHERE id = ?');
    $app->execute([$authId]);
    $appId = (int)$app->fetch()['app_id'];
    $st = db()->prepare('SELECT access_token_hash FROM oauth_tokens WHERE user_id = ? AND app_id = ? AND revoked = 0');
    $st->execute([$userId, $appId]);
    $r = redis();
    foreach ($st->fetchAll() as $tok) {
        $r->del(rk('tok:' . $tok['access_token_hash']));
    }
    db()->prepare('UPDATE oauth_tokens SET revoked = 1 WHERE user_id = ? AND app_id = ?')
        ->execute([$userId, $appId]);

    ok();
}


/**
 * 校验 token 上下文是否仍然有效：
 * 用户未禁用 + 应用未吊销 + 授权关系仍有效
 */
function tokenStillValid(array $ctx): bool
{
    $st = db()->prepare('SELECT u.status AS us, a.status AS ast,
                         (SELECT status FROM authorizations WHERE user_id = ? AND app_id = ? LIMIT 1) AS az
                         FROM users u, apps a WHERE u.id = ? AND a.id = ?');
    $st->execute([$ctx['user_id'], $ctx['app_id'], $ctx['user_id'], $ctx['app_id']]);
    $row = $st->fetch();
    if (!$row) return false;
    if ((int)$row['us'] !== 1) return false;                    // 用户被禁用
    if ((int)$row['ast'] === 3) return false;                   // 应用被吊销
    if ($row['az'] !== null && (int)$row['az'] !== 1) return false; // 授权被撤回
    return true;
}
