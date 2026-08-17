<?php
/**
 * 第三方账号绑定页（彩虹聚合登录用）
 * 新第三方账号 → 绑定已有账号（邮箱+密码）或注册新账号
 */
require_once __DIR__ . '/api/lib/db.php';
require_once __DIR__ . '/api/lib/helpers.php';
require_once __DIR__ . '/api/lib/page.php';

session_start();
$sp = $_SESSION['social_pending'] ?? null;
if (!$sp) {
    header('Location: /login.php');
    exit;
}

$providerNames = [
    'qq' => 'QQ', 'wx' => '微信', 'alipay' => '支付宝', 'sina' => '微博',
    'baidu' => '百度', 'douyin' => '抖音', 'xiaomi' => '小米',
    'google' => '谷歌', 'microsoft' => '微软', 'gitee' => 'Gitee', 'github' => 'GitHub',
];
$providerName = $providerNames[$sp['provider']] ?? $sp['provider'];

$error = '';
$action = $_POST['action'] ?? '';

if ($action === 'bind') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '请输入有效的邮箱';
    } elseif ($password === '') {
        $error = '请输入密码';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = '邮箱或密码错误';
        } elseif ((int)$user['status'] !== 1) {
            $error = '该账号已被禁用';
        } else {
            // 该第三方账号是否已被其他用户绑定
            $st = db()->prepare('SELECT user_id FROM social_bindings WHERE provider = ? AND social_uid = ? LIMIT 1');
            $st->execute([$sp['provider'], $sp['social_uid']]);
            if ($st->fetch()) {
                $error = '该' . $providerName . '账号已绑定其他用户';
            } else {
                db()->prepare('INSERT INTO social_bindings (user_id, provider, social_uid, nickname, avatar) VALUES (?,?,?,?,?)')
                    ->execute([$user['id'], $sp['provider'], $sp['social_uid'], $sp['nickname'], $sp['avatar']]);
                unset($_SESSION['social_pending']);
                session_regenerate_id(true);
                $_SESSION['user_id']  = (int)$user['id'];
                $_SESSION['nickname'] = $user['nickname'];
                header('Location: /user/index.php');
                exit;
            }
        }
    }
} elseif ($action === 'register') {
    $uid = genUid();
    $email = 'social_' . $sp['social_uid'] . '@social.auth.local';
    $st = db()->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) {
        $email = 'social_' . $sp['social_uid'] . '_' . time() . '@social.auth.local';
    }
    db()->prepare('INSERT INTO users (uid, nickname, email, password_hash, avatar) VALUES (?,?,?,?,?)')
        ->execute([$uid, $sp['nickname'], $email, password_hash(randToken(24), PASSWORD_DEFAULT), $sp['avatar']]);
    $userId = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO social_bindings (user_id, provider, social_uid, nickname, avatar) VALUES (?,?,?,?,?)')
        ->execute([$userId, $sp['provider'], $sp['social_uid'], $sp['nickname'], $sp['avatar']]);
    unset($_SESSION['social_pending']);
    session_regenerate_id(true);
    $_SESSION['user_id']  = $userId;
    $_SESSION['nickname'] = $sp['nickname'];
    header('Location: /user/index.php');
    exit;
}

pageHead('绑定账号');
?>
<button class="theme-toggle fixed" onclick="toggleTheme()" title="切换主题"><mdui-icon id="theme-icon" name="dark_mode--outlined"></mdui-icon></button>

<div class="page active">
  <div class="center-wrap">
    <div class="auth-card">
      <div class="brand">
        <div class="logo" style="background:linear-gradient(135deg,#0ea5e9,#2dd4bf);">
          <mdui-icon name="link--outlined" style="font-size:30px;"></mdui-icon>
        </div>
        <h1>关联你的账号</h1>
        <p style="word-break:break-all;"><?= htmlspecialchars($providerName) ?> 账号 <b>@<?= htmlspecialchars($sp['nickname']) ?></b> 尚未关联 Auth Center 账号</p>
      </div>

      <mdui-card class="auth-card" variant="elevated" style="border-radius:24px;">
        <div style="padding:24px;">
          <?php if ($error): ?>
          <mdui-alert severity="error" icon="error--outlined" style="margin-bottom:14px;"><?= htmlspecialchars($error) ?></mdui-alert>
          <?php endif; ?>

          <form method="POST" id="bind-form">
            <input type="hidden" name="action" value="bind">
            <div class="sec-title" style="margin:0 0 12px; font-size:14px;">绑定已有账号</div>
            <div class="form-field">
              <mdui-text-field name="email" label="已有账号邮箱" placeholder="name@example.com" icon="mail--outlined" type="email" clearable full-width></mdui-text-field>
            </div>
            <div class="form-field">
              <mdui-text-field name="password" label="密码" placeholder="输入已有账号的密码" icon="lock--outlined" type="password" toggle-password clearable full-width></mdui-text-field>
            </div>
            <mdui-button variant="filled" icon="link--outlined" full-width type="submit">绑定并登录</mdui-button>
          </form>

          <div class="divider">或者</div>

          <form method="POST">
            <input type="hidden" name="action" value="register">
            <mdui-button variant="tonal" icon="person_add" full-width type="submit">用 <?= htmlspecialchars($sp['nickname']) ?> 注册新账号</mdui-button>
          </form>

          <div style="text-align:center; margin-top:16px;">
            <mdui-button variant="text" onclick="location.href='/login.php'">返回登录</mdui-button>
          </div>
        </div>
      </mdui-card>
      <div class="foot">© 2026 Auth Center</div>
    </div>
  </div>
</div>

<script>
document.getElementById('bind-form').addEventListener('submit', function (e) {
  const email = this.querySelector('[name=email]').value || '';
  const pass  = this.querySelector('[name=password]').value || '';
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.trim())) { e.preventDefault(); mdui.snackbar('请输入有效的邮箱'); return; }
  if (!pass) { e.preventDefault(); mdui.snackbar('请输入密码'); return; }
});
</script>
<?php pageFoot(); ?>
