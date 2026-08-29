<?php
/**
 * 我的积分 · 积分卡片 + 流水
 * 积分 = 平台内奖励点数（不可提现），由签到/活动/管理员发放
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';

$user = requireLoginPage();
$db = db();

// 查询积分
$st = $db->prepare('SELECT points FROM users WHERE id = ? LIMIT 1');
$st->execute([$user['id']]);
$points = (int)$st->fetch()['points'];

// 签到状态（服务端预渲染）
$today = date('Y-m-d');
$st = $db->prepare('SELECT streak, points FROM checkins WHERE user_id = ? AND checkin_date = ? LIMIT 1');
$st->execute([$user['id'], $today]);
$todayCheckin = $st->fetch();
$st = $db->prepare('SELECT checkin_date, streak FROM checkins WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$st->execute([$user['id']]);
$lastCheckin = $st->fetch();

$checkedIn = (bool)$todayCheckin;
$streak = 1;
if ($checkedIn) {
    $streak = (int)$todayCheckin['streak'];
} elseif ($lastCheckin && $lastCheckin['checkin_date'] === date('Y-m-d', strtotime('-1 day'))) {
    $streak = (int)$lastCheckin['streak'] + 1; // 昨天签了，今天连续
}
// 7 天循环奖励（与后端 checkinReward 一致）
$rewardMap = [1 => 10, 2 => 12, 3 => 14, 4 => 16, 5 => 18, 6 => 20, 7 => 30];
$nextAward = $rewardMap[(($streak - 1) % 7) + 1];

// 最近流水
$st = $db->prepare('SELECT type, amount, points_after, reference, remark, created_at FROM points_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 10');
$st->execute([$user['id']]);
$txns = $st->fetchAll();

$typeLabel = [
    'reward'       => ['奖励', 'success'],
    'consume'      => ['消费', 'primary'],
    'refund'       => ['退回', 'tertiary'],
    'admin_adjust' => ['管理员调整', 'warning'],
];

pageHead('我的积分', '<link rel="stylesheet" href="/css/user.css?v=20260817">');
pageNav($user);
echo '<div class="shell">';
pageSidebar('points');
contentOpen('我的积分', '积分明细与变动记录');
?>
    <!-- 积分卡片 -->
    <mdui-card variant="elevated" style="border-radius:16px; padding:24px; background:linear-gradient(135deg,#00696d,#004d40); color:#fff;">
      <div style="font-size:13px; opacity:.85;">当前积分</div>
      <div style="font-size:40px; font-weight:800; margin:6px 0 2px;"><?= number_format($points) ?></div>
      <div style="font-size:12px; opacity:.75;">积分不可提现 · 可用于平台内兑换或活动</div>
    </mdui-card>

    <!-- 签到卡片 -->
    <div class="sec-title" style="margin:22px 0 12px;">每日签到</div>
    <mdui-card variant="elevated" style="border-radius:16px; padding:20px;">
      <div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap;">
        <div style="flex:1; min-width:200px;">
          <div style="font-size:15px; font-weight:700;">
            <?php if ($checkedIn): ?>
              今日已签到 <mdui-badge color="success">已签</mdui-badge>
            <?php else: ?>
              今日还未签到
            <?php endif; ?>
          </div>
          <div style="font-size:13px; opacity:.7; margin-top:6px;">
            连续 <span id="ckStreak" style="font-weight:800; opacity:1;"><?= $streak ?></span> 天
            · 下次签到可得 <span id="ckNextAward" style="font-weight:800; opacity:1;"><?= $nextAward ?></span> 积分
          </div>
          <div style="font-size:12px; opacity:.5; margin-top:4px;">7 天循环奖励：10 / 12 / 14 / 16 / 18 / 20 / 30，漏签重置</div>
        </div>
        <mdui-button id="btnCheckin" <?= $checkedIn ? 'disabled' : '' ?> variant="filled" icon="event_available--outlined" style="min-width:130px;">
          <?= $checkedIn ? '明天再来' : '立即签到' ?>
        </mdui-button>
      </div>
      <div id="ckMsg" style="margin-top:12px; font-size:13px; opacity:.7;"></div>
    </mdui-card>

    <!-- 流水 -->
    <div class="sec-title" style="margin:22px 0 12px;">最近流水</div>
    <mdui-card variant="elevated" style="border-radius:16px;">
      <mdui-list>
        <?php if (!$txns): ?>
        <mdui-list-item nonclickable>还没有任何积分变动记录</mdui-list-item>
        <?php else: foreach ($txns as $t): ?>
        <?php
            [$label, $color] = $typeLabel[$t['type']] ?? ['未知', 'primary'];
            $sign = $t['amount'] >= 0 ? '+' : '';
        ?>
        <mdui-list-item nonclickable>
          <mdui-icon slot="icon" name="stars--outlined" style="font-size:20px;"></mdui-icon>
          <div><?= $label ?>
            <?php if ($t['remark']): ?><span style="opacity:.6; font-size:12px; margin-left:6px;"><?= htmlspecialchars($t['remark']) ?></span><?php endif; ?>
          </div>
          <span slot="description" style="font-size:12px;"><?= htmlspecialchars($t['created_at']) ?><?= $t['reference'] ? ' · ' . htmlspecialchars($t['reference']) : '' ?></span>
          <span slot="end-icon" style="font-weight:700; color:<?= $t['amount'] >= 0 ? '#1b8a5a' : '#c62828' ?>;"><?= $sign ?><?= number_format($t['amount']) ?></span>
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
const ptsEl = document.querySelector('.shell .content .stat-card .num'); // 总览页用

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
      // 刷新积分数字
      const pts = document.querySelector('.shell .content > mdui-card:first-child .num');
      if (pts) pts.textContent = d.points_after.toLocaleString();
      // 更新签到状态
      document.getElementById('ckStreak').textContent = d.streak;
      document.getElementById('ckNextAward').textContent = d.next_award;
      btn.innerHTML = '明天再来';
      btn.disabled = true;
      msg.innerHTML = '今日已签到，明天继续！';
      // 刷新流水（重载页面更简单）
      setTimeout(() => location.reload(), 1500);
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
</script>
