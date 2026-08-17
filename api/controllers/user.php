<?php
/**
 * 用户接口：注册 / 登录 / 登出 / 用户信息
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/redis.php';
require_once __DIR__ . '/../lib/helpers.php';

/**
 * POST /user/register
 * { nickname, email, password }
 */
function userRegister(): void
{
    $nickname = trim(param('nickname', ''));
    $email    = strtolower(trim(param('email', '')));
    $password = param('password', '');

    if (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 30) {
        fail(41001, '昵称需 2-30 个字符', 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail(41002, '邮箱格式不正确', 400);
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        fail(41003, '密码需 8-72 位', 400);
    }
    if (!rateLimit('reg:' . clientIp(), 10, 3600)) {
        fail(40010, '注册过于频繁', 429);
    }

    $db = db();
    $st = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    if ($st->fetch()) {
        fail(41004, '该邮箱已注册', 409);
    }

    $uid = genUid();
    $st = $db->prepare('INSERT INTO users (uid, nickname, email, password_hash) VALUES (?,?,?,?)');
    $st->execute([$uid, $nickname, $email, password_hash($password, PASSWORD_DEFAULT)]);

    $userId = (int)$db->lastInsertId();
    // 自动登录
    session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['nickname'] = $nickname;

    ok(['id' => $uid, 'nickname' => $nickname, 'email' => $email]);
}

/**
 * POST /user/login
 * { email, password }
 */
function userLogin(): void
{
    $email    = strtolower(trim(param('email', '')));
    $password = param('password', '');

    if (!rateLimit('login:' . clientIp(), 20, 60)) {
        fail(40010, '尝试过于频繁，请稍后再试', 429);
    }

    $st = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $user = $st->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        fail(41005, '邮箱或密码错误', 401);
    }
    if ((int)$user['status'] !== 1) {
        fail(41006, '账号已被禁用', 403);
    }

    session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['nickname'] = $user['nickname'];

    ok([
        'id' => $user['uid'],
        'nickname' => $user['nickname'],
        'avatar' => $user['avatar'],
        'email' => $user['email'],
    ]);
}

/**
 * POST /user/logout
 */
function userLogout(): void
{
    session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    ok();
}

/**
 * GET /user/me
 * 当前登录态用户（session）
 */
function userMe(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        fail(41007, '未登录', 401);
    }
    $st = db()->prepare('SELECT uid, nickname, avatar, email, created_at FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $u = $st->fetch();
    if (!$u) fail(41008, '用户不存在', 404);

    ok([
        'id' => $u['uid'],
        'nickname' => $u['nickname'],
        'avatar' => $u['avatar'],
        'email' => $u['email'],
        'created_at' => $u['created_at'],
    ]);
}

/**
 * POST /user/avatar 上传头像（转发 imgbb 图床）
 * multipart: avatar=<file>，需登录态
 */
function userAvatarUpload(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        fail(41007, '未登录', 401);
    }

    if (empty($_FILES['avatar']) || ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail(41009, '请选择要上传的图片', 400);
    }

    $file = $_FILES['avatar'];
    // 校验类型
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        fail(41010, '仅支持 JPG/PNG/GIF/WebP 格式', 400);
    }
    // 大小限制 2MB
    if ($file['size'] > 2 * 1024 * 1024) {
        fail(41011, '图片不能超过 2MB', 400);
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
        error_log('[avatar] imgbb 上传失败: ' . ($curlErr ?: ($resp ?: 'empty')));
        fail(41012, '图床上传失败，请稍后再试', 502);
    }

    $url = $data['data']['url'];
    $deleteUrl = $data['data']['delete_url'] ?? null;

    // 存库
    db()->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$url, (int)$userId]);

    ok([
        'avatar' => $url,
        'delete_url' => $deleteUrl,
    ]);
}

/**
 * DELETE /user/avatar 移除头像
 */
function userAvatarRemove(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        fail(41007, '未登录', 401);
    }
    db()->prepare('UPDATE users SET avatar = NULL WHERE id = ?')->execute([(int)$userId]);
    ok();
}
