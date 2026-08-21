<?php
/**
 * 通知接口：应用发邮件给用户
 * -------------------------------------------------
 * POST /api/notify/send          群发：发给所有已授权 notify 权限的用户
 *   client_id / client_secret / title / body
 *
 * POST /api/notify/send_to_user  单发：发给指定用户（需该用户已授权 notify）
 *   client_id / client_secret / title / body / user_id
 *
 * 共同行为：
 *   1. 校验应用凭证（client_id + client_secret）
 *   2. 核查应用是否拥有 notify 权限（app_scopes 表）
 *   3. 限频：按应用滑动窗口限制发送频率
 *   4. 只发给已授权 notify 权限、账号正常、留了邮箱的用户
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/redis.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/mailer.php';

/** 通知发送频率限制（每应用每分钟最多 N 封） */
const NOTIFY_RATE_MAX    = 10;   // 每窗口最多
const NOTIFY_RATE_WINDOW = 60;   // 窗口秒数

/**
 * 校验应用凭证 + notify 权限 + 限频，返回应用或直接 fail 中断
 */
function notifyAuthApp(): array
{
    $clientId  = (string)param('client_id', '');
    $clientSec = (string)param('client_secret', '');

    if ($clientId === '' || $clientSec === '') {
        fail(40000, '缺少 client_id 或 client_secret', 401);
    }

    // 校验应用凭证
    $st = db()->prepare('SELECT * FROM apps WHERE client_id = ? AND status != 3 LIMIT 1');
    $st->execute([$clientId]);
    $app = $st->fetch();
    if (!$app) {
        fail(40003, 'client_id 不存在或应用已吊销', 401);
    }
    if (!hash_equals($app['client_secret_hash'], hashSecret($clientSec))) {
        fail(40004, 'client_secret 错误', 401);
    }

    // 核查应用是否拥有 notify 权限
    $st = db()->prepare('SELECT COUNT(*) FROM app_scopes WHERE app_id = ? AND scope = ? LIMIT 1');
    $st->execute([$app['id'], 'notify']);
    if ((int)$st->fetchColumn() === 0) {
        fail(40000, '该应用未申请 notify（通知）权限', 403);
    }

    // 限频：按应用滑动窗口
    if (!rateLimit('notify:' . $app['id'], NOTIFY_RATE_MAX, NOTIFY_RATE_WINDOW)) {
        fail(40010, '发送过于频繁，请稍后再试', 429);
    }

    return $app;
}

/**
 * 组装邮件内容并发送，返回 [sentMail, error?]
 */
function notifyDeliver(array $app, string $to, string $title, string $body): array
{
    $subject = "[{$app['name']}] {$title}";
    $html  = '<div style="font-family:sans-serif;max-width:600px;margin:auto;border:1px solid #eee;border-radius:10px;overflow:hidden">'
           . '<div style="background:#ffa726;color:#fff;padding:14px 18px;font-weight:700;">' . htmlspecialchars($app['name']) . '</div>'
           . '<div style="padding:20px 18px;color:#333;line-height:1.7;">'
           . $body
           . '</div></div>';

    $r = mailSend($to, $subject, $html);
    if ($r === true) {
        return [true, null];
    }
    return [false, (string)$r];
}

/**
 * POST /notify/send  群发：给所有已授权 notify 权限的用户发邮件
 */
function notifySend(): void
{
    session_start();
    $title = trim((string)param('title', ''));
    $body  = (string)param('body', '');

    if ($title === '') {
        fail(40000, '邮件主题 title 不能为空', 400);
    }
    if ($body === '') {
        fail(40000, '邮件正文 body 不能为空', 400);
    }

    $app = notifyAuthApp();

    // 找到已授权 notify 权限、且账号正常、且留了邮箱的用户
    $st = db()->prepare(
        'SELECT DISTINCT u.id, u.email, u.nickname
         FROM authorizations a
         JOIN users u ON u.id = a.user_id
         WHERE a.app_id = ?
           AND a.status = 1
           AND a.scopes LIKE ?
           AND u.status = 1
           AND u.email IS NOT NULL
           AND u.email != \'\''
    );
    $st->execute([$app['id'], '%notify%']);
    $users = $st->fetchAll();

    if (empty($users)) {
        ok(['sent' => 0, 'message' => '暂无已授权 notify 权限的用户']);
        return;
    }

    // 逐用户发送
    $sent   = 0;
    $failed = [];
    foreach ($users as $u) {
        [$okMail, $errMail] = notifyDeliver($app, $u['email'], $title, $body);
        if ($okMail) {
            $sent++;
        } else {
            $failed[] = ['email' => $u['email'], 'error' => $errMail];
        }
    }

    ok([
        'sent'    => $sent,
        'failed'  => count($failed),
        'failures'=> $failed,
    ]);
}

/**
 * POST /notify/send_to_user  单发：给指定用户发邮件
 * 参数额外需要 user_id（目标用户的数据库 id 或公开 uid 均可）
 */
function notifySendToUser(): void
{
    session_start();
    $title  = trim((string)param('title', ''));
    $body   = (string)param('body', '');
    $userId = trim((string)param('user_id', ''));

    if ($title === '') {
        fail(40000, '邮件主题 title 不能为空', 400);
    }
    if ($body === '') {
        fail(40000, '邮件正文 body 不能为空', 400);
    }
    if ($userId === '') {
        fail(40000, '缺少目标用户 user_id', 400);
    }

    $app = notifyAuthApp();

    // 定位目标用户（id 或 uid 均可）
    $target = (ctype_digit($userId))
        ? ['id' => (int)$userId]
        : ['uid' => $userId];

    $st = db()->prepare('SELECT id, email, nickname, status FROM users WHERE ' . key($target) . ' = ? LIMIT 1');
    $st->execute([current($target)]);
    $user = $st->fetch();
    if (!$user) {
        fail(41008, '目标用户不存在', 404);
    }

    // 账号必须正常且有邮箱
    if ((int)$user['status'] !== 1) {
        fail(40000, '目标用户账号不可用', 403);
    }
    if ($user['email'] === null || $user['email'] === '') {
        fail(40000, '目标用户未绑定邮箱', 400);
    }

    // 核查该用户是否已授权本应用 notify 权限
    $st = db()->prepare(
        'SELECT COUNT(*) FROM authorizations
         WHERE user_id = ? AND app_id = ? AND status = 1 AND scopes LIKE ? LIMIT 1'
    );
    $st->execute([$user['id'], $app['id'], '%notify%']);
    if ((int)$st->fetchColumn() === 0) {
        fail(40020, '目标用户未授权该应用的 notify 权限', 403);
    }

    // 发送
    [$okMail, $errMail] = notifyDeliver($app, $user['email'], $title, $body);
    if (!$okMail) {
        fail(50000, '发送失败: ' . (string)$errMail, 502);
    }

    ok([
        'sent'   => 1,
        'email'  => $user['email'],
        'user'   => ['id' => (int)$user['id'], 'nickname' => $user['nickname']],
    ]);
}
