<?php
/**
 * 应用管理接口：列表 / 创建 / 详情 / 更新 / 删除
 * 需要登录态（session）
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/redis.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/scopes.php';

/** 当前登录用户 ID，未登录直接 401 */
function requireUser(): int
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        fail(41007, '未登录', 401);
    }
    return (int)$userId;
}

/**
 * GET /apps 我的应用列表
 */
function appsList(): void
{
    $userId = requireUser();
    $st = db()->prepare('SELECT * FROM apps WHERE owner_id = ? ORDER BY created_at DESC');
    $st->execute([$userId]);
    $rows = $st->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $sc = db()->prepare('SELECT scope FROM app_scopes WHERE app_id = ?');
        $sc->execute([$r['id']]);
        $out[] = [
            'client_id'  => $r['client_id'],
            'name'       => $r['name'],
            'description'=> $r['description'],
            'icon'       => $r['icon'] ?? null,
            'callback'   => $r['callback_url'],
            'homepage'   => $r['homepage'],
            'status'     => (int)$r['status'],
            'status_text'=> ['开发中', '已上线', '已吊销'][(int)$r['status'] - 1] ?? '未知',
            'scopes'     => array_column($sc->fetchAll(), 'scope'),
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }
    ok(['apps' => $out]);
}

/**
 * POST /apps/create 创建应用
 * { name, description, callback_url, homepage, scopes: [] }
 * 返回 client_id + client_secret（只显示一次）
 */
function appsCreate(): void
{
    $userId = requireUser();
    $name   = trim(param('name', ''));
    $desc   = trim(param('description', ''));
    $cb     = trim(param('callback_url', ''));
    $home   = trim(param('homepage', ''));
    $scopes = param('scopes', []);

    if (mb_strlen($name) < 2 || mb_strlen($name) > 30) {
        fail(42001, '应用名称需 2-30 个字符', 400);
    }
    if (!preg_match('#^https?://#i', $cb)) {
        fail(42002, '回调地址需以 http:// 或 https:// 开头', 400);
    }
    if (filter_var($home, FILTER_VALIDATE_URL) === false && $home !== '') {
        fail(42003, '应用主页格式不正确', 400);
    }
    $scopes = sanitizeScopes((array)$scopes);

    $clientId = genClientId();
    $secret   = genSecret();

    $db = db();
    $st = $db->prepare('INSERT INTO apps (client_id, client_secret_hash, owner_id, name, description, callback_url, homepage, status) VALUES (?,?,?,?,?,?,?,1)');
    $st->execute([$clientId, hashSecret($secret), $userId, $name, $desc, $cb, $home]);
    $appId = (int)$db->lastInsertId();

    $si = $db->prepare('INSERT INTO app_scopes (app_id, scope) VALUES (?,?)');
    foreach ($scopes as $s) $si->execute([$appId, $s]);

    ok([
        'client_id'     => $clientId,
        'client_secret' => $secret,  // 只此一次
        'name'          => $name,
        'scopes'        => $scopes,
    ]);
}

/**
 * POST /apps/update 更新应用
 * { client_id, name?, description?, callback_url?, homepage?, scopes? }
 */
