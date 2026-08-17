<?php
/**
 * 管理后台 · 授权管理
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';
require_once __DIR__ . '/../api/lib/scopes.php';

$admin = requireAdminPage();
$db = db();

// 强制撤回
if (($_POST['action'] ?? '') === 'revoke') {
    $authId = (int)($_POST['authorization_id'] ?? 0);
    $st = $db->prepare('SELECT id, user_id, app_id FROM authorizations WHERE id = ? LIMIT 1');
    $st->execute([$authId]);
    $row = $st->fetch();
    if ($row) {
        $db->prepare('UPDATE authorizations SET status = 0, updated_at = NOW() WHERE id = ?')->execute([$authId]);
        $db->prepare('UPDATE oauth_tokens SET revoked = 1 WHERE user_id = ? AND app_id = ?')
            ->execute([$row['user_id'], $row['app_id']]);
    }
    header('Location: auths.php');
    exit;
}

// 搜索
$kw = trim($_GET['q'] ?? '');
$sql = 'SELECT z.*, a.name AS app_name, a.client_id, u.nickname, u.email FROM authorizations z
        JOIN apps a ON a.id = z.app_id
        JOIN users u ON u.id = z.user_id';
$args = [];
if ($kw) {
    $sql .= ' WHERE a.name LIKE ? OR u.nickname LIKE ? OR u.email LIKE ?';
    $args = ["%$kw%", "%$kw%", "%$kw%"];
}
$sql .= ' ORDER BY z.updated_at DESC LIMIT 100';
$st = $db->prepare($sql);
$st->execute($args);
$auths = $st->fetchAll();

$scopeLabels = scopeLabels();

pageHead('授权管理', '<link rel="stylesheet" href="/css/user.css">');
pageNav($admin);
echo '<div class="shell">';
adminSidebar('auths');
contentOpen('授权管理', '全系统授权关系');
?>
    <form method="GET" style="margin-bottom:16px;">
      <div style="display:flex; gap:10px; max-width:420px;">
        <mdui-text-field name="q" label="搜索应用 / 用户" value="<?= htmlspecialchars($kw) ?>" icon="search--outlined" clearable full-width></mdui-text-field>
        <mdui-button variant="filled" icon="search--outlined" type="submit">搜索</mdui-button>
      </div>
    </form>

    <mdui-list>
      <?php foreach ($auths as $z): $active = (int)$z['status'] === 1; ?>
      <mdui-list-item nonclickable>
        <mdui-avatar slot="icon" style="--mdui-avatar-size:38px; border-radius:12px; background:<?= $active ? 'linear-gradient(135deg,#34d399,#10b981)' : 'rgb(var(--mdui-color-surface-container-high))' ?>; font-size:15px;"><?= htmlspecialchars(mb_substr($z['app_name'], 0, 1)) ?></mdui-avatar>
        <div>
          <?= htmlspecialchars($z['app_name']) ?>
          <?php if ($active): ?><mdui-badge color="tertiary">有效</mdui-badge><?php else: ?><mdui-badge>已撤回</mdui-badge><?php endif; ?>
        </div>
        <span slot="description" style="font-size:12px;">
          <?= htmlspecialchars($z['nickname']) ?> (<?= htmlspecialchars($z['email']) ?>)
          · <?php foreach (explode(',', $z['scopes']) as $s): ?><span style="margin-right:4px;"><?= $scopeLabels[$s] ?? $s ?></span><?php endforeach; ?>
          · <?= substr($z['updated_at'], 0, 16) ?>
        </span>
        <?php if ($active): ?>
        <form slot="end-icon" method="POST" onsubmit="return confirm('强制撤回该授权？对应令牌将全部失效。');">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="authorization_id" value="<?= (int)$z['id'] ?>">
          <mdui-button variant="text" color="error" icon="link_off--outlined" type="submit">撤回</mdui-button>
        </form>
        <?php endif; ?>
      </mdui-list-item>
      <?php endforeach; ?>
    </mdui-list>
<?php
contentClose();
echo '</div>';
pageFoot(); ?>