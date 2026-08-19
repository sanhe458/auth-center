<?php
/**
 * 登录页（服务端渲染）
 */
require_once __DIR__ . '/api/lib/db.php';
require_once __DIR__ . '/api/lib/helpers.php';
require_once __DIR__ . '/api/lib/redis.php';
require_once __DIR__ . '/api/lib/page.php';

// 已登录 → 直接进控制台（next 安全校验，防 open redirect）
$me = currentUser();
if ($me) {
    $next = $_GET['next'] ?? '';
    $safe = false;
    if ($next) {
        if (str_starts_with($next, '/') && !str_starts_with($next, '//')) {
            $safe = true; // 相对路径
        } elseif (str_starts_with($next, 'https://auth.sanhe.com.mp')) {
            $safe = true; // 本站完整 URL
        }
    }
    header('Location: ' . ($safe ? $next : '/user/index.php'));
    exit;
}

$error = '';
$oldEmail = '';

// POST 登录（限流：每 IP 每分钟 20 次）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rateLimit('login:' . clientIp(), 20, 60)) {
        $error = '尝试过于频繁，请 1 分钟后再试';
        $oldEmail = strtolower(trim($_POST['email'] ?? ''));
    } else {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $oldEmail = $email;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '请输入有效的邮箱地址';
    } elseif ($password === '') {
        $error = '请输入密码';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = '邮箱或密码错误';
        } elseif ((int)$user['status'] !== 1) {
            $error = '账号已被禁用';
        } else {
            session_start();
            session_regenerate_id(true);
            $_SESSION['user_id']  = (int)$user['id'];
            $_SESSION['nickname'] = $user['nickname'];

            $next = $_POST['next'] ?? ($_GET['next'] ?? '');
            // 安全校验：仅允许本站相对路径或本站完整 URL，防止开放重定向
            $safe = false;
            if ($next) {
                if (str_starts_with($next, '/') && !str_starts_with($next, '//')) {
                    $safe = true; // 相对路径
                } elseif (str_starts_with($next, 'https://auth.sanhe.com.mp')) {
                    $safe = true; // 本站完整 URL
                }
            }
            header('Location: ' . ($safe ? $next : '/user/index.php'));
            exit;
        }
    }
    }
}

$next = htmlspecialchars($_GET['next'] ?? ($_POST['next'] ?? ''), ENT_QUOTES);
pageHead('登录');
?>
<button class="theme-toggle fixed" onclick="toggleTheme()" title="切换主题"><mdui-icon id="theme-icon" name="dark_mode--outlined"></mdui-icon></button>

<div class="page active" id="page-login">
  <div class="center-wrap">
    <div class="auth-card">
      <div class="brand">
        <div class="logo"><img src="/logo.svg" alt="Auth Center" style="width:100%;height:100%;border-radius:20px;"></div>
        <h1>Auth Center</h1>
        <p>统一身份认证 · 一个账号，通行所有应用</p>
      </div>
      <mdui-card class="auth-card" variant="elevated" style="border-radius:24px;">
        <div style="padding:24px;">
          <?php
          if ($error) {
              echo '<mdui-alert severity="error" icon="error--outlined" style="margin-bottom:14px;">' . htmlspecialchars($error) . '</mdui-alert>';
          }
          $ghErr = $_GET['error'] ?? '';
          $ghMsg = [
              'github_denied' => '已取消 GitHub 授权',
              'github_no_code' => 'GitHub 授权失败（缺少授权码）',
              'github_token_failed' => 'GitHub 登录失败（获取令牌失败）',
              'github_user_failed' => 'GitHub 登录失败（获取用户信息失败）',
              'rainbow_no_code' => '第三方授权失败（缺少授权码）',
              'rainbow_failed' => '第三方登录失败',
              'account_disabled' => '该账号已被禁用',
          ][$ghErr] ?? '';
          if ($ghMsg) {
              echo '<mdui-alert severity="error" icon="error--outlined" style="margin-bottom:14px;">' . $ghMsg . '</mdui-alert>';
          }
          ?>
          <form method="POST" action="/login.php" id="login-form">
            <input type="hidden" name="next" value="<?= $next ?>">
            <div class="form-field">
              <mdui-text-field name="email" label="邮箱" placeholder="name@example.com" icon="mail--outlined" type="email" value="<?= htmlspecialchars($oldEmail) ?>" clearable full-width></mdui-text-field>
            </div>
            <div class="form-field">
              <mdui-text-field name="password" label="密码" placeholder="请输入密码" icon="lock--outlined" type="password" toggle-password clearable full-width></mdui-text-field>
            </div>
            <div style="text-align:right; margin: 4px 0 16px;">
              <mdui-button variant="text" style="font-size:13px;">忘记密码？</mdui-button>
            </div>
            <mdui-button variant="filled" icon="login" full-width type="submit">登 录</mdui-button>
          </form>
          <div class="divider">其他登录方式</div>
          <div class="socials2">
            <a class="social-btn" href="/api/oauth/rainbow?type=wx" style="--c:07c160">
              <img src="/icons/wechat.png">
            </a>
            <a class="social-btn" href="/api/oauth/rainbow?type=qq" style="--c:12b7f5">
              <img src="/icons/qq.png">
            </a>
            <a class="social-btn" href="/api/oauth/github" style="--c:24292f">
              <img src="/icons/github.png">
            </a>
            <a class="social-btn" href="/api/oauth/rainbow?type=gitee" style="--c:de6d2c">
              <img src="/icons/gitee.png">
            </a>
          </div>
          <div style="text-align:center; margin-top:18px;">
            <mdui-button variant="text" onclick="location.href='register.php<?= $next ? '?next=' . $next : '' ?>'">还没有账号？立即注册</mdui-button>
          </div>
        </div>
      </mdui-card>
      <div class="foot">© 2026 Auth Center · 登录即代表同意服务条款与隐私政策</div>
    </div>
  </div>
</div>

<script>
// 表单校验后提交
document.getElementById('login-form').addEventListener('submit', function (e) {
  const email = this.querySelector('[name=email]').value || '';
  const pass  = this.querySelector('[name=password]').value || '';
  if (!email.trim()) { e.preventDefault(); toast.warning('请输入邮箱'); return; }
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.trim())) { e.preventDefault(); toast.warning('请输入有效的邮箱地址'); return; }
  if (!pass) { e.preventDefault(); toast.warning('请输入密码'); return; }
});
</script>
<?php pageFoot(); ?>