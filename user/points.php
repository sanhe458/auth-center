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