function appsUpdate(): void
{
    $userId = requireUser();
    $clientId = param('client_id', '');

    $app = db()->prepare('SELECT * FROM apps WHERE client_id = ? AND owner_id = ? LIMIT 1');
    $app->execute([$clientId, $userId]);
    $app = $app->fetch();
    if (!$app) fail(42004, '应用不存在或无权操作', 404);

    $fields = [];
    $values = [];
    foreach ([
        'name' => 'name', 'description' => 'description',
        'callback_url' => 'callback_url', 'homepage' => 'homepage',
    ] as $input => $col) {
        $v = param($input);
        if ($v !== null) {
            $v = trim((string)$v);
            // 与创建时一致的格式校验
            if ($input === 'name' && (mb_strlen($v) < 2 || mb_strlen($v) > 30)) {
                fail(42001, '应用名称需 2-30 个字符', 400);
            }
            if ($input === 'callback_url' && !preg_match('#^https?://#i', $v)) {
                fail(42002, '回调地址需以 http:// 或 https:// 开头', 400);
            }
            if ($input === 'homepage' && $v !== '' && filter_var($v, FILTER_VALIDATE_URL) === false) {
                fail(42003, '应用主页格式不正确', 400);
            }
            $fields[] = "$col = ?";
            $values[] = $v;
        }
    }
    if ($fields) {
        $values[] = $app['id'];
        db()->prepare('UPDATE apps SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);
    }

    $scopes = param('scopes');
    if (is_array($scopes)) {
        $scopes = sanitizeScopes($scopes);
        db()->prepare('DELETE FROM app_scopes WHERE app_id = ?')->execute([$app['id']]);
        $si = db()->prepare('INSERT INTO app_scopes (app_id, scope) VALUES (?,?)');
        foreach ($scopes as $s) $si->execute([$app['id'], $s]);
    }

    ok();
}

/**
 * POST /apps/delete 删除应用（不可逆）
 * { client_id }
 */
function appsDelete(): void
{
    $userId = requireUser();
    $clientId = param('client_id', '');

    $app = db()->prepare('SELECT id FROM apps WHERE client_id = ? AND owner_id = ? LIMIT 1');
    $app->execute([$clientId, $userId]);
    $app = $app->fetch();
    if (!$app) fail(42004, '应用不存在或无权操作', 404);

    $db = db();
    $db->beginTransaction();
    $db->prepare('DELETE FROM app_scopes WHERE app_id = ?')->execute([$app['id']]);
    $db->prepare('DELETE FROM api_keys WHERE app_id = ?')->execute([$app['id']]);
    $db->prepare('DELETE FROM authorizations WHERE app_id = ?')->execute([$app['id']]);
    $db->prepare('DELETE FROM oauth_tokens WHERE app_id = ?')->execute([$app['id']]);
    $db->prepare('DELETE FROM apps WHERE id = ?')->execute([$app['id']]);
    $db->commit();

    ok();
}

/**
 * POST /apps/icon 上传应用图标（转发 imgbb 图床）
 * multipart: icon=<file> + client_id，需登录态（应用 owner）
 */
function appsIcon(): void
{
    $userId = requireUser();
    $clientId = (string)param('client_id', '');

    $app = db()->prepare('SELECT * FROM apps WHERE client_id = ? AND owner_id = ? LIMIT 1');
    $app->execute([$clientId, $userId]);
    $app = $app->fetch();
    if (!$app) fail(42004, '应用不存在或无权操作', 404);

    if (empty($_FILES['icon']) || ($_FILES['icon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail(41009, '请选择要上传的图片', 400);
    }

    $file = $_FILES['icon'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        fail(41010, '仅支持 JPG/PNG/GIF/WebP 格式', 400);
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        fail(41011, '图片不能超过 2MB，请压缩后上传', 400);
    }

    // 转 base64 上传 imgbb
    $b64 = base64_encode(file_get_contents($file['tmp_name']));
    $ch = curl_init('https://api.imgbb.com/1/upload?key=' . cfg('imgbb_key'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => http_build_query(['image' => $b64]),
    ]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if (!$data || !($data['success'] ?? false)) {
        error_log('[app-icon] imgbb 上传失败: ' . ($curlErr ?: ($resp ?: 'empty')));
        fail(41012, '图床上传失败，请稍后再试', 502);
    }

    $url = $data['data']['url'];
    db()->prepare('UPDATE apps SET icon = ? WHERE id = ?')->execute([$url, $app['id']]);

    ok(['icon' => $url, 'delete_url' => $data['data']['delete_url'] ?? null]);
}

/**
 * POST /apps/icon_remove 移除应用图标
 * { client_id }
 */
function appsIconRemove(): void
{
    $userId = requireUser();
    $clientId = (string)param('client_id', '');

    $app = db()->prepare('SELECT * FROM apps WHERE client_id = ? AND owner_id = ? LIMIT 1');
    $app->execute([$clientId, $userId]);
    $app = $app->fetch();
    if (!$app) fail(42004, '应用不存在或无权操作', 404);

    db()->prepare('UPDATE apps SET icon = NULL WHERE id = ?')->execute([$app['id']]);
    ok();
}
