<?php
/**
 * OAuth 2.0 授权码流程：authorize / token / refresh / revoke
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/redis.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/scopes.php';
require_once __DIR__ . '/../lib/oidc.php';

/**
 * GET /oauth/authorize
 * 参数: response_type, client_id, redirect_uri, scope, state
 * 需要登录态（session cookie）→ 展示确认页或直接放行已授权应用
 */
function oauthAuthorize(): void
{
    $responseType = param('response_type', '');
    $clientId     = param('client_id', '');
    $redirectUri  = param('redirect_uri', '');
    $scope        = param('scope', 'basic');
    // OIDC 标准 scope（openid/profile/email）归一化为 basic：
    // 授权页显示、授权记录、直过判断、token 回显统一为应用权限，避免出现英文 scope 裸词
    $scope = implode(',', array_values(array_unique(array_filter(array_map(
        fn($s) => in_array($s, ['openid', 'profile', 'email'], true) ? 'basic' : $s,
        preg_split('/[\s,]+/', $scope)
    )))));
    $state        = param('state', '');

    // 安全：client_id / redirect_uri 未通过校验前，绝不 302 到外部地址（防开放重定向）
    if ($responseType !== 'code') {
        oauthErrorRedirect('', 'unsupported_response_type', 'response_type 仅支持 code', $state);
        return;
    }
    if (!$clientId) {
        oauthErrorRedirect('', 'invalid_request', '缺少 client_id', $state);
        return;
    }

    $app = db()->prepare('SELECT * FROM apps WHERE client_id = ? AND status IN (1,2) LIMIT 1');
    $app->execute([$clientId]);
    $app = $app->fetch();
    if (!$app) {
        // 应用未知 → 渲染本地错误页，不反射任何外部 redirect_uri
        oauthErrorRedirect('', 'unauthorized_client', '应用不存在或未上线', $state);
        return;
    }
    // 回调地址必须与注册一致（支持逗号分隔多回调白名单）
    $registered = array_values(array_filter(array_map('trim', explode(',', $app['callback_url']))));
    if (!in_array($redirectUri, $registered, true)) {
        // 回跳只允许落到本应用已注册的回调；未注册的 redirect_uri 一律本地报错
        oauthErrorRedirect('', 'invalid_request', 'redirect_uri 与注册地址不一致', $state);
        return;
    }

    // 校验 scope 是否在应用权限内（OIDC 标准 scope 映射为 basic，不要求应用显式申请）
    $st = db()->prepare('SELECT scope FROM app_scopes WHERE app_id = ?');
    $st->execute([$app['id']]);
    $allowed = array_column($st->fetchAll(), 'scope');
    if (!in_array('basic', $allowed)) $allowed[] = 'basic';
    // scope 已在入口归一化（openid/profile/email → basic），这里做最终白名单校验
    $requested = array_filter(array_map('trim', explode(',', $scope)));
    foreach ($requested as $s) {
        if (!in_array($s, $allowed, true)) {
            // 此时 redirect_uri 已确认在注册白名单内，可安全回跳
            oauthErrorRedirect($redirectUri, 'invalid_scope', "权限 $s 应用未申请", $state);
            return;
        }
    }

    // 登录态检查（session）
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        // 未登录 → 跳登录页，登录后回跳授权
        $back = APP_BASE . '/api/oauth/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => $clientId,
            'redirect_uri' => $redirectUri, 'scope' => $scope, 'state' => $state,
        ]);
        header('Location: ' . APP_BASE . '/login.php?next=' . urlencode($back));
        exit;
    }

    // 已登录：检查是否已授权该应用 → 已授权且权限覆盖当前请求则直接发码
    $requestedArr = array_values(array_unique(array_map('trim', $requested)));
    $auth = db()->prepare('SELECT scopes FROM authorizations WHERE user_id = ? AND app_id = ? AND status = 1 LIMIT 1');
    $auth->execute([$userId, $app['id']]);
    $authRow = $auth->fetch();
    if ($authRow) {
        $granted = array_values(array_unique(array_filter(array_map('trim', explode(',', $authRow['scopes'])))));
        // 已授权权限必须覆盖当前应用申请的权限，才可无感直过
        $covered = count(array_diff($requestedArr, $granted)) === 0;
        if ($covered) {
            $code = issueAuthCode($userId, $app, $redirectUri, implode(',', $requested), $state);
            header('Location: ' . $redirectUri . (strpos($redirectUri, '?') !== false ? '&' : '?') . 'code=' . urlencode($code) . ($state ? '&state=' . urlencode($state) : ''));
            exit;
        }
        // 应用有新增权限（不在已授权范围内）→ 不直过，落到授权页重新征得同意
    }

    // 未授权 → 展示授权确认页（HTML）
    $appName = htmlspecialchars($app['name']);
    $appDesc = htmlspecialchars($app['description']);
    $scopesHtml = '';
    $scopeLabels = scopeDefs();
    // 默认勾选应用申请的所有权限；basic 必选不可取消
    foreach ($requested as $s) {
        $label = $scopeLabels[$s] ?? [$s, ''];
        $isBasic = ($s === 'basic');
        $checked = $isBasic ? 'checked disabled' : 'checked';
        $hint = $isBasic ? ' <span style="opacity:.55;font-size:11px;">(必选)</span>' : '';
        $scopesHtml .= '<div class="scope-item"><label class="scope-line" style="display:flex;align-items:center;gap:10px;cursor:pointer;">'
                     . '<input type="checkbox" name="scopes[]" value="' . htmlspecialchars($s) . '" ' . $checked . ' style="width:17px;height:17px;accent-color:#ffa726;">'
                     . '<span style="flex:1;"><span class="t">' . $label[0] . '</span><span class="d">' . $label[1] . '</span>' . $hint . '</span>'
                     . '</label></div>';
    }

    $token = bin2hex(random_bytes(16));
    $_SESSION['oauth_confirm_' . $token] = [
        'user_id' => $userId, 'app_id' => $app['id'], 'client_id' => $clientId,
        'redirect_uri' => $redirectUri, 'scope' => implode(',', $requested), 'state' => $state,
    ];

    // 应用 Logo：有图标显示图片，否则用占位首字母
    $appLogo = '<div class="logo">A</div>';
    if (!empty($app['icon'])) {
        $iconUrl = htmlspecialchars($app['icon']);
        $appLogo = '<div class="logo" style="overflow:hidden;"><img src="' . $iconUrl . '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:16px;"></div>';
    }

    header('Content-Type: text/html; charset=utf-8');

    // 主题：优先读 auth_theme cookie，缺省跟随系统偏好
    $themeCookie = $_COOKIE['auth_theme'] ?? '';
    $themeClass = match ($themeCookie) {
        'light' => 'mdui-theme-light',
        'dark'  => 'mdui-theme-dark',
        default => 'mdui-theme-auto',
    };

    echo <<<HTML
