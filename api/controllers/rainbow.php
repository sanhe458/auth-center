<?php
/**
 * 彩虹聚合登录（login.9o3.cn）
 * 一个 APPID 支持 QQ/微信/支付宝/微博/抖音/Gitee 等第三方登录
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/redis.php';
require_once __DIR__ . '/../lib/helpers.php';

const RAINBOW_API = 'https://login.9o3.cn/connect.php';

/**
 * 彩虹 API 地址（后台可配，settings 表 rainbow_api，缺省回退常量）
 */
function rainbowApi(): string
{
    return rtrim((string)cfg('rainbow_api', RAINBOW_API), '/');
}

/**
 * GET /oauth/rainbow?type=qq|wx|alipay|...
 * 获取跳转地址并重定向
 */
function rainbowLogin(): void
{
    $type = param('type', 'qq');
    $allowed = ['qq', 'wx', 'alipay', 'sina', 'baidu', 'douyin', 'xiaomi', 'google', 'microsoft', 'gitee', 'github'];
    if (!in_array($type, $allowed, true)) {
        fail(45003, '不支持的登录方式', 400);
    }

    $params = http_build_query([
        'act'         => 'login',
        'appid'       => cfg('rainbow_appid'),
        'appkey'      => cfg('rainbow_appkey'),
        'type'        => $type,
        'redirect_uri'=> APP_BASE . '/api/oauth/rainbow/callback',
    ]);
    $resp = @file_get_contents(rainbowApi() . '?' . $params);
    $data = json_decode((string)$resp, true);

    if (empty($data['url'])) {
        fail(45004, '获取登录地址失败: ' . ($data['msg'] ?? '未知错误'), 502);
    }

    // 存 type 到 session，回调时校验
    session_start();
    $_SESSION['rainbow_type'] = $type;

    header('Location: ' . $data['url']);
    exit;
}

/**
 * GET /oauth/rainbow/callback?type=qq&code=xxx
 * 授权码换用户信息，登录/绑定
 */
function rainbowCallback(): void
{
    session_start();

    $type = $_GET['type'] ?? ($_SESSION['rainbow_type'] ?? '');
    $code = $_GET['code'] ?? '';
    if ($code === '') {
        header('Location: ' . APP_BASE . '/login.php?error=rainbow_no_code');
        exit;
    }

    // 换用户信息
    $params = http_build_query([
        'act'    => 'callback',
        'appid'  => cfg('rainbow_appid'),
        'appkey' => cfg('rainbow_appkey'),
        'type'   => $type,
        'code'   => $code,
    ]);
    $resp = @file_get_contents(rainbowApi() . '?' . $params);
    $data = json_decode((string)$resp, true);

    if (empty($data['social_uid'])) {
        header('Location: ' . APP_BASE . '/login.php?error=rainbow_failed');
        exit;
    }

    $socialUid = (string)$data['social_uid'];
    $nickname  = $data['nickname'] ?? ($type . '_user');
    $avatar    = $data['faceimg'] ?? null;
    $provider  = $type;

    $db = db();

    // 已登录用户 → 直接绑定到当前账号
    if (!empty($_SESSION['user_id'])) {
        $st = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int)$_SESSION['user_id']]);
        if ($st->fetch()) {
            // 该第三方账号已被其他用户绑定则拒绝
            $st = $db->prepare('SELECT user_id FROM social_bindings WHERE provider = ? AND social_uid = ? AND user_id != ? LIMIT 1');
            $st->execute([$provider, $socialUid, (int)$_SESSION['user_id']]);
            if ($st->fetch()) {
                header('Location: ' . APP_BASE . '/user/bindings.php?error=social_used');
                exit;
            }
            // UPSERT 绑定
            $db->prepare('INSERT INTO social_bindings (user_id, provider, social_uid, nickname, avatar) VALUES (?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), nickname = VALUES(nickname), avatar = VALUES(avatar)')
                ->execute([(int)$_SESSION['user_id'], $provider, $socialUid, $nickname, $avatar]);
            header('Location: ' . APP_BASE . '/user/bindings.php?msg=bound');
            exit;
        }
    }

    // 按 social_uid 找已有绑定
    $st = $db->prepare('SELECT b.user_id, u.status, u.nickname AS uname FROM social_bindings b JOIN users u ON u.id = b.user_id WHERE b.provider = ? AND b.social_uid = ? LIMIT 1');
    $st->execute([$provider, $socialUid]);
    $bind = $st->fetch();

    if ($bind) {
        // 已绑定 → 直接登录
        if ((int)$bind['status'] !== 1) {
            header('Location: ' . APP_BASE . '/login.php?error=account_disabled');
            exit;
        }
        session_regenerate_id(true);
        $_SESSION['user_id']  = (int)$bind['user_id'];
        $_SESSION['nickname'] = $bind['uname'];
        header('Location: ' . APP_BASE . '/user/index.php');
        exit;
    }

    // 新第三方账号 → 跳绑定页（绑定已有账号或注册新账号）
    $_SESSION['social_pending'] = [
        'provider'   => $provider,
        'social_uid' => $socialUid,
        'nickname'   => $nickname,
        'avatar'     => $avatar,
    ];
    header('Location: ' . APP_BASE . '/social-bind.php?provider=' . urlencode($provider));
    exit;
}
