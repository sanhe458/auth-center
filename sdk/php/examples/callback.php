<?php
/**
 * Auth Center 回调处理示例
 *
 * 这是用户在 Auth Center 同意授权后被跳转回来的页面。
 * 流程：校验 state → 用 code 换令牌 → 拿用户信息 → 创建你的登录会话
 */
require_once __DIR__ . '/auth-center-sdk.php';

// ====== 配置（改成你的，和 login.php 保持一致） ======
$config = [
    'client_id'     => '你的client_id',
    'client_secret' => '你的client_secret',
    'redirect_uri'  => 'https://yourapp.com/callback.php',
];
// ====================================================

session_start();
$sdk = new AuthCenter($config);

// ① 校验 state（防 CSRF）：必须和 login.php 存的一致
if (($_GET['state'] ?? '') !== ($_SESSION['oauth_state'] ?? '')) {
    http_response_code(400);
    exit('state 校验失败，请重新发起登录');
}

// ② 用户拒绝了授权（带 error 参数跳回）
if (isset($_GET['error'])) {
    $desc = $_GET['error_description'] ?? '';
    http_response_code(400);
    exit('授权失败：' . htmlspecialchars($desc ?: $_GET['error']));
}

// ③ 必须带 code
$code = $_GET['code'] ?? '';
if ($code === '') {
    http_response_code(400);
    exit('缺少授权码');
}

try {
    // ④ 换令牌（access_token 2小时 + refresh_token 30天）
    $tokens = $sdk->exchangeCode($code);

    // ⑤ 拿用户信息
    $user = $sdk->getUserInfo($tokens['access_token']);

    // ⑥ ===== 在这里创建你自己的登录会话 =====
    // 把 $user['id'] 和你的用户体系关联，写入你的数据库/session：
    //   - 已有用户 → 更新信息
    //   - 新用户 → 自动注册
    // 然后把 $tokens 存起来（数据库），过期用 refreshToken() 续期
    $_SESSION['logged_in']   = true;
    $_SESSION['user']        = $user;
    $_SESSION['ac_tokens']   = $tokens;

    // ⑦ 登录成功，跳转到你的首页
    header('Location: /index.php');
    exit;

} catch (Exception $e) {
    http_response_code(500);
    exit('登录失败：' . htmlspecialchars($e->getMessage()));
}
