<?php
/**
 * GitHub OAuth 登录
 * 流程：跳转 GitHub 授权 → 回调拿 code → 换 token → 取用户信息 → 关联/创建本地账号
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/redis.php';
require_once __DIR__ . '/../lib/helpers.php';

/**
 * GET /oauth/github 跳转 GitHub 授权页
 */
function githubLogin(): void
{
    $clientId = cfg('github_client_id');
    if (!$clientId) {
        fail(45001, 'GitHub 登录未配置', 500);
    }

    // 生成 state 防 CSRF，存 session
    session_start();
    $state = randToken(16);
    $_SESSION['github_oauth_state'] = $state;

    $params = http_build_query([
        'client_id'    => $clientId,
        'redirect_uri' => APP_BASE . '/api/oauth/github/callback',
        'scope'        => 'read:user user:email',
        'state'        => $state,
    ]);
    header('Location: https://github.com/login/oauth/authorize?' . $params);
    exit;
}

/**
 * GET /oauth/github/callback GitHub 回调
 */
function githubCallback(): void
{
    session_start();

    // ① 校验 state（防 CSRF）
    $state = $_GET['state'] ?? '';
    if ($state === '' || $state !== ($_SESSION['github_oauth_state'] ?? '')) {
        fail(45002, 'state 校验失败，请重新尝试', 400);
    }
    unset($_SESSION['github_oauth_state']);

    // ② 用户拒绝授权
    if (isset($_GET['error'])) {
        header('Location: ' . APP_BASE . '/login.php?error=github_denied');
        exit;
    }

    // ③ code 换 access_token
    $code = $_GET['code'] ?? '';
    if ($code === '') {
        header('Location: ' . APP_BASE . '/login.php?error=github_no_code');
        exit;
    }

    $ch = curl_init('https://github.com/login/oauth/access_token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => cfg('github_client_id'),
            'client_secret' => cfg('github_client_secret'),
            'code'          => $code,
            'redirect_uri'  => APP_BASE . '/api/oauth/github/callback',
        ]),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $tok = json_decode((string)$resp, true);
    if (empty($tok['access_token'])) {
        header('Location: ' . APP_BASE . '/login.php?error=github_token_failed');
        exit;
    }
    $accessToken = $tok['access_token'];

    // ④ 取 GitHub 用户信息
    $ch = curl_init('https://api.github.com/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/vnd.github+json',
            'User-Agent: AuthCenter',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
    ]);
    $userResp = curl_exec($ch);
    curl_close($ch);
    $gh = json_decode((string)$userResp, true);
    if (empty($gh['id'])) {
        header('Location: ' . APP_BASE . '/login.php?error=github_user_failed');
        exit;
    }

    $githubId   = (string)$gh['id'];
    $nickname   = $gh['login'] ?? 'github_' . substr($githubId, -4);
    $name       = $gh['name'] ?: $nickname;
    $avatar     = $gh['avatar_url'] ?? null;
    $email      = $gh['email'] ?? '';

    // ⑤ 邮箱：GitHub 可能不返回公开邮箱，尝试取主邮箱
    if (!$email) {
        $ch = curl_init('https://api.github.com/user/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/vnd.github+json',
                'User-Agent: AuthCenter',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);
        $emails = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (is_array($emails)) {
            foreach ($emails as $e) {
                if (!empty($e['primary']) && !empty($e['email'])) {
                    $email = $e['email'];
                    break;
                }
            }
        }
    }

    $db = db();

    // ⑤.5 已登录用户授权 GitHub → 直接绑定到当前账号（控制台"绑定渠道"入口）
    if (!empty($_SESSION['user_id'])) {
        $st = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int)$_SESSION['user_id']]);
        $cur = $st->fetch();
        if ($cur) {
            // 该 GitHub 已被其他账号绑定则拒绝
            $st = $db->prepare('SELECT id FROM users WHERE github_id = ? AND id != ? LIMIT 1');
            $st->execute([$githubId, $cur['id']]);
            if ($st->fetch()) {
                header('Location: ' . APP_BASE . '/user/bindings.php?error=gh_used');
                exit;
            }
            $db->prepare('UPDATE users SET github_id = ?, avatar = COALESCE(avatar, ?) WHERE id = ?')
                ->execute([$githubId, $avatar, $cur['id']]);
            header('Location: ' . APP_BASE . '/user/bindings.php?msg=bound');
            exit;
        }
    }

    // ⑥ 按 github_id 找已有账号
    $st = $db->prepare('SELECT * FROM users WHERE github_id = ? LIMIT 1');
    $st->execute([$githubId]);
    $user = $st->fetch();

    if (!$user && $email) {
        // 按邮箱找（同邮箱的本地账号可绑定 GitHub）
        $st = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch();
        if ($user) {
            $db->prepare('UPDATE users SET github_id = ? WHERE id = ?')->execute([$githubId, $user['id']]);
        }
    }

    if (!$user) {
        // ⑦ 新 GitHub 账号：不直接注册，跳绑定页让用户选择
        //    绑定已有账号（输邮箱+密码）或直接注册新账号
        $_SESSION['gh_pending'] = [
            'github_id' => $githubId,
            'nickname'  => $name,
            'email'     => $email,
            'avatar'    => $avatar,
        ];
        header('Location: ' . APP_BASE . '/github-bind.php');
        exit;
    } else {
        $userId = (int)$user['id'];
        // 更新头像/昵称
        if ($avatar) {
            $db->prepare('UPDATE users SET avatar = ?, nickname = ? WHERE id = ?')
                ->execute([$avatar, $name, $userId]);
        }
    }

    if ((int)$user['status'] !== 1) {
        header('Location: ' . APP_BASE . '/login.php?error=account_disabled');
        exit;
    }

    // ⑧ 建立登录会话
    session_regenerate_id(true);
    $_SESSION['user_id']  = $userId;
    $_SESSION['nickname'] = $name;

    header('Location: ' . APP_BASE . '/user/index.php');
    exit;
}
