<?php
/**
 * 管理后台 · 仪表盘
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';

$admin = requireAdminPage();
$db = db();

// 统计
$stats = [];
foreach ([
    'users'  => 'SELECT COUNT(*) c FROM users',
    'apps'   => 'SELECT COUNT(*) c FROM apps',
    'keys'   => 'SELECT COUNT(*) c FROM api_keys',
    'tokens' => 'SELECT COUNT(*) c FROM oauth_tokens WHERE revoked = 0',
    'auths'  => 'SELECT COUNT(*) c FROM authorizations WHERE status = 1',
    'codes'  => 'SELECT COUNT(*) c FROM oauth_codes',
] as $k => $sql) {
    $st = $db->prepare($sql);
    $st->execute();
    $stats[$k] = (int)$st->fetch()['c'];
}

// 今日新增
$st = $db->prepare('SELECT COUNT(*) c FROM users WHERE created_at >= CURDATE()');
$st->execute();
$stats['today_users'] = (int)$st->fetch()['c'];
$st = $db->prepare('SELECT COUNT(*) c FROM apps WHERE created_at >= CURDATE()');
$st->execute();
$stats['today_apps'] = (int)$st->fetch()['c'];

// 最近注册用户
$st = $db->prepare('SELECT uid, nickname, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 6');
$st->execute();
$recentUsers = $st->fetchAll();

// 最近创建应用
$st = $db->prepare('SELECT a.name, a.client_id, a.status, u.nickname, a.created_at FROM apps a JOIN users u ON u.id = a.owner_id ORDER BY a.created_at DESC LIMIT 6');
$st->execute();
$recentApps = $st->fetchAll();

pageHead('仪表盘', '<link rel="stylesheet" href="/css/user.css">');
pageNav($admin);
echo '<div class="shell">';
adminSidebar('dashboard');
contentOpen('管理后台', '全系统概况');
?>
    <div class="stat-grid">
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="people--outlined"></mdui-icon>
        <div class="num"><?= $stats['users'] ?></div>
        <div class="lbl">用户总数（今日 +<?= $stats['today_users'] ?>）</div>
      </mdui-card>
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="apps--outlined"></mdui-icon>
        <div class="num"><?= $stats['apps'] ?></div>
        <div class="lbl">应用总数（今日 +<?= $stats['today_apps'] ?>）</div>
      </mdui-card>
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="key--outlined"></mdui-icon>
        <div class="num"><?= $stats['keys'] ?></div>
        <div class="lbl">密钥总数</div>
      </mdui-card>
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="vpn_key--outlined"></mdui-icon>
        <div class="num"><?= $stats['tokens'] ?></div>
        <div class="lbl">有效令牌</div>
      </mdui-card>
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="verified_user--outlined"></mdui-icon>
        <div class="num"><?= $stats['auths'] ?></div>
        <div class="lbl">有效授权</div>
      </mdui-card>
      <mdui-card class="stat-card" variant="elevated">
        <mdui-icon class="ic" name="confirmation_number--outlined"></mdui-icon>
        <div class="num"><?= $stats['codes'] ?></div>
        <div class="lbl">授权码（未用）</div>
      </mdui-card>
    </div>

    <div style="display:flex; gap:16px; margin-top:26px; flex-wrap:wrap;">
      <div style="flex:1; min-width:280px;">
        <div class="sec-title" style="margin:0 0 12px;">最近注册</div>
        <mdui-card variant="elevated" style="border-radius:16px;">
          <mdui-list>
            <?php foreach ($recentUsers as $u): ?>
            <mdui-list-item nonclickable>
              <mdui-avatar slot="icon" style="--mdui-avatar-size:34px; border-radius:10px; background:linear-gradient(135deg,#fbbf24,#f59e0b); font-size:14px;"><?= htmlspecialchars(mb_substr($u['nickname'], 0, 1)) ?></mdui-avatar>
              <?= htmlspecialchars($u['nickname']) ?> <?= $u['role'] === 'admin' ? '<mdui-badge>管理员</mdui-badge>' : '' ?>
              <span slot="description" style="font-size:12px;"><?= htmlspecialchars($u['email']) ?> · <?= substr($u['created_at'], 0, 16) ?></span>
            </mdui-list-item>
            <?php endforeach; ?>
          </mdui-list>
        </mdui-card>
      </div>
      <div style="flex:1; min-width:280px;">
        <div class="sec-title" style="margin:0 0 12px;">最近应用</div>
        <mdui-card variant="elevated" style="border-radius:16px;">
          <mdui-list>
            <?php foreach ($recentApps as $a): ?>
            <mdui-list-item nonclickable>
              <mdui-avatar slot="icon" style="--mdui-avatar-size:34px; border-radius:10px; background:linear-gradient(135deg,#2dd4bf,#0ea5e9); font-size:14px;"><?= htmlspecialchars(mb_substr($a['name'], 0, 1)) ?></mdui-avatar>
              <?= htmlspecialchars($a['name']) ?>
              <span slot="description" style="font-size:12px;"><?= htmlspecialchars($a['nickname']) ?> · <?= substr($a['created_at'], 0, 16) ?></span>
              <?= appStatusBadge((int)$a['status']) ?>
            </mdui-list-item>
            <?php endforeach; ?>
          </mdui-list>
        </mdui-card>
      </div>
    </div>
<?php
contentClose();
echo '</div>';
pageFoot(); ?>