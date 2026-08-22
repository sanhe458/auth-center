<?php
/**
 * 公共布局库：页面头部/顶栏/侧边栏/登录态
 * 所有 PHP 页面共用，服务端渲染
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/** 当前登录用户（session），未登录返回 null */
function currentUser(): ?array
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return null;

    $st = db()->prepare('SELECT id, uid, nickname, avatar, email, role, github_id, created_at FROM users WHERE id = ? AND status = 1 LIMIT 1');
    $st->execute([(int)$userId]);
    $u = $st->fetch();
    return $u ?: null;
}

/** 必须登录，未登录跳登录页 */
function requireLoginPage(): array
{
    $u = currentUser();
    if (!$u) {
        header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    return $u;
}

/** 必须管理员，未登录跳登录，非管理员 403 */
function requireAdminPage(): array
{
    $u = currentUser();
    if (!$u) {
        header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    if (($u['role'] ?? 'user') !== 'admin') {
        http_response_code(403);
        pageHead('无权限');
        echo '<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;">';
        echo '<mdui-icon name="block--outlined" style="font-size:48px;opacity:.4;"></mdui-icon>';
        echo '<div style="font-size:18px;font-weight:700;">403 无权限</div>';
        echo '<div style="opacity:.6;font-size:13px;">此页面仅管理员可访问</div>';
        echo '<mdui-button variant="tonal" onclick="location.href=\'/user/index.php\'">返回控制台</mdui-button>';
        echo '</div>';
        pageFoot();
        exit;
    }
    return $u;
}

/** 后台侧边栏（$active: dashboard/users/apps/auths/tokens） */
function adminSidebar(string $active): void
{
    $items = [
        'dashboard' => ['仪表盘', 'dashboard--outlined', 'index.php'],
        'users'     => ['用户管理', 'people--outlined', 'users.php'],
        'apps'      => ['应用管理', 'apps--outlined', 'apps.php'],
        'auths'     => ['授权管理', 'verified_user--outlined', 'auths.php'],
        'tokens'    => ['令牌管理', 'vpn_key--outlined', 'tokens.php'],
        'cards'     => ['充值卡密', 'card_membership--outlined', 'cards.php'],
        'merchants' => ['商户管理', 'storefront--outlined', 'merchants.php'],
        'settings'  => ['系统设置', 'settings--outlined', 'settings.php'],
    ];
    echo '<div class="sidebar">';
    foreach ($items as $k => [$label, $icon, $href]) {
        $on = $k === $active ? ' on' : '';
        echo '<div class="nav-item' . $on . '" onclick="location.href=\'/admin/' . $href . '\'"><mdui-icon class="ic" name="' . $icon . '"></mdui-icon>' . $label . '</div>';
    }
    echo '<div class="nav-item" style="margin-top:auto;" onclick="location.href=\'/user/index.php\'"><mdui-icon class="ic" name="arrow_back--outlined"></mdui-icon>返回控制台</div>';
    echo '</div>';
}

/** 页面头部 */
function pageHead(string $title, string $extraCss = ''): void
{
    $theme = $_COOKIE['auth_theme'] ?? 'auto';
    $cls = $theme === 'light' ? 'mdui-theme-light' : ($theme === 'dark' ? 'mdui-theme-dark' : 'mdui-theme-auto');
    echo '<!DOCTYPE html>
<html lang="zh-CN" class="' . $cls . '">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no"/>
<meta name="renderer" content="webkit"/>
<link rel="stylesheet" href="/lib/mdui.css">
<link rel="stylesheet" href="/lib/material-icons.css">
<link rel="stylesheet" href="/css/common.css?v=1787119506">';
    if ($extraCss) echo $extraCss;
    echo '<title>' . htmlspecialchars($title) . ' · Auth Center</title>
<link rel="stylesheet" href="/lib/toast.css?v=' . (filemtime(__DIR__ . '/../../lib/toast.css') ?: 1) . '">
<script src="/lib/theme.js?v=' . (filemtime(__DIR__ . '/../../lib/theme.js') ?: 1) . '"></script>
<script src="/lib/toast.js?v=' . (filemtime(__DIR__ . '/../../lib/toast.js') ?: 1) . '"></script>
<script src="/js/glass.js?v=' . (filemtime(__DIR__ . '/../../js/glass.js') ?: 1) . '"></script>
</head>
<body>';
}

/** 页面尾部 */
function pageFoot(string $extraJs = ''): void
{
    echo '<script src="/lib/mdui.global.js?v=' . (filemtime(__DIR__ . '/../../lib/mdui.global.js') ?: 1) . '"></script>';
    if ($extraJs) echo $extraJs;
    echo '</body></html>';
}

/** 顶部导航栏（登录用户可选） */
function pageNav(?array $user): void
{
    echo '<div class="nav">
  <div class="brand-mini" onclick="location.href=\'/index.php\'"><div class="dot">A</div>Auth Center</div>
  <div class="me">';
    if ($user) {
        echo '<span style="font-size:13px; opacity:.7;">' . htmlspecialchars($user['nickname']) . '</span>';
        if ($user['avatar']) {
            echo '<mdui-avatar style="--mdui-avatar-size:34px;"><img src="' . htmlspecialchars($user['avatar']) . '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;"></mdui-avatar>';
        } else {
            echo '<mdui-avatar style="--mdui-avatar-size:34px;"><img src="/avatar.php?n=' . rawurlencode($user['nickname']) . '&s=' . rawurlencode($user['uid']) . '&size=68" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;"></mdui-avatar>';
        }
    }
    echo '</div>
</div>';
}

/** 常规用户侧边栏（$active: index/auth/profile） */
function pageSidebar(string $active): void
{
    $items = [
        'index'    => ['总览', 'dashboard--outlined', 'index.php'],
        'auth'     => ['授权管理', 'verified_user--outlined', 'auth.php'],
        'wallet'   => ['我的余额', 'account_balance_wallet--outlined', 'wallet.php'],
        'images'   => ['我的图床', 'image--outlined', 'image.php'],
        'bindings' => ['绑定渠道', 'link--outlined', 'bindings.php'],
        'profile'  => ['个人设置', 'person--outlined', 'profile.php'],
    ];
    echo '<div class="sidebar">';
    foreach ($items as $k => [$label, $icon, $href]) {
        $on = $k === $active ? ' on' : '';
        echo '<div class="nav-item' . $on . '" onclick="location.href=\'/user/' . $href . '\'"><mdui-icon class="ic" name="' . $icon . '"></mdui-icon>' . $label . '</div>';
    }
    echo '<div class="nav-item" style="margin-top:auto;" onclick="location.href=\'/developer/index.php\'"><mdui-icon class="ic" name="code--outlined"></mdui-icon>开发者控制台</div>';
    echo '</div>';
}

/** 内容区开/关 */
function contentOpen(string $title, string $sub = ''): void
{
    echo '<div class="content"><div class="page-title">' . htmlspecialchars($title) . '</div>';
    if ($sub) echo '<div class="page-sub">' . htmlspecialchars($sub) . '</div>';
}

function contentClose(): void
{
    echo '</div>';
}

/** 应用状态徽章 */
function appStatusBadge(int $status): string
{
    $map = [1 => ['开发中', 'primary'], 2 => ['已上线', 'tertiary'], 3 => ['已吊销', 'error']];
    [$text, $color] = $map[$status] ?? ['未知', 'primary'];
    return '<mdui-badge color="' . $color . '">' . $text . '</mdui-badge>';
}


/** 开发者控制台侧边栏（$active: devindex/devapps/devkeys） */
function devSidebar(string $active): void
{
    $items = [
        'devindex' => ['开发总览', 'dashboard--outlined', 'index.php'],
        'devapps'  => ['我的应用', 'apps--outlined', 'apps.php'],
        'devauths' => ['收到的授权', 'verified_user--outlined', 'auths.php'],
        'devkeys'  => ['API 密钥', 'key--outlined', 'keys.php'],
        'devbal'   => ['应用余额', 'savings--outlined', 'app-balance.php'],
    ];
    echo '<div class="sidebar">';
    foreach ($items as $k => [$label, $icon, $href]) {
        $on = $k === $active ? ' on' : '';
        echo '<div class="nav-item' . $on . '" onclick="location.href=\'/developer/' . $href . '\'"><mdui-icon class="ic" name="' . $icon . '"></mdui-icon>' . $label . '</div>';
    }
    echo '<div class="nav-item" style="margin-top:auto;" onclick="location.href=\'/user/index.php\'"><mdui-icon class="ic" name="person--outlined"></mdui-icon>用户控制台</div>';
    echo '</div>';
}
