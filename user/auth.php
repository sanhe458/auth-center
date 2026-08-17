<?php
/**
 * 授权管理（服务端渲染）
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';
require_once __DIR__ . '/../api/lib/scopes.php';

$user = requireLoginPage();
$db = db();

// 撤回授权
if (($_POST['action'] ?? '') === 'revoke') {
    $authId = (int)($_POST['authorization_id'] ?? 0);
    $st = $db->prepare('SELECT id, app_id FROM authorizations WHERE id = ? AND user_id = ? LIMIT 1');
    $st->execute([$authId, $user['id']]);
    $row = $st->fetch();
    if ($row) {
        $db->prepare('UPDATE authorizations SET status = 0, updated_at = NOW() WHERE id = ?')->execute([$authId]);
        $db->prepare('UPDATE oauth_tokens SET revoked = 1 WHERE user_id = ? AND app_id = ?')
            ->execute([$user['id'], $row['app_id']]);
    }
    header('Location: auth.php');
    exit;
}

// 我授权的应用（作为用户授权的）
$st = $db->prepare('SELECT z.*, a.name AS app_name, a.client_id, a.description FROM authorizations z
                    JOIN apps a ON a.id = z.app_id
                    WHERE z.user_id = ? ORDER BY z.updated_at DESC');
$st->execute([$user['id']]);
$myAuths = $st->fetchAll();

// 我的应用被授权情况（作为应用所有者的授权）
$st = $db->prepare('SELECT z.*, a.name AS app_name, a.client_id, u.nickname, u.uid FROM authorizations z
                    JOIN apps a ON a.id = z.app_id
                    JOIN users u ON u.id = z.user_id
                    WHERE a.owner_id = ? AND z.status = 1 ORDER BY z.updated_at DESC LIMIT 20');
$st->execute([$user['id']]);
$receivedAuths = $st->fetchAll();

$scopeLabels = scopeLabels();

pageHead('授权管理', '<link rel="stylesheet" href="/css/user.css?v=20260817">');
pageNav($user);
echo '<div class="shell">';
pageSidebar('auth');
contentOpen('授权管理', '查看和管理你与应用之间的授权关系');
?>
    <div class="sec-title" style="margin:0 0 12px;">我授权的应用</div>
      <?php if (!$myAuths): ?>
      <mdui-card variant="elevated" style="border-radius:16px; padding:40px; text-align:center;">
        <mdui-icon name="verified_user--outlined" style="font-size:44px; opacity:.3;"></mdui-icon>
        <div style="margin-top:12px; opacity:.7;">还没有授权过任何应用</div>
      </mdui-card>
      <?php else: foreach ($myAuths as $z): $active = (int)$z['status'] === 1; ?>
      <div class="auth-card" style="background:rgb(var(--mdui-color-surface-container));">
        <mdui-avatar style="--mdui-avatar-size:42px; border-radius:14px; background:rgb(var(--mdui-color-surface-container-high));">
          <mdui-icon name="<?= $active ? 'verified_user--outlined' : 'history--outlined' ?>" style="font-size:20px;"></mdui-icon>
        </mdui-avatar>
        <div class="auth-main">
          <div class="auth-title"><?= htmlspecialchars($z['app_name']) ?></div>
          <div class="auth-desc">授权于 <?= substr($z['updated_at'], 0, 10) ?></div>
        </div>
        <div class="auth-actions">
          <?php if ($active): ?>
          <?php $sz = implode('、', array_filter(array_map(fn($s2) => $scopeLabels[$s2] ?? $s2, explode(',', $z['scopes'])))); ?>
          <mdui-badge color="tertiary">已授权<?= $sz ? ' · ' . htmlspecialchars($sz) : '' ?></mdui-badge>
          <mdui-button variant="text" icon="edit--outlined" onclick="location.href='auth-edit.php?id=<?= (int)$z['id'] ?>'">权限</mdui-button>
          <form method="POST" style="display:inline;" onsubmit="return confirm('确定撤回对「<?= htmlspecialchars($z['app_name']) ?>」的授权吗？');">
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="authorization_id" value="<?= (int)$z['id'] ?>">
            <mdui-button variant="text" color="error" icon="link_off--outlined" type="submit">撤回</mdui-button>
          </form>
          <?php else: ?>
          <mdui-badge>已撤回</mdui-badge>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; endif; ?>

    <?php if ($receivedAuths): ?>
    <div class="sec-title" style="margin:26px 0 12px;">我的应用收到的授权</div>
    <mdui-list>
      <?php foreach ($receivedAuths as $z): ?>
      <mdui-list-item nonclickable>
        <mdui-avatar slot="icon" style="--mdui-avatar-size:42px; border-radius:14px; background:rgb(var(--mdui-color-surface-container-high));">
          <mdui-icon name="person--outlined" style="font-size:20px;"></mdui-icon>
        </mdui-avatar>
        <?= htmlspecialchars($z['app_name']) ?> · <?= htmlspecialchars($z['nickname']) ?>
        <span slot="description">
          授权于 <?= substr($z['updated_at'], 0, 10) ?> ·
          <?php foreach (explode(',', $z['scopes']) as $s): ?><span style="margin-right:4px;"><?= $scopeLabels[$s] ?? $s ?></span><?php endforeach; ?>
        </span>
        <mdui-badge slot="end-icon" color="tertiary">已授权</mdui-badge>
      </mdui-list-item>
      <?php endforeach; ?>
    </mdui-list>
    <?php endif; ?>
<?php
contentClose();
echo '</div>';
pageFoot(); ?>