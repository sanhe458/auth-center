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

// 签到状态
$today = date('Y-m-d');
$st = $db->prepare('SELECT streak, points FROM checkins WHERE user_id = ? AND checkin_date = ? LIMIT 1');
$st->execute([$user['id'], $today]);
$todayCheckin = $st->fetch();
$st = $db->prepare('SELECT checkin_date, streak FROM checkins WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$st->execute([$user['id']]);
$lastCheckin = $st->fetch();
$cCheckedIn = (bool)$todayCheckin;
$cStreak = 1;
if ($cCheckedIn) {
    $cStreak = (int)$todayCheckin['streak'];
} elseif ($lastCheckin && $lastCheckin['checkin_date'] === date('Y-m-d', strtotime('-1 day'))) {
    $cStreak = (int)$lastCheckin['streak'] + 1;
}
$rewardMap = [1 => 10, 2 => 12, 3 => 14, 4 => 16, 5 => 18, 6 => 20, 7 => 30];
$cNextAward = $rewardMap[(($cStreak - 1) % 7) + 1];

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

    <!-- 签到卡片 -->
    <mdui-card variant="elevated" style="border-radius:16px; padding:16px 20px; margin-top:20px; background:linear-gradient(135deg,#1a237e,#311b92); color:#fff;">
      <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
        <div style="flex:1; min-width:160px;">
          <div style="font-size:14px; font-weight:700;">
            <?php if ($cCheckedIn): ?>
              <mdui-icon name="event_available--outlined" style="font-size:18px; vertical-align:middle; margin-right:4px;"></mdui-icon>今日已签到
            <?php else: ?>
              今日签到领积分
            <?php endif; ?>
          </div>
          <div style="font-size:12px; opacity:.8; margin-top:4px;">
            连续 <span id="ckStreak" style="font-weight:800; opacity:1;"><?= $cStreak ?></span> 天
            · 签到可得 <span id="ckNextAward" style="font-weight:800; opacity:1;"><?= $cNextAward ?></span> 积分
          </div>
        </div>
        <mdui-button id="btnCheckin" <?= $cCheckedIn ? 'disabled' : '' ?> variant="filled" icon="event_available--outlined" style="min-width:110px;" <?= $cCheckedIn ? '' : 'color="#fff"' ?>>
          <?= $cCheckedIn ? '已签到' : '立即签到' ?>
        </mdui-button>
      </div>
      <div id="ckMsg" style="margin-top:8px; font-size:12px; opacity:.7;"></div>
    </mdui-card>

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
pageFoot();
?>
<script>
const btn = document.getElementById('btnCheckin');
const msg = document.getElementById('ckMsg');
const ptsCard = document.querySelector('.stat-card:nth-child(3) .num');

btn?.addEventListener('click', async () => {
  if (btn.disabled) return;
  btn.disabled = true;
  btn.innerHTML = '签到中...';
  const t = toast.loading('正在签到...');
  try {
    const r = await fetch('/api/checkin/do', { method: 'POST' });
    const d = await r.json();
    if (r.ok) {
      t.done('签到成功', `获得 ${d.award} 积分，连续 ${d.streak} 天`);
      // 刷新积分 stat-card
      if (ptsCard) ptsCard.textContent = d.points_after.toLocaleString();
      // 更新签到状态
      document.getElementById('ckStreak').textContent = d.streak;
      document.getElementById('ckNextAward').textContent = d.next_award;
      btn.innerHTML = '已签到';
      btn.disabled = true;
      msg.innerHTML = '今日已签到，明天继续！';
    } else {
      t.fail('签到失败', d.error || d.message || '未知错误');
      btn.disabled = false;
      btn.innerHTML = '立即签到';
      msg.innerHTML = d.error || d.message || '';
    }
  } catch (e) {
    t.fail('网络错误', e.message);
    btn.disabled = false;
    btn.innerHTML = '立即签到';
  }
});
</script> ?>