<?php
/**
 * 管理后台 · 系统设置
 * 可视化编辑后台配置项（值存 settings 表，代码里用 cfg() 读取）
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';
require_once __DIR__ . '/../api/lib/mailer.php';

$admin = requireAdminPage();
$db = db();

// 测试邮件发送
$testMsg = '';
if (($_POST['action'] ?? '') === 'test_email') {
    $to = trim((string)($_POST['test_to'] ?? ''));
    if ($to === '') {
        $testMsg = '请填写收件人邮箱';;
    } else {
        $r = mailSend($to, 'Auth Center 邮件测试', '<h3>✅ 邮件发送成功</h3><p>这封是 Auth Center 后台「邮件通知」的测试邮件，说明 SMTP 配置正常。</p>');
        $testMsg = ($r === true) ? '✅ 测试邮件发送成功，请检查 ' . htmlspecialchars($to) . ' 收件箱' : '❌ 发送失败：' . htmlspecialchars((string)$r);
    }
}

// 可配置项定义（键 => [标签, 说明, 是否密码]
$defs = [
    'imgbb_key'           => ['图床 IMGBB Key', '用户头像图床上传', false],
    'secret_pepper'       => ['密钥加盐 Pepper', '密钥 HMAC 加盐，修改会使旧密钥失效', true],
    'github_client_id'    => ['GitHub Client ID', 'GitHub OAuth 应用', false],
    'github_client_secret'=> ['GitHub Client Secret', 'GitHub OAuth 密钥', true],
    'rainbow_appid'       => ['彩虹 AppID', '彩虹聚合登录', false],
    'rainbow_appkey'      => ['彩虹 AppKey', '彩虹聚合登录密钥', true],
    'rainbow_api'         => ['彩虹接口地址', '彩虹聚合登录 API，如 https://login.9o3.cn/connect.php', false],
    'gitee_client_id'     => ['Gitee Client ID', 'Gitee OAuth 应用', false],
    'gitee_client_secret' => ['Gitee Client Secret', 'Gitee OAuth 密钥', true],
    'epay_api_url'        => ['易支付接口地址', 'mapi.php 上级地址', false],
    'epay_mch_id'         => ['易支付商户ID', '商户号', false],
    'epay_key'            => ['易支付 MD5 密钥', '商户密钥', true],
    // ---- 邮件通知（SMTP）----
    'smtp_host'           => ['SMTP 服务器', '默认 smtp.qq.com，可改其他邮箱服务商', false],
    'smtp_port'           => ['SMTP 端口', '默认 465（SSL）。TLS 用 587', false],
    'smtp_secure'         => ['加密方式', 'ssl / tls / none，默认 ssl', false],
    'smtp_user'           => ['邮箱账号', 'QQ 邮箱填完整地址，如 yourname@qq.com', false],
    'smtp_pass'           => ['邮箱授权码', 'QQ 邮箱：设置→账户→开启 SMTP 后生成的授权码（非登录密码）', true],
    'smtp_from'           => ['发件人地址', '留空默认用邮箱账号', false],
    'smtp_from_name'      => ['发件人显示名', '默认 Auth Center', false],
];

// 分批保存配置
if (($_POST['action'] ?? '') === 'save') {
    $values = $_POST['cfg'] ?? [];
    $st = $db->prepare('REPLACE INTO settings (skey, svalue) VALUES (?,?)');
    foreach ($values as $k => $v) {
        if (is_string($k) && $k !== '') {
            $v = trim((string)$v);
            // 密码字段留空 = 保持原值（不回显、不覆盖）
            $isPwd = isset($defs[$k][2]) && $defs[$k][2];
            if ($isPwd && $v === '') continue;
            $st->execute([$k, $v]);
        }
    }
    // 清理无效 key
    // 配置已变更，递增版本号使 Redis 缓存失效
    try {
        if (function_exists('redis') && function_exists('rk')) {
            redis()->incr(rk('cfg:ver'));
        }
    } catch (Throwable $e) {
        // Redis 不可用不影响保存
    }
    header('Location: settings.php?msg=saved');
    exit;
}

$msg = $_GET['msg'] ?? '';

// 读取当前值
$cur = [];
$st = $db->query('SELECT skey, svalue FROM settings');
foreach ($st as $row) $cur[$row['skey']] = $row['svalue'];

pageHead('系统设置', '<link rel="stylesheet" href="/css/user.css">');
pageNav($admin);
echo '<div class="shell">';
adminSidebar('settings');
contentOpen('系统设置', '敏感配置已从代码迁移到此处，修改即时生效（不重启）');
?>
    <?php if ($msg === 'saved'): ?>
    <mdui-alert severity="success" icon="check_circle--outlined" style="margin-bottom:14px;">✅ 配置已保存</mdui-alert>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="action" value="save">
      <?php foreach ($defs as $key => [$label, $desc, $isPwd]): ?>
      <mdui-card variant="elevated" style="border-radius:12px; padding:16px 18px; margin-bottom:12px;">
        <div style="font-weight:700; margin-bottom:4px;"><?= htmlspecialchars($label) ?></div>
        <div style="font-size:12px; opacity:.6; margin-bottom:10px;"><?= htmlspecialchars($desc) ?> · <code style="opacity:.7;"><?= htmlspecialchars($key) ?></code></div>
        <mdui-text-field
          name="cfg[<?= htmlspecialchars($key) ?>]"
          <?php if ($isPwd): ?>
          placeholder="<?= !empty($cur[$key]) ? '已设置（留空保持不变）' : '未设置' ?>"
          <?php else: ?>
          value="<?= htmlspecialchars($cur[$key] ?? '') ?>"
          <?php endif; ?>
          <?= $isPwd ? 'type="password"' : '' ?>
          clearable full-width
          <?= $isPwd ? 'toggle-password' : '' ?>></mdui-text-field>
      </mdui-card>
      <?php endforeach; ?>
      <mdui-button variant="filled" icon="save--outlined" type="submit" style="margin-top:4px;">保存全部设置</mdui-button>
    </form>

    <?php if ($testMsg): ?>
    <mdui-alert severity="<?= str_starts_with($testMsg, '✅') ? 'success' : 'error' ?>" icon="<?= str_starts_with($testMsg, '✅') ? 'check_circle--outlined' : 'error--outlined' ?>" style="margin:18px 0 8px;"><?= $testMsg ?></mdui-alert>
    <?php endif; ?>

    <mdui-card variant="elevated" style="border-radius:12px; padding:16px 18px; margin-top:18px;">
      <div style="font-weight:700; margin-bottom:4px;">✉️ 测试邮件发送</div>
      <div style="font-size:12px; opacity:.6; margin-bottom:10px;">先保存上方 SMTP 配置，再填收件箱发一封测试邮件验证</div>
      <form method="POST" style="display:flex; gap:10px; align-items:flex-start;">
        <input type="hidden" name="action" value="test_email">
        <mdui-text-field name="test_to" placeholder="收件人邮箱，如 your@qq.com" clearable style="flex:1;"></mdui-text-field>
        <mdui-button variant="tonal" icon="send--outlined" type="submit">发测试邮件</mdui-button>
      </form>
    </mdui-card>
<?php
contentClose();
echo '</div>';
pageFoot();
