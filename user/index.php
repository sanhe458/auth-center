<?php
/**
 * 用户控制台 · 总览（服务端渲染）
 * 常规用户视角：我的授权、账号信息
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';

$user = requireLoginPage();
$db = db();

// 常规用户统计：我授权的应用数、有效授权、收到的授权
$st = $db->prepare('SELECT COUNT(*) c FROM authorizations WHERE user_id = ? AND status = 1');
$st->execute([$user['id']]);
$authCount = (int)$st->fetch()['c'];

$st = $db->prepare('SELECT COUNT(*) c FROM authorizations WHERE user_id = ? AND status = 0');
$st->execute([$user['id']]);
$revokedCount = (int)$st->fetch()['c'];

$st = $db->prepare('SELECT COUNT(*) c FROM authorizations z JOIN apps a ON a.id=z.app_id WHERE a.owner_id = ? AND z.status = 1');
$st->execute([$user['id']]);
$receivedCount = (int)$st->fetch()['c'];

// 我授权过的应用（最近）
$st = $db->prepare('SELECT a.name, a.client_id, z.updated_at FROM authorizations z JOIN apps a ON a.id=z.app_id WHERE z.user_id = ? ORDER BY z.updated_at DESC LIMIT 5');
$st->execute([$user['id']]);
$myAuths = $st->fetchAll();

// 余额
$st = $db->prepare('SELECT balance FROM users WHERE id = ? LIMIT 1');
$st->execute([$user['id']]);
$balanceFen = (int)$st->fetch()['balance'];

// 积分
$st = $db->prepare('SELECT points FROM users WHERE id = ? LIMIT 1');
$st->execute([$user['id']]);
$points = (int)$st->fetch()['points'];

pageHead('总览', '<link rel="stylesheet" href="/css/user.css?v=20260817">');
pageNav($user);
echo '<div class="shell">';
pageSidebar('index');
contentOpen('总览', '欢迎回来，' . htmlspecialchars($user['nickname']) . '，管理你的账号与授权');
?>
    <div class="stat-grid">
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="verified_user--outlined"></mdui-icon>
        <div class="num"><?= $authCount ?></div>
        <div class="lbl">已授权应用</div>
      </mdui-card>
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="account_balance_wallet--outlined"></mdui-icon>
        <div class="num">¥ <?= number_format($balanceFen / 100, 2) ?></div>
        <div class="lbl">账户余额 · <a href="wallet.php" style="color:inherit;">去充值</a></div>
      </mdui-card>
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="stars--outlined"></mdui-icon>
        <div class="num"><?= number_format($points) ?></div>
        <div class="lbl">我的积分 · <a href="points.php" style="color:inherit;">明细</a></div>
      </mdui-card>
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="history--outlined"></mdui-icon>
        <div class="num"><?= $revokedCount ?></div>
        <div class="lbl">已撤回授权</div>
      </mdui-card>
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="people--outlined"></mdui-icon>
        <div class="num"><?= $receivedCount ?></div>
        <div class="lbl">应用收到授权</div>
      </mdui-card>
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="person--outlined"></mdui-icon>
        <div class="num"><?= htmlspecialchars(mb_substr($user['nickname'], 0, 1)) ?></div>
        <div class="lbl"><?= htmlspecialchars($user['email']) ?></div>
      </mdui-card>
    </div>

    <div style="display:flex; gap:16px; margin-top:20px; flex-wrap:wrap;">
      <mdui-button variant="filled" icon="verified_user--outlined" onclick="location.href='auth.php'">管理授权</mdui-button>
      <mdui-button variant="tonal" icon="person--outlined" onclick="location.href='profile.php'">个人设置</mdui-button>
      <mdui-button variant="text" icon="code--outlined" onclick="location.href='/developer/index.php'">开发者控制台</mdui-button>
    </div>

    <div class="sec-title" style="margin:26px 0 12px;">我授权的应用</div>
    <mdui-card variant="elevated" style="border-radius:16px;">
      <mdui-list>
        <?php if (!$myAuths): ?>
        <mdui-list-item nonclickable>还没有授权过任何应用，去 [体验中心](/docs/guide/demo.html) 试试登录吧</mdui-list-item>
        <?php else: foreach ($myAuths as $z): ?>
        <mdui-list-item nonclickable>
          <mdui-icon slot="icon" name="apps--outlined" style="font-size:20px;"></mdui-icon>
          <?= htmlspecialchars($z['name']) ?>
          <span slot="description" style="font-size:12px;">授权于 <?= htmlspecialchars($z['updated_at']) ?></span>
          <mdui-badge slot="end-icon" color="tertiary">已授权</mdui-badge>
        </mdui-list-item>
        <?php endforeach; endif; ?>
      </mdui-list>
    </mdui-card>
<?php
contentClose();
echo '</div>';
pageFoot(); ?>