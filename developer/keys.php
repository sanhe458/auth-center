<?php
/**
 * API 密钥列表（服务端渲染）
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';

$user = requireLoginPage();
$db = db();

// 吊销密钥
if (($_POST['action'] ?? '') === 'revoke') {
    $keyId = (int)($_POST['key_id'] ?? 0);
    $st = $db->prepare('SELECT k.id FROM api_keys k JOIN apps a ON a.id=k.app_id WHERE k.id=? AND a.owner_id=? LIMIT 1');
    $st->execute([$keyId, $user['id']]);
    if ($st->fetch()) {
        $db->prepare('UPDATE api_keys SET status = 0 WHERE id = ?')->execute([$keyId]);
    }
    header('Location: keys.php');
    exit;
}

$st = $db->prepare('SELECT k.*, a.name AS app_name, a.client_id FROM api_keys k
                    JOIN apps a ON a.id = k.app_id
                    WHERE a.owner_id = ? ORDER BY k.created_at DESC');
$st->execute([$user['id']]);
$keys = $st->fetchAll();

pageHead('API 密钥', '<link rel="stylesheet" href="/css/user.css?v=20260817">');
pageNav($user);
echo '<div class="shell">';
devSidebar('devkeys');
contentOpen('API 密钥', '管理各应用的访问密钥');
?>
    <div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
      <mdui-button variant="filled" icon="add--outlined" onclick="location.href='key-create.php'">生成密钥</mdui-button>
    </div>

    <mdui-list>
      <?php if (!$keys): ?>
      <mdui-card variant="elevated" style="border-radius:16px; padding:40px; text-align:center;">
        <mdui-icon name="key--outlined" style="font-size:44px; opacity:.3;"></mdui-icon>
        <div style="margin-top:12px; opacity:.7;">还没有密钥，点击右上角生成第一个</div>
      </mdui-card>
      <?php else: foreach ($keys as $k): $active = (int)$k['status'] === 1; ?>
      <mdui-list-item nonclickable>
        <mdui-avatar slot="icon" style="--mdui-avatar-size:42px; border-radius:14px; background:rgb(var(--mdui-color-surface-container-high));">
          <mdui-icon name="<?= $active ? 'key--outlined' : 'lock--outlined' ?>" style="font-size:20px;"></mdui-icon>
        </mdui-avatar>
        <?= htmlspecialchars($k['name'] ?: ($k['app_name'] . ' 密钥')) ?>
        <span slot="description" style="font-family:ui-monospace,monospace; font-size:12px;"><?= htmlspecialchars($k['key_prefix']) ?>•••••• · <?= htmlspecialchars($k['app_name']) ?><?= $k['last_used_at'] ? ' · 上次使用 ' . $k['last_used_at'] : '' ?></span>
        <?php if ($active): ?>
        <form slot="end-icon" method="POST" style="display:inline;" onsubmit="return confirm('确定吊销该密钥吗？');">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
          <mdui-button variant="text" color="error" icon="delete--outlined" type="submit"></mdui-button>
        </form>
        <mdui-badge slot="end-icon" color="tertiary">有效</mdui-badge>
        <?php else: ?>
        <mdui-badge slot="end-icon">已吊销</mdui-badge>
        <?php endif; ?>
      </mdui-list-item>
      <?php endforeach; endif; ?>
    </mdui-list>
<?php
contentClose();
echo '</div>';
pageFoot(); ?>