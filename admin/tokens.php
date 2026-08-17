<?php
/**
 * 管理后台 · 令牌管理
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';

$admin = requireAdminPage();
$db = db();

// 吊销令牌
if (($_POST['action'] ?? '') === 'revoke') {
    $tokId = (int)($_POST['token_id'] ?? 0);
    $db->prepare('UPDATE oauth_tokens SET revoked = 1 WHERE id = ?')->execute([$tokId]);
    header('Location: tokens.php');
    exit;
}

// 筛选
$status = $_GET['status'] ?? 'active'; // active / revoked / expired
$kw = trim($_GET['q'] ?? '');

$sql = 'SELECT t.*, a.name AS app_name, a.client_id, u.nickname, u.email FROM oauth_tokens t
        JOIN apps a ON a.id = t.app_id
        JOIN users u ON u.id = t.user_id';
$conds = [];
$args = [];

switch ($status) {
    case 'active':   $conds[] = 't.revoked = 0 AND t.access_expires_at > NOW()'; break;
    case 'revoked':  $conds[] = 't.revoked = 1'; break;
    case 'expired':  $conds[] = 't.revoked = 0 AND t.access_expires_at <= NOW()'; break;
    default: $status = 'active'; $conds[] = 't.revoked = 0 AND t.access_expires_at > NOW()';
}
if ($kw) {
    $conds[] = '(a.name LIKE ? OR u.nickname LIKE ? OR u.email LIKE ?)';
    array_push($args, "%$kw%", "%$kw%", "%$kw%");
}
if ($conds) $sql .= ' WHERE ' . implode(' AND ', $conds);
$sql .= ' ORDER BY t.created_at DESC LIMIT 100';
$st = $db->prepare($sql);
$st->execute($args);
$tokens = $st->fetchAll();

pageHead('令牌管理', '<link rel="stylesheet" href="/css/user.css">');
pageNav($admin);
echo '<div class="shell">';
adminSidebar('tokens');
contentOpen('令牌管理', '全系统 OAuth 访问令牌');
?>
    <form method="GET" style="margin-bottom:16px;">
      <div style="display:flex; gap:10px; max-width:520px; flex-wrap:wrap;">
        <mdui-text-field name="q" label="搜索应用 / 用户" value="<?= htmlspecialchars($kw) ?>" icon="search--outlined" clearable full-width></mdui-text-field>
        <mdui-select name="status" label="状态" value="<?= htmlspecialchars($status) ?>">
          <mdui-menu-item value="active">有效</mdui-menu-item>
          <mdui-menu-item value="expired">已过期</mdui-menu-item>
          <mdui-menu-item value="revoked">已吊销</mdui-menu-item>
        </mdui-select>
        <mdui-button variant="filled" icon="search--outlined" type="submit">筛选</mdui-button>
      </div>
    </form>

    <mdui-list>
      <?php foreach ($tokens as $t): $isActive = (int)$t['revoked'] === 0 && strtotime($t['access_expires_at']) > time(); ?>
      <mdui-list-item nonclickable>
        <mdui-avatar slot="icon" style="--mdui-avatar-size:38px; border-radius:12px; background:<?= $isActive ? 'linear-gradient(135deg,#60a5fa,#3b82f6)' : 'rgb(var(--mdui-color-surface-container-high))' ?>; font-size:15px;"><?= htmlspecialchars(mb_substr($t['app_name'], 0, 1)) ?></mdui-avatar>
        <div>
          <?= htmlspecialchars($t['app_name']) ?>
          <?php if ($isActive): ?><mdui-badge color="tertiary">有效</mdui-badge>
          <?php elseif ((int)$t['revoked'] === 1): ?><mdui-badge color="error">已吊销</mdui-badge>
          <?php else: ?><mdui-badge>已过期</mdui-badge><?php endif; ?>
        </div>
        <span slot="description" style="font-size:12px;">
          <?= htmlspecialchars($t['nickname']) ?> (<?= htmlspecialchars($t['email']) ?>)
          · 签发 <?= substr($t['created_at'], 0, 16) ?>
          · 过期 <?= substr($t['access_expires_at'], 0, 16) ?>
          · scope: <?= htmlspecialchars($t['scopes']) ?>
        </span>
        <?php if ($isActive): ?>
        <form slot="end-icon" method="POST" onsubmit="return confirm('吊销该令牌？');">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="token_id" value="<?= (int)$t['id'] ?>">
          <mdui-button variant="text" color="error" icon="vpn_key--outlined" type="submit">吊销</mdui-button>
        </form>
        <?php endif; ?>
      </mdui-list-item>
      <?php endforeach; ?>
    </mdui-list>
<?php
contentClose();
echo '</div>';
pageFoot(); ?>