<!DOCTYPE html><html lang="zh-CN" class="$themeClass"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>授权确认 · Auth Center</title>
<link rel="stylesheet" href="/docs.css"><style>
:root{
  --ac-bg:#000; --ac-fg:#e8e4ee; --ac-card:#15151a; --ac-border:#26262e;
  --ac-scope:#1d1d24; --ac-sub:#8a8595; --ac-deny-border:#3a3a46; --ac-deny-fg:#c9c2d4;
}
.mdui-theme-light{
  --ac-bg:#f5f1fa; --ac-fg:#1c1b20; --ac-card:#ffffff; --ac-border:#e4e0ea;
  --ac-scope:#f3eff8; --ac-sub:#6f6a78; --ac-deny-border:#d5d0dd; --ac-deny-fg:#4a4552;
}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--ac-bg);color:var(--ac-fg);font-family:system-ui,sans-serif;padding:20px;transition:background .2s,color .2s}
.card{width:100%;max-width:420px;background:var(--ac-card);border:1px solid var(--ac-border);border-radius:20px;padding:28px;transition:background .2s,border-color .2s}
.logo{width:52px;height:52px;border-radius:16px;background:linear-gradient(135deg,#ffb74d,#ff7043);display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;color:#3a1d00;margin-bottom:18px}
h1{font-size:20px;margin:0 0 6px}
.app-domain{font-size:12px;color:var(--ac-sub);margin-bottom:18px}
.scope-item{background:var(--ac-scope);border-radius:12px;padding:12px 14px;margin-bottom:10px;transition:background .2s}
.scope-item .t{font-size:14px;font-weight:600}
.scope-item .d{font-size:12px;opacity:.6;margin-top:3px;line-height:1.6}
form.actions{display:flex;gap:12px;margin-top:24px}
form.actions button{
  flex:1;padding:13px 16px;border:none;border-radius:14px;
  font-size:15px;font-weight:700;cursor:pointer;
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  transition:transform .15s ease, box-shadow .15s ease, filter .15s ease;
  -webkit-tap-highlight-color:transparent;
}
.allow{
  background:linear-gradient(135deg,#ffb74d,#ff7043);color:#3a1d00;
  box-shadow:0 6px 20px rgba(255,140,60,.35);
}
.allow:hover{filter:brightness(1.06);transform:translateY(-1px);box-shadow:0 8px 26px rgba(255,140,60,.45)}
.allow:active{transform:scale(.97)}
.deny{
  background:transparent;color:var(--ac-deny-fg);
  border:1px solid var(--ac-deny-border) !important;
}
.deny:hover{background:rgba(128,128,128,.08);border-color:var(--ac-deny-border)}
.deny:active{transform:scale(.97)}
.actions .ic{font-size:18px}
</style></head><body>
<div class="card">
  $appLogo
  <h1>$appName 请求授权</h1>
  <div class="app-domain">$appDesc</div>
  $scopesHtml
  <form method="POST" action="/api/oauth/consent" class="actions">
    <input type="hidden" name="token" value="$token">
    <button type="submit" name="decision" value="allow" class="allow"><span class="ic">✓</span>同意授权</button>
    <button type="submit" name="decision" value="deny" class="deny"><span class="ic">✕</span>拒绝</button>
  </form>
</div></body></html>
HTML;
    exit;
}

/**
 * POST /oauth/consent
 * 授权确认页提交
 */
function oauthConsent(): void
{
    session_start();
    $token    = param('token', '');
    $decision = param('decision', '');
    $key = 'oauth_confirm_' . $token;
    if (empty($token) || empty($_SESSION[$key])) {
        fail(40001, '授权会话已失效，请重新发起授权', 400);
    }
    $ctx = $_SESSION[$key];
    unset($_SESSION[$key]);

    if ($decision !== 'allow') {
        oauthErrorRedirect($ctx['redirect_uri'], 'access_denied', '用户拒绝了授权', $ctx['state']);
    }

    // 用户勾选的权限（授权页 checkbox name=scopes[]，basic 必选已强制勾上）
    // 与应用申请的范围取交集，并保证 basic 兜底
    $requested = array_filter(array_map('trim', explode(',', $ctx['scope'] ?? '')));
    $picked    = (array)param('scopes', []);
    $picked    = array_values(array_unique(array_filter($picked, fn($s) => in_array($s, $requested, true))));
    // basic 必选，且保证排在最前
    if (!in_array('basic', $picked, true)) {
        array_unshift($picked, 'basic');
    }
    $grantScope = implode(',', $picked);

    // 写入授权关系（UPSERT），存用户最终同意的权限
    $db = db();
    $st = $db->prepare('INSERT INTO authorizations (user_id, app_id, scopes, status) VALUES (?,?,?,1)
                        ON DUPLICATE KEY UPDATE scopes = VALUES(scopes), status = 1, updated_at = NOW()');
    $st->execute([$ctx['user_id'], $ctx['app_id'], $grantScope]);

    $app = $db->prepare('SELECT * FROM apps WHERE id = ? LIMIT 1');
    $app->execute([$ctx['app_id']]);
    $app = $app->fetch();
    if (!$app) {
        fail(40002, '应用不存在或已删除，请重新发起授权', 400);
    }

    $code = issueAuthCode($ctx['user_id'], $app, $ctx['redirect_uri'], $grantScope, $ctx['state']);
    header('Location: ' . $ctx['redirect_uri'] . (strpos($ctx['redirect_uri'], '?') !== false ? '&' : '?') . 'code=' . urlencode($code) . ($ctx['state'] ? '&state=' . urlencode($ctx['state']) : ''));
    exit;
}

/**
 * 签发授权码
 */
function issueAuthCode(int $userId, array $app, string $redirectUri, string $scope, ?string $state): string
{
    $code = randToken(32);
    $db = db();
    $st = $db->prepare('INSERT INTO oauth_codes (code, user_id, app_id, client_id, redirect_uri, scopes, state, expires_at) VALUES (?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ' . AUTH_CODE_TTL . ' SECOND))');
    $st->execute([$code, $userId, $app['id'], $app['client_id'], $redirectUri, $scope, $state]);
    return $code;
}

/**
 * POST /oauth/token
 * 授权码换令牌 / 刷新令牌
 */
function oauthToken(): void
{
    // 限流：每 IP 每分钟 30 次
    if (!rateLimit('token:' . clientIp(), 30, 60)) {
        fail(40010, '请求过于频繁，请稍后再试', 429);
    }

    $grantType = param('grant_type', '');
    $clientId  = param('client_id', '');
    $clientSec = param('client_secret', '');

    $app = db()->prepare('SELECT * FROM apps WHERE client_id = ? AND status != 3 LIMIT 1');
    $app->execute([$clientId]);
    $app = $app->fetch();
    if (!$app) {
        securityLog('token.client_invalid', ['client_id' => $clientId]);
        fail(40003, 'client_id 不存在或应用已吊销', 401);
    }
    if (!hash_equals($app['client_secret_hash'], hashSecret($clientSec))) {
        securityLog('token.secret_mismatch', ['client_id' => $clientId, 'app_id' => $app['id']]);
        fail(40004, 'client_secret 错误', 401);
    }

    if ($grantType === 'authorization_code') {
        $code = param('code', '');
        $redirectUri = param('redirect_uri', '');
        $st = db()->prepare('SELECT * FROM oauth_codes WHERE code = ? AND used = 0 LIMIT 1');
        $st->execute([$code]);
        $row = $st->fetch();
        if (!$row || strtotime($row['expires_at']) < time()) {
            fail(40001, '授权码无效或已过期', 400);
        }
        if ($row['client_id'] !== $clientId) {
            fail(40001, '授权码与应用不匹配', 400);
        }
        // redirect_uri 校验：RFC 6749 允许省略（仅当授权请求带过时才必须一致）
        if ($redirectUri !== '' && $row['redirect_uri'] !== $redirectUri) {
            fail(40001, '授权码与回调不匹配', 400);
        }
        // 原子标记已用，防并发重放（UPDATE 影响 0 行 = 已被抢兑）
        $upd = db()->prepare('UPDATE oauth_codes SET used = 1 WHERE code = ? AND used = 0');
        $upd->execute([$code]);
        if ($upd->rowCount() === 0) {
            fail(40001, '授权码无效或已过期', 400);
        }
        issueTokens($app, $row['user_id'], $row['scopes']);
    } elseif ($grantType === 'refresh_token') {
        $refresh = param('refresh_token', '');
        $hash = hash('sha256', $refresh);
        $st = db()->prepare('SELECT * FROM oauth_tokens WHERE refresh_token_hash = ? AND revoked = 0 LIMIT 1');
        $st->execute([$hash]);
        $row = $st->fetch();
        if (!$row || strtotime($row['refresh_expires_at']) < time()) {
            fail(40002, '刷新令牌无效或已过期', 400);
        }
        if ($row['app_id'] !== $app['id']) {
            fail(40002, '刷新令牌不属于该应用', 400);
        }
        // 重新拉取应用整行，确保 issueTokens 里 id_token 的 aud 使用 client_id（入参 app 可能字段不全）
        $full = db()->prepare('SELECT * FROM apps WHERE id = ? LIMIT 1');
        $full->execute([$row['app_id']]);
        $fullApp = $full->fetch() ?: $app;
        db()->prepare('UPDATE oauth_tokens SET revoked = 1 WHERE id = ?')->execute([$row['id']]);
        issueTokens($fullApp, $row['user_id'], $row['scopes']);
    } else {
        fail(40000, '不支持的 grant_type', 400);
    }
}

/**
 * 签发 access_token + refresh_token
 */
function issueTokens(array $app, int $userId, string $scope): void
{
    $access  = randToken(32);
    $refresh = randToken(40);
    $db = db();
    $st = $db->prepare('INSERT INTO oauth_tokens
        (access_token_hash, refresh_token_hash, user_id, app_id, scopes, access_expires_at, refresh_expires_at)
        VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ' . ACCESS_TOKEN_TTL . ' SECOND), DATE_ADD(NOW(), INTERVAL ' . REFRESH_TOKEN_TTL . ' SECOND))');
    $st->execute([hash('sha256', $access), hash('sha256', $refresh), $userId, $app['id'], $scope]);

    // Redis 缓存 access_token → 用户信息（TTL 与 access_token 一致）
    $user = $db->prepare('SELECT uid, nickname, avatar, email FROM users WHERE id = ? LIMIT 1');
    $user->execute([$userId]);
    $u = $user->fetch();
    redis()->setex(rk('tok:' . hash('sha256', $access)), ACCESS_TOKEN_TTL, json_encode([
        'user_id' => $userId, 'uid' => $u['uid'], 'app_id' => $app['id'], 'scope' => $scope,
    ]));

    // token 端点按 OAuth 2.0 标准返回顶层字段（不做 code/data 包装）
    // OIDC：附带 RS256 签名的 id_token（access_token 保持不透明随机串，不破坏现有对接）
    $out = [
        'access_token'  => $access,
        'token_type'    => 'Bearer',
        'expires_in'    => ACCESS_TOKEN_TTL,
        'refresh_token' => $refresh,
        'scope'         => $scope,
    ];
    if (function_exists('oidcSignJwt')) {
        $out['id_token'] = oidcSignJwt(oidcClaims($u, $app['client_id'], time(), ACCESS_TOKEN_TTL));
    }
    jsonOut($out);
}

/**
 * GET /oauth/userinfo
 * OIDC UserInfo 端点：Authorization: Bearer <access_token>
 * 返回与 id_token 一致的 claims（标准 OIDC 格式）
 */
function oauthUserInfo(): void
{
    $token = bearerToken();
    if (!$token) {
        fail(40005, '缺少 access_token', 401);
    }
    $hash = hash('sha256', $token);
    $cache = redis()->get(rk('tok:' . $hash));
    if ($cache === false) {
        // Redis 未命中 → 回源数据库，避免缓存过期/被清后 userinfo 直接 401
        $st = db()->prepare('SELECT * FROM oauth_tokens WHERE access_token_hash = ? AND revoked = 0 LIMIT 1');
        $st->execute([$hash]);
        $row = $st->fetch();
        if (!$row || strtotime($row['access_expires_at']) < time()) {
            fail(40005, 'access_token 无效或已过期', 401);
        }
        $info = ['user_id' => (int)$row['user_id'], 'app_id' => (int)$row['app_id'], 'scope' => $row['scopes']];
    } else {
        $info = json_decode((string)$cache, true);
        if (!is_array($info)) fail(40005, 'access_token 无效或已过期', 401);
        // 缓存命中也要校验用户/应用/授权状态（与 requireToken 一致），防退出/撤回后仍可用
        if (function_exists('tokenStillValid') && !tokenStillValid($info)) {
            redis()->del(rk('tok:' . $hash));
            fail(40005, 'access_token 已失效', 401);
        }
    }
    $st = db()->prepare('SELECT uid, nickname, avatar, email FROM users WHERE id = ? LIMIT 1');
    $st->execute([$info['user_id']]);
    $u = $st->fetch();
    if (!$u) {
        fail(40005, '用户不存在', 401);
    }
    // aud 用 client_id（查应用行），避免存入 app_id 数字导致 audience 不一致
    $audApp = '';
    $st = db()->prepare('SELECT client_id FROM apps WHERE id = ? LIMIT 1');
    $st->execute([$info['app_id']]);
    $audApp = (string)($st->fetchColumn() ?: '');
    $claims = oidcClaims($u, $audApp, time(), ACCESS_TOKEN_TTL);
    $claims['avatar'] = $u['avatar'];
    unset($claims['aud'], $claims['exp'], $claims['iat'], $claims['auth_time']);
    jsonOut($claims);
}

/**
 * POST /oauth/revoke
 * 吊销令牌
 */
function oauthRevoke(): void
{
    $token = param('token', '');
    if (!$token) fail(40000, '缺少 token', 400);
    $hash = hash('sha256', $token);
    db()->prepare('UPDATE oauth_tokens SET revoked = 1 WHERE access_token_hash = ? OR refresh_token_hash = ?')
        ->execute([$hash, $hash]);
    redis()->del(rk('tok:' . $hash));
    ok();
}

/** 授权失败跳回回调地址（带 error） */
function oauthErrorRedirect(string $redirectUri, string $error, string $desc, ?string $state): void
{
    // 开放重定向防护：$redirectUri 为空时一律本地报错；非空时必须是绝对 http(s) 地址
    // 且不含 CR/LF（防 Header 注入）。调用方只会在「已确认属于注册回调白名单」时
    // 才传入非空值，其余错误分支一律传空串渲染本地错误页。
    $isRemote = $redirectUri !== ''
        && preg_match('#^https?://#i', $redirectUri)
        && !preg_match('/[\r\n]/', $redirectUri);
    if ($isRemote) {
        $sep = (strpos($redirectUri, '?') !== false) ? '&' : '?';
        $url = $redirectUri . $sep . http_build_query([
            'error' => $error, 'error_description' => $desc, 'state' => $state,
        ]);
        header('Location: ' . $url);
        exit;
    }
    oauthErrorPage($error, $desc);
}

/** 本地 OAuth 错误页（不回跳任何外部地址） */
function oauthErrorPage(string $error, string $desc): void
{
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    $e = htmlspecialchars($error, ENT_QUOTES);
    $d = htmlspecialchars($desc, ENT_QUOTES);
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>授权失败 · Auth Center</title>'
       . '<style>body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#0b0b0f;color:#e8e4ee;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
       . '.card{max-width:420px;padding:28px 26px;background:#15151a;border:1px solid #26262e;border-radius:18px;text-align:center}'
       . '.card h1{font-size:18px;margin:0 0 10px}.card p{color:#8a8595;font-size:14px;line-height:1.7;margin:0}'
       . '.code{display:inline-block;margin-top:14px;font-size:12px;color:#ffa726;background:#241c10;border-radius:8px;padding:4px 10px}</style></head>'
       . '<body><div class="card"><h1>授权请求无法继续</h1><p>' . $d . '</p>'
       . '<span class="code">' . $e . '</span></div></body></html>';
    exit;
}
