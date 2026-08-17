<?php
/**
 * 创建应用（服务端渲染）
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';
require_once __DIR__ . '/../api/lib/scopes.php';

$user = requireLoginPage();
$db = db();

$err = '';
$created = null; // 创建成功后展示 client_secret（只一次）

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $cb   = trim($_POST['callback'] ?? '');
    $home = trim($_POST['homepage'] ?? '');
    $scopes = $_POST['scopes'] ?? [];

    if (mb_strlen($name) < 2 || mb_strlen($name) > 30) {
        $err = '应用名称需 2-30 个字符';
    } elseif ($cb === '') {
        $err = '请填写回调地址';
    } elseif (!preg_match('#^https?://#i', $cb)) {
        $err = '回调地址需以 http:// 或 https:// 开头';
    } elseif ($home !== '' && filter_var($home, FILTER_VALIDATE_URL) === false) {
        $err = '应用主页格式不正确';
    } else {
        $scopes = sanitizeScopes($scopes);

        $clientId = genClientId();
        $secret   = genSecret();

        $st = $db->prepare('INSERT INTO apps (client_id, client_secret_hash, owner_id, name, description, callback_url, homepage, status) VALUES (?,?,?,?,?,?,?,1)');
        $st->execute([$clientId, hashSecret($secret), $user['id'], $name, $desc, $cb, $home]);
        $appId = (int)$db->lastInsertId();

        $si = $db->prepare('INSERT INTO app_scopes (app_id, scope) VALUES (?,?)');
        foreach ($scopes as $s) $si->execute([$appId, $s]);

        $created = ['client_id' => $clientId, 'client_secret' => $secret, 'name' => $name];
    }
}

$scopeDefs = scopeDefs();

pageHead('创建应用', '<link rel="stylesheet" href="/css/user.css?v=20260817">');
pageNav($user);
echo '<div class="shell">';
devSidebar('devapps');
?>
<div class="content">
    <div class="page-title">创建应用</div>
    <div class="page-sub">注册一个新应用，接入 Auth Center 统一认证</div>

    <?php if ($err): ?><mdui-alert severity="error" icon="error--outlined" style="margin-bottom:14px;"><?= htmlspecialchars($err) ?></mdui-alert><?php endif; ?>

    <?php if ($created): ?>
    <!-- 创建成功：展示凭据，只此一次 -->
    <mdui-card class="form-card" variant="elevated">
      <div class="sec-title" style="margin:0 0 12px;"><mdui-icon name="check_circle--outlined" style="font-size:18px; vertical-align:-3px; margin-right:4px;"></mdui-icon>应用「<?= htmlspecialchars($created['name']) ?>」创建成功</div>
      <div class="callback-hint" style="opacity:.85; margin-bottom:10px;">client_secret 只显示这一次，请立即保存到安全的地方。</div>
      <div class="form-field">
        <mdui-text-field label="client_id" value="<?= htmlspecialchars($created['client_id']) ?>" readonly full-width></mdui-text-field>
      </div>
      <div class="token-box" style="margin-top:0;"><?= htmlspecialchars($created['client_secret']) ?></div>
      <div class="actions">
        <mdui-button variant="text" onclick="location.href='apps.php'">返回应用列表</mdui-button>
        <mdui-button variant="filled" icon="check--outlined" onclick="location.href='apps.php'">完成</mdui-button>
      </div>
    </mdui-card>
    <?php else: ?>

    <form method="POST">
    <mdui-card class="form-card" variant="elevated">
      <div class="form-field">
        <mdui-text-field name="name" label="应用名称" placeholder="给你的应用起个名字" icon="badge--outlined" clearable full-width></mdui-text-field>
      </div>
      <div class="form-field">
        <mdui-text-field name="description" label="应用简介" placeholder="一句话描述这个应用是干什么的" icon="description--outlined" clearable full-width></mdui-text-field>
      </div>
      <div class="form-field">
        <mdui-text-field name="callback" label="回调地址 (Callback URL)" placeholder="https://yourapp.com/callback" icon="link--outlined" clearable full-width></mdui-text-field>
        <div class="callback-hint">用户授权后会跳转到这个地址，并附带授权码。生产环境必须使用 HTTPS。</div>
      </div>
      <div class="form-field">
        <mdui-text-field name="homepage" label="应用主页 (可选)" placeholder="https://yourapp.com" icon="language--outlined" clearable full-width></mdui-text-field>
      </div>

      <div class="sec-title" style="margin:20px 0 12px;">权限范围</div>
      <?php foreach ($scopeDefs as $key => [$t, $d]): ?>
      <label class="scope-check">
        <mdui-checkbox name="scopes[]" value="<?= $key ?>" <?= $key === 'basic' ? 'checked' : '' ?>></mdui-checkbox>
        <div class="txt">
          <div class="t"><?= $t ?></div>
          <div class="d"><?= $d ?></div>
        </div>
      </label>
      <?php endforeach; ?>

      <div class="actions">
        <mdui-button variant="text" onclick="location.href='apps.php'">取 消</mdui-button>
        <mdui-button variant="filled" icon="check--outlined" type="submit">创建应用</mdui-button>
      </div>
    </mdui-card>
    </form>
    <?php endif; ?>
</div>
<?php
echo '</div>';
pageFoot(); ?>