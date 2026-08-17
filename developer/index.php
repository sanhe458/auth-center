<?php
/**
 * 开发者控制台 · 总览（服务端渲染）
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';

$user = requireLoginPage();
$db = db();

// 统计：我拥有的应用/密钥/授权
$st = $db->prepare('SELECT COUNT(*) c FROM apps WHERE owner_id = ?');
$st->execute([$user['id']]);
$appCount = (int)$st->fetch()['c'];

$st = $db->prepare('SELECT COUNT(*) c FROM api_keys k JOIN apps a ON a.id=k.app_id WHERE a.owner_id = ? AND k.status = 1');
$st->execute([$user['id']]);
$keyCount = (int)$st->fetch()['c'];

$st = $db->prepare('SELECT COUNT(*) c FROM apps a WHERE a.owner_id = ? AND a.status = 2');
$st->execute([$user['id']]);
$onlineCount = (int)$st->fetch()['c'];

$st = $db->prepare('SELECT COUNT(*) c FROM authorizations z JOIN apps a ON a.id=z.app_id WHERE a.owner_id = ? AND z.status = 1');
$st->execute([$user['id']]);
$authCount = (int)$st->fetch()['c'];

// 最近动态：最近应用 + 最近密钥
$activities = [];
$st = $db->prepare('SELECT name, created_at FROM apps WHERE owner_id = ? ORDER BY created_at DESC LIMIT 3');
$st->execute([$user['id']]);
foreach ($st->fetchAll() as $r) {
    $activities[] = ['icon' => 'apps--outlined', 'title' => '创建了应用「' . $r['name'] . '」', 'time' => $r['created_at']];
}
$st = $db->prepare('SELECT k.name, k.created_at, a.name app FROM api_keys k JOIN apps a ON a.id=k.app_id WHERE a.owner_id = ? ORDER BY k.created_at DESC LIMIT 3');
$st->execute([$user['id']]);
foreach ($st->fetchAll() as $r) {
    $activities[] = ['icon' => 'key--outlined', 'title' => '生成了密钥' . ($r['name'] ? '「' . $r['name'] . '」' : '') . '（' . $r['app'] . '）', 'time' => $r['created_at']];
}
usort($activities, fn($a, $b) => strcmp($b['time'], $a['time']));
$activities = array_slice($activities, 0, 6);

pageHead('开发总览', '<link rel="stylesheet" href="/css/user.css?v=20260817">');
pageNav($user);
echo '<div class="shell">';
devSidebar('devindex');
contentOpen('开发者控制台', '管理你的应用、密钥与 API 接入');
?>
    <div class="stat-grid">
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="apps--outlined"></mdui-icon>
        <div class="num"><?= $appCount ?></div>
        <div class="lbl">我的应用</div>
      </mdui-card>
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="key--outlined"></mdui-icon>
        <div class="num"><?= $keyCount ?></div>
        <div class="lbl">有效密钥</div>
      </mdui-card>
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="trending_up--outlined"></mdui-icon>
        <div class="num"><?= $onlineCount ?></div>
        <div class="lbl">已上线应用</div>
      </mdui-card>
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="verified_user--outlined"></mdui-icon>
        <div class="num"><?= $authCount ?></div>
        <div class="lbl">收到授权</div>
      </mdui-card>
    </div>

    <div style="display:flex; gap:16px; margin-top:20px; flex-wrap:wrap;">
      <mdui-button variant="filled" icon="add--outlined" onclick="location.href='app-create.php'">创建应用</mdui-button>
      <mdui-button variant="tonal" icon="key--outlined" onclick="location.href='key-create.php'">生成密钥</mdui-button>
      <mdui-button variant="text" icon="menu_book--outlined" onclick="location.href='/docs/'">对接文档</mdui-button>
    </div>

    <div class="sec-title" style="margin:26px 0 12px;">最近动态</div>
    <mdui-card variant="elevated" style="border-radius:16px;">
      <mdui-list>
        <?php if (!$activities): ?>
        <mdui-list-item nonclickable>还没有动态，去创建第一个应用吧</mdui-list-item>
        <?php else: foreach ($activities as $act): ?>
        <mdui-list-item nonclickable>
          <mdui-icon slot="icon" name="<?= $act['icon'] ?>" style="font-size:20px;"></mdui-icon>
          <?= htmlspecialchars($act['title']) ?>
          <span slot="description"><?= htmlspecialchars($act['time']) ?></span>
        </mdui-list-item>
        <?php endforeach; endif; ?>
      </mdui-list>
    </mdui-card>
<?php
contentClose();
echo '</div>';
pageFoot(); ?>