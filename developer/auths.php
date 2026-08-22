<?php
/**
 * 开发者控制台 · 收到的授权（服务端渲染）
 * 展示我拥有的应用被其他用户授权的记录
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';
require_once __DIR__ . '/../api/lib/scopes.php';

$user = requireLoginPage();
$db = db();
$scopeLabels = scopeLabels();

$st = $db->prepare('SELECT z.id, z.app_id, z.scopes, z.updated_at, z.status,
                    a.name AS app_name, a.client_id, u.nickname, u.uid, u.avatar
                    FROM authorizations z
                    JOIN apps a ON a.id = z.app_id
                    JOIN users u ON u.id = z.user_id
                    WHERE a.owner_id = ? ORDER BY z.updated_at DESC LIMIT 50');
$st->execute([$user['id']]);
$auths = $st->fetchAll();

pageHead('收到的授权', '<link rel="stylesheet" href="/css/user.css?v=20260817">');
pageNav($user);
echo '<div class="shell">';
devSidebar('devauths');
contentOpen('收到的授权', '其他用户对你有应用授权的情况');
?>
      <?php if (!$auths): ?>
      <mdui-card variant="elevated" style="border-radius:16px; padding:40px; text-align:center;">
        <mdui-icon name="verified_user--outlined" style="font-size:44px; opacity:.3;"></mdui-icon>
        <div style="margin-top:12px; opacity:.7;">你的应用还没有收到任何授权</div>
      </mdui-card>
      <?php else: foreach ($auths as $z): $active = (int)$z['status'] === 1; ?>
      <div class="auth-card" data-glass="container" data-glass-radius="20" style="background:rgb(var(--mdui-color-surface-container));">
        <mdui-avatar style="--mdui-avatar-size:42px; border-radius:14px; background:rgb(var(--mdui-color-surface-container-high));">
          <?php if (!empty($z['avatar'])): ?><img src="<?= htmlspecialchars($z['avatar']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:14px;"><?php else: ?><mdui-icon name="person--outlined" style="font-size:20px;"></mdui-icon><?php endif; ?>
        </mdui-avatar>
        <div class="auth-main">
          <div class="auth-title"><?= htmlspecialchars($z['nickname']) ?></div>
          <div class="auth-desc">授权给「<?= htmlspecialchars($z['app_name']) ?>」 · <?= substr($z['updated_at'], 0, 10) ?></div>
        </div>
        <div class="auth-actions">
          <?php $sz = implode('、', array_filter(array_map(fn($s2) => $scopeLabels[$s2] ?? $s2, explode(',', $z['scopes'])))); ?>
          <span style="font-size:12px; opacity:.6; margin-right:2px;"><?= htmlspecialchars($sz) ?></span>
          <?php if ($active): ?><mdui-badge color="tertiary">已授权</mdui-badge><?php else: ?><mdui-badge>已撤回</mdui-badge><?php endif; ?>
        </div>
      </div>
      <?php endforeach; endif; ?>
<?php
contentClose();
echo '</div>';
pageFoot();
