<?php
/**
 * Auth Center 登录入口示例
 *
 * 部署：把本文件放到你的服务器，访问 login.php 即跳转 Auth Center 授权页
 * 配置：先编辑本文件底部的 $config
 */
require_once __DIR__ . '/auth-center-sdk.php';

// ====== 配置（改成你的） ======
$config = [
    'client_id'     => '你的client_id',
    'client_secret' => '你的client_secret',
    'redirect_uri'  => 'https://yourapp.com/callback.php', // 必须和注册时一致
];
$scope = 'basic'; // 需要的权限
// =============================

session_start();
$sdk = new AuthCenter($config);

// 生成防 CSRF 的 state 并存入 session
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

// 跳转授权页
header('Location: ' . $sdk->getAuthorizeUrl($scope, $state));
exit;
