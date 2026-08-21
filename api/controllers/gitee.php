<?php
/**
 * Gitee 官方 OAuth 登录
 * 流程：跳转 Gitee 授权 → 回调 code → 换 token → 取用户信息+邮箱 → 登录/绑定
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/redis.php';
require_once __DIR__ . '/../lib/helpers.php';

/**
 * GET /oauth/gitee 跳转 Gitee 授权页
 */
function giteeLogin(): void
{
    session_start();
    $state = randToken(16);
    $_SESSION['gitee_oauth_state'] = $state;

    $params = http_build_query([
        'client_id'    => cfg('gitee_client_id'),
        'redirect_uri' => APP_BASE . '/api/oauth/gitee/callback',
        'response_type'=> 'code',
        'scope'        => 'user_info emails',
        'state'        => $state,
    ]);
    header('Location: https://gitee.com/oauth/authorize?' . $params);
    exit;
}

/**
 * GET /oauth/gitee/callback Gitee 回调
 */
function giteeCallback(): void
{
    session_start();
    error_log('[gitee-cb] params: ' . json_encode($_GET) . ' session_user: ' . ($_SESSION['user_id'] ?? 'none') . ' state_ok: ' . (($_GET['state'] ?? '') === ($_SESSION['gitee_oauth_state'] ?? '') ? 'yes' : 'no'));

    // ① 校验 state（防 CSRF）
    $state = $_GET['state'] ?? '';
    if ($state === '' || $state !== ($_SESSION['gitee_oauth_state'] ?? '')) {
        fail(45005, 'state 校验失败，请重新尝试', 400);
    }
    unset($_SESSION['gitee_oauth_state']);

    // ② 用户拒绝
    if (isset($_GET['error'])) {
        header('Location: ' . APP_BASE . '/login.php?error=gitee_denied');
        exit;
    }

    $code = $_GET['code'] ?? '';
    if ($code === '') {
        header('Location: ' . APP_BASE . '/login.php?error=gitee_no_code');
        exit;
    }

    // ③ code 换 access_token
    $ch = curl_init('https://gitee.com/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => cfg('gitee_client_id'),
            'client_secret' => cfg('gitee_client_secret'),
            'redirect_uri'  => APP_BASE . '/api/oauth/gitee/callback',
        ]),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $tok = json_decode((string)$resp, true);
    error_log('[gitee-cb] token resp: ' . substr((string)$resp, 0, 200));
    if (empty($tok['access_token'])) {
        header('Location: ' . APP_BASE . '/login.php?error=gitee_token_failed');
        exit;
    }
    $accessToken = $tok['access_token'];

    // ④ 取 Gitee 用户信息
    $ch = curl_init('https://gitee.com/api/v5/user?access_token=' . urlencode($accessToken));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['User-Agent: AuthCenter'],
    ]);
    $userResp = curl_exec($ch);
    curl_close($ch);
    $user = json_decode((string)$userResp, true);
    error_log('[gitee-cb] user resp: ' . substr((string)$userResp, 0, 200));
    if (empty($user['id'])) {
        header('Location: ' . APP_BASE . '/login.php?error=gitee_user_failed');
        exit;
    }

    $giteeId  = (string)$user['id'];
    $nickname = $user['name'] ?: ($user['login'] ?? ('gitee_' . substr($giteeId, -4)));
    $avatar   = $user['avatar_url'] ?? null;
    $email    = $user['email'] ?? '';

    // ⑤ 取 Gitee 邮箱（emails 权限）
    if (!$email) {
        $ch = curl_init('https://gitee.com/api/v5/emails?access_token=' . urlencode($accessToken));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['User-Agent: AuthCenter'],
        ]);
        $emails = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (is_array($emails)) {
            foreach ($emails as $e) {
                if (!empty($e['email'])) {
                    $email = $e['email'];
                    break;
                }
            }
        }
    }

    $db = db();

    // ⑥ 已登录用户 → 直接绑定到当前账号
    if (!empty($_SESSION['user_id'])) {
        $st = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int)$_SESSION['user_id']]);
        if ($st->fetch()) {
            $st = $db->prepare('SELECT user_id FROM social_bindings WHERE provider = ? AND social_uid = ? AND user_id != ? LIMIT 1');
            $st->execute(['gitee', $giteeId, (int)$_SESSION['user_id']]);
            if ($st->fetch()) {
                header('Location: ' . APP_BASE . '/user/bindings.php?error=social_used');
                exit;
            }
            $db->prepare('INSERT INTO social_bindings (user_id, provider, social_uid, nickname, avatar) VALUES (?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), nickname = VALUES(nickname), avatar = VALUES(avatar)')
                ->execute([(int)$_SESSION['user_id'], 'gitee', $giteeId, $nickname, $avatar]);
            header('Location: ' . APP_BASE . '/user/bindings.php?msg=bound');
            exit;
        }
    }

    // ⑦ 按 gitee 绑定找已有账号
    $st = $db->prepare('SELECT b.user_id, u.status, u.nickname AS uname FROM social_bindings b JOIN users u ON u.id = b.user_id WHERE b.provider = ? AND b.social_uid = ? LIMIT 1');
    $st->execute(['gitee', $giteeId]);
    $bind = $st->fetch();

    if ($bind) {
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

    // ⑧ 同邮箱本地账号自动绑定（仅限正常状态账号）
    if ($email) {
        $st = $db->prepare('SELECT id, status, nickname FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $local = $st->fetch();
        if ($local && (int)$local['status'] === 1) {
            $db->prepare('INSERT INTO social_bindings (user_id, provider, social_uid, nickname, avatar) VALUES (?,?,?,?,?)')
                ->execute([$local['id'], 'gitee', $giteeId, $nickname, $avatar]);
            session_regenerate_id(true);
            $_SESSION['user_id']  = (int)$local['id'];
            $_SESSION['nickname'] = $local['nickname'];
            header('Location: ' . APP_BASE . '/user/index.php');
            exit;
        }
    }

    // ⑨ 新 Gitee 账号 → 跳绑定页
    $_SESSION['social_pending'] = [
        'provider'   => 'gitee',
        'social_uid' => $giteeId,
        'nickname'   => $nickname,
        'avatar'     => $avatar,
    ];
    header('Location: ' . APP_BASE . '/social-bind.php?provider=gitee');
    exit;
}
