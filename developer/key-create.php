<?php
/**
 * 生成密钥（服务端渲染）
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';

$user = requireLoginPage();
$db = db();

// 用户的应用列表（下拉选择）
$st = $db->prepare('SELECT id, client_id, name FROM apps WHERE owner_id = ? AND status IN (1,2) ORDER BY name');
$st->execute([$user['id']]);
$myApps = $st->fetchAll();

$err = '';
$created = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientId = $_POST['client_id'] ?? '';
    $name     = trim($_POST['name'] ?? '');

    $app = null;
    foreach ($myApps as $a) {
        if ($a['client_id'] === $clientId) { $app = $a; break; }
    }
    if (!$app) {
        $err = '请选择应用';
    } else {
        // 每应用最多 5 个有效密钥
        $cnt = $db->prepare('SELECT COUNT(*) c FROM api_keys WHERE app_id = ? AND status = 1');
        $cnt->execute([$app['id']]);
        if ((int)$cnt->fetch()['c'] >= 5) {
            $err = '该应用已有 5 个有效密钥，请先吊销旧密钥';
        } else {
            $secret = genSecret();
            $db->prepare('INSERT INTO api_keys (app_id, key_prefix, key_hash, name) VALUES (?,?,?,?)')
                ->execute([$app['id'], substr($secret, 0, 10), hashSecret($secret), $name]);
            $created = ['key' => $secret, 'app' => $app['name']];
        }
    }
}

pageHead('生成密钥', '<link rel="stylesheet" href="/css/user.css">');
pageNav($user);
echo '<div class="shell">';
devSidebar('devkeys');
?>
<div class="content">
    <div class="page-title">生成密钥</div>
    <div class="page-sub">为应用创建新的 API 访问密钥</div>

    <?php if ($err): ?><mdui-alert severity="error" icon="error--outlined" style="margin-bottom:14px;"><?= htmlspecialchars($err) ?></mdui-alert><?php endif; ?>

    <?php if ($created): ?>
    <mdui-card class="form-card" variant="elevated">
      <div class="sec-title" style="margin:0 0 12px;"><mdui-icon name="check_circle--outlined" style="font-size:18px; vertical-align:-3px; margin-right:4px;"></mdui-icon>密钥已生成（<?= htmlspecialchars($created['app']) ?>）</div>
      <div class="callback-hint" style="opacity:.85; margin-bottom:10px;">完整密钥只显示这一次，关闭页面后无法再次查看，请立即保存。</div>
      <div class="token-box" style="margin-top:0;"><?= htmlspecialchars($created['key']) ?></div>
      <div class="actions">
        <mdui-button variant="text" onclick="location.href='keys.php'">返回密钥列表</mdui-button>
        <mdui-button variant="filled" icon="check--outlined" onclick="location.href='keys.php'">完成</mdui-button>
      </div>
    </mdui-card>
    <?php else: ?>

    <form method="POST">
    <mdui-card class="form-card" variant="elevated">
      <div class="form-field">
        <mdui-select name="client_id" label="选择应用" placeholder="为哪个应用生成密钥" full-width>
          <?php foreach ($myApps as $a): ?>
          <mdui-menu-item value="<?= htmlspecialchars($a['client_id']) ?>"><?= htmlspecialchars($a['name']) ?> · <?= htmlspecialchars($a['client_id']) ?></mdui-menu-item>
          <?php endforeach; ?>
        </mdui-select>
      </div>
      <div class="form-field">
        <mdui-text-field name="name" label="密钥名称 (可选)" placeholder="如：生产环境 / 测试用" icon="badge--outlined" clearable full-width></mdui-text-field>
      </div>
      <div class="callback-hint" style="margin-top:4px;">每个应用最多 5 个有效密钥，建议按环境区分。</div>
      <div class="actions">
        <mdui-button variant="text" onclick="location.href='keys.php'">取 消</mdui-button>
        <mdui-button variant="filled" icon="key--outlined" type="submit">生成密钥</mdui-button>
      </div>
    </mdui-card>
    </form>
    <?php endif; ?>
</div>
<?php
echo '</div>';
pageFoot(); ?>