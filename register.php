<?php
/**
 * 注册页（服务端渲染）
 */
require_once __DIR__ . '/api/lib/db.php';
require_once __DIR__ . '/api/lib/helpers.php';
require_once __DIR__ . '/api/lib/redis.php';
require_once __DIR__ . '/api/lib/page.php';
require_once __DIR__ . '/ajcaptcha/vendor/autoload.php';

// 已登录 → 直接进控制台
$me = currentUser();
if ($me) {
    header('Location: /user/index.php');
    exit;
}

$error = '';
$old = ['nickname' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // AJ-Captcha 服务端强校验（防注册机/撞库）
    $capOk = false;
    try {
        $capConfig = require __DIR__ . '/ajcaptcha/src/config.php';
        $capSvc = new \Fastknife\Service\BlockPuzzleCaptchaService($capConfig);
        $capSvc->check($_POST['captcha_token'] ?? '', $_POST['captcha_pointJson'] ?? '');
        $capOk = true;
    } catch (\Throwable $e) {
        $capOk = false;
    }
    if (!$capOk) {
        $error = '请先完成滑块验证';
        $old = ['nickname' => '', 'email' => ''];
    } elseif (!rateLimit('reg:' . clientIp(), 10, 3600)) {
        $error = '注册过于频繁，请稍后再试';
        $old = ['nickname' => '', 'email' => ''];
    } else {
    $nickname = trim($_POST['nickname'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $old = ['nickname' => $nickname, 'email' => $email];

    if (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 30) {
        $error = '昵称需 2-30 个字符';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确';
    } elseif (strlen($password) < 8 || strlen($password) > 72) {
        $error = '密码需 8-72 位';
    } else {
        $st = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) {
            $error = '该邮箱已注册，去登录吧';
        } else {
            $uid = genUid();
            $st = db()->prepare('INSERT INTO users (uid, nickname, email, password_hash) VALUES (?,?,?,?)');
            $st->execute([$uid, $nickname, $email, password_hash($password, PASSWORD_DEFAULT)]);

            session_start();
            session_regenerate_id(true);
            $_SESSION['user_id']  = (int)db()->lastInsertId();
            $_SESSION['nickname'] = $nickname;

            header('Location: /user/index.php');
            exit;
        }
    }
    }
}

$next = htmlspecialchars($_GET['next'] ?? '', ENT_QUOTES);
pageHead('注册', '<link rel="stylesheet" href="/lib/captcha.css?v=' . (filemtime(__DIR__ . '/lib/captcha.css') ?: 1) . '">');
?>
<button class="theme-toggle fixed" onclick="toggleTheme()" title="切换主题"><mdui-icon id="theme-icon" name="dark_mode--outlined"></mdui-icon></button>

<div class="page active" id="page-register">
  <div class="center-wrap">
    <div class="auth-card" data-glass="container" data-glass-radius="24">
      <div class="brand">
        <div class="logo"><img src="/logo.svg" alt="Auth Center" style="width:100%;height:100%;border-radius:20px;"></div>
        <h1>Auth Center</h1>
        <p>统一身份认证 · 一个账号，通行所有应用</p>
      </div>
      <mdui-card class="auth-card" variant="elevated" style="border-radius:24px;" data-glass="container" data-glass-radius="24">
        <div style="padding:24px;">
          <?php if ($error): ?>
          <mdui-alert severity="error" icon="error--outlined" style="margin-bottom:14px;"><?= htmlspecialchars($error) ?></mdui-alert>
          <?php endif; ?>
          <form method="POST" action="/register.php" id="reg-form">
            <input type="hidden" name="next" value="<?= $next ?>">
            <div class="form-field">
              <mdui-text-field name="nickname" label="昵称" placeholder="怎么称呼你" icon="badge--outlined" value="<?= htmlspecialchars($old['nickname']) ?>" clearable full-width></mdui-text-field>
            </div>
            <div class="form-field">
              <mdui-text-field name="email" label="邮箱" placeholder="name@example.com" icon="mail--outlined" type="email" value="<?= htmlspecialchars($old['email']) ?>" clearable full-width></mdui-text-field>
            </div>
            <div class="form-field">
              <mdui-text-field name="password" label="密码" placeholder="至少 8 位" icon="lock--outlined" type="password" toggle-password clearable full-width></mdui-text-field>
            </div>
            <div id="captchaSlider" style="margin:2px 0 14px;"></div>
            <mdui-button variant="filled" icon="person_add" full-width type="submit">注 册</mdui-button>
          </form>
          <div style="text-align:center; margin-top:18px;">
            <mdui-button variant="text" onclick="location.href='login.php<?= $next ? '?next=' . $next : '' ?>'">已有账号？去登录</mdui-button>
          </div>
        </div>
      </mdui-card>
      <div class="foot">© 2026 Auth Center · 注册即代表同意服务条款与隐私政策</div>
    </div>
  </div>
</div>

<script>
document.getElementById('reg-form').addEventListener('submit', function (e) {
  const nick = this.querySelector('[name=nickname]').value || '';
  const email = this.querySelector('[name=email]').value || '';
  const pass  = this.querySelector('[name=password]').value || '';
  if (nick.trim().length < 2) { e.preventDefault(); toast.warning('昵称至少 2 个字符'); return; }
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.trim())) { e.preventDefault(); toast.warning('请输入有效的邮箱地址'); return; }
  if (pass.length < 8) { e.preventDefault(); toast.warning('密码至少 8 位'); return; }
  const ctk = this.querySelector('[name=captcha_token]');
  const cpt = this.querySelector('[name=captcha_pointJson]');
  if (!ctk || !ctk.value || !cpt || !cpt.value) { e.preventDefault(); toast.warning('请先完成滑块验证'); return; }
});
</script>
<?php pageFoot('<script src="/lib/crypto-js.js?v=' . (filemtime(__DIR__ . '/lib/crypto-js.js') ?: 1) . '"></script>
<script src="/lib/captcha-verify.js?v=' . (filemtime(__DIR__ . '/lib/captcha-verify.js') ?: 1) . '"></script>
<script>initCaptchaSlider({ wrap: "#captchaSlider", form: "#reg-form" });</script>'); ?>