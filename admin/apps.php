<?php
/**
 * 管理后台 · 应用管理
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';

$admin = requireAdminPage();
$db = db();

// 操作：上线/吊销
$action = $_POST['action'] ?? '';
$appId = (int)($_POST['app_id'] ?? 0);
if ($action && $appId) {
    switch ($action) {
        case 'online':
            $db->prepare('UPDATE apps SET status = 2 WHERE id = ?')->execute([$appId]);
            break;
        case 'revoke':
            $db->prepare('UPDATE apps SET status = 3 WHERE id = ?')->execute([$appId]);
            // 吊销该应用所有令牌
            $db->prepare('UPDATE oauth_tokens SET revoked = 1 WHERE app_id = ?')->execute([$appId]);
            break;
        case 'offline':
            $db->prepare('UPDATE apps SET status = 1 WHERE id = ?')->execute([$appId]);
            break;
    }
    header('Location: apps.php');
    exit;
}

// 搜索
$kw = trim($_GET['q'] ?? '');
$sql = 'SELECT a.*, u.nickname, u.email FROM apps a JOIN users u ON u.id = a.owner_id';
$args = [];
if ($kw) {
    $sql .= ' WHERE a.name LIKE ? OR a.client_id LIKE ? OR u.nickname LIKE ?';
    $args = ["%$kw%", "%$kw%", "%$kw%"];
}
$sql .= ' ORDER BY a.created_at DESC LIMIT 100';
$st = $db->prepare($sql);
$st->execute($args);
$apps = $st->fetchAll();

// 每应用密钥数
$keyCnt = $db->prepare('SELECT app_id, COUNT(*) c FROM api_keys GROUP BY app_id');
$keyCnt->execute();
$keyCntMap = array_column($keyCnt->fetchAll(), 'c', 'app_id');
// 每应用授权数
$authCnt = $db->prepare('SELECT app_id, COUNT(*) c FROM authorizations WHERE status = 1 GROUP BY app_id');
$authCnt->execute();
$authCntMap = array_column($authCnt->fetchAll(), 'c', 'app_id');

pageHead('应用管理', '<link rel="stylesheet" href="/css/user.css">');
pageNav($admin);
echo '<div class="shell">';
adminSidebar('apps');
contentOpen('应用管理', '全系统所有应用');
?>
    <form method="GET" style="margin-bottom:16px;">
      <div style="display:flex; gap:10px; max-width:420px;">
        <mdui-text-field name="q" label="搜索应用名 / client_id / 所有者" value="<?= htmlspecialchars($kw) ?>" icon="search--outlined" clearable full-width></mdui-text-field>
        <mdui-button variant="filled" icon="search--outlined" type="submit">搜索</mdui-button>
      </div>
    </form>

    <mdui-list>
      <?php foreach ($apps as $a): $stText = [1 => '开发中', 2 => '已上线', 3 => '已吊销'][(int)$a['status']] ?? '未知'; ?>
      <mdui-list-item nonclickable>
        <mdui-avatar slot="icon" style="--mdui-avatar-size:38px; border-radius:12px; background:linear-gradient(135deg,#a78bfa,#8b5cf6); font-size:15px;"><?= htmlspecialchars(mb_substr($a['name'], 0, 1)) ?></mdui-avatar>
        <div>
          <?= htmlspecialchars($a['name']) ?>
          <?= appStatusBadge((int)$a['status']) ?>
        </div>
        <span slot="description" style="font-size:12px;">
          <span style="font-family:ui-monospace,monospace;"><?= htmlspecialchars($a['client_id']) ?></span>
          · <?= htmlspecialchars($a['nickname']) ?> (<?= htmlspecialchars($a['email']) ?>)
          · <?= (int)($keyCntMap[$a['id']] ?? 0) ?> 密钥 / <?= (int)($authCntMap[$a['id']] ?? 0) ?> 授权
          · <?= substr($a['created_at'], 0, 10) ?>
        </span>
        <div slot="end-icon" style="display:flex; gap:4px;">
          <?php if ((int)$a['status'] === 1): ?>
          <form method="POST">
            <input type="hidden" name="action" value="online"><input type="hidden" name="app_id" value="<?= (int)$a['id'] ?>">
            <mdui-button variant="text" color="tertiary" type="submit" style="font-size:12px;">上线</mdui-button>
          </form>
          <?php elseif ((int)$a['status'] === 2): ?>
          <form method="POST">
            <input type="hidden" name="action" value="offline"><input type="hidden" name="app_id" value="<?= (int)$a['id'] ?>">
            <mdui-button variant="text" type="submit" style="font-size:12px;">转开发中</mdui-button>
          </form>
          <?php endif; ?>
          <?php if ((int)$a['status'] !== 3): ?>
          <form method="POST" onsubmit="return confirm('吊销该应用？其所有令牌将立即失效。');">
            <input type="hidden" name="action" value="revoke"><input type="hidden" name="app_id" value="<?= (int)$a['id'] ?>">
            <mdui-button variant="text" color="error" type="submit" style="font-size:12px;">吊销</mdui-button>
          </form>
          <?php endif; ?>
        </div>
      </mdui-list-item>
      <?php endforeach; ?>
    </mdui-list>
<?php
contentClose();
echo '</div>';
pageFoot(); ?>