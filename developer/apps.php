<?php
/**
 * 我的应用（服务端渲染）
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';

$user = requireLoginPage();
$db = db();

$st = $db->prepare('SELECT * FROM apps WHERE owner_id = ? ORDER BY created_at DESC');
$st->execute([$user['id']]);
$apps = $st->fetchAll();

$gradients = [
    'linear-gradient(135deg,#2dd4bf,#0ea5e9)',
    'linear-gradient(135deg,#a78bfa,#8b5cf6)',
    'linear-gradient(135deg,#fbbf24,#f59e0b)',
    'linear-gradient(135deg,#34d399,#10b981)',
    'linear-gradient(135deg,#f472b6,#ec4899)',
    'linear-gradient(135deg,#60a5fa,#3b82f6)',
];
$icons = ['movie--outlined', 'record_voice_over--outlined', 'smart_toy--outlined', 'tv--outlined', 'extension--outlined', 'cloud--outlined'];

pageHead('我的应用', '<link rel="stylesheet" href="/css/user.css">');
pageNav($user);
echo '<div class="shell">';
devSidebar('devapps');
contentOpen('我的应用', '管理接入 Auth Center 的应用');
?>
    <div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
      <mdui-button variant="filled" icon="add--outlined" onclick="location.href='app-create.php'">创建应用</mdui-button>
    </div>

    <mdui-list>
      <?php if (!$apps): ?>
      <mdui-card variant="elevated" style="border-radius:16px; padding:40px; text-align:center;">
        <mdui-icon name="apps--outlined" style="font-size:44px; opacity:.3;"></mdui-icon>
        <div style="margin-top:12px; opacity:.7;">还没有应用，点击右上角创建第一个</div>
      </mdui-card>
      <?php else: foreach ($apps as $i => $app): $g = $gradients[$i % count($gradients)]; $ic = $icons[$i % count($icons)]; ?>
      <mdui-list-item nonclickable>
        <mdui-avatar slot="icon" style="--mdui-avatar-size:42px; border-radius:14px;"><?php if (!empty($app['icon'])): ?><img src="<?= htmlspecialchars($app['icon']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:14px;"><?php else: ?><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:<?= $g ?>;border-radius:14px;"><mdui-icon name="<?= $ic ?>" style="font-size:22px;"></mdui-icon></div><?php endif; ?></mdui-avatar>
        <?= htmlspecialchars($app['name']) ?>
        <span slot="description" style="font-family:ui-monospace,monospace; font-size:12px;">client_id: <?= htmlspecialchars($app['client_id']) ?> · 创建于 <?= substr($app['created_at'], 0, 10) ?></span>
        <mdui-button slot="end-icon" variant="text" icon="settings--outlined" onclick="location.href='app-detail.php?id=<?= urlencode($app['client_id']) ?>'"></mdui-button>
        <?= appStatusBadge((int)$app['status']) ?>
      </mdui-list-item>
      <?php endforeach; endif; ?>
    </mdui-list>
<?php
contentClose();
echo '</div>';
pageFoot(); ?>