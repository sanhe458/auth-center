<?php
/**
 * 我的余额 · 充值 + 流水
 * 充值通道为占位：等支付渠道 API 接入后，前端填充真实下单参数
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';

$user = requireLoginPage();
$db = db();

// 查询余额
$st = $db->prepare('SELECT balance FROM users WHERE id = ? LIMIT 1');
$st->execute([$user['id']]);
$balanceFen = (int)$st->fetch()['balance'];

// 最近流水
$st = $db->prepare('SELECT type, amount, balance_after, reference, remark, created_at FROM balance_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 10');
$st->execute([$user['id']]);
$txns = $st->fetchAll();

$typeLabel = [
    'recharge'     => ['充值', 'tertiary'],
    'consume'      => ['消费', 'primary'],
    'refund'       => ['退款', 'success'],
    'gift'         => ['赠送', 'success'],
    'admin_adjust' => ['管理员调整', 'warning'],
];

pageHead('我的余额', '<link rel="stylesheet" href="/css/user.css?v=20260817">');
pageNav($user);
echo '<div class="shell">';
pageSidebar('wallet');
contentOpen('我的余额', '账户余额与充值');
?>
    <!-- 余额卡片 -->
    <mdui-card variant="elevated" style="border-radius:16px; padding:24px; background:linear-gradient(135deg,#6750a4,#3700b3); color:#fff;">
      <div style="font-size:13px; opacity:.85;">账户余额</div>
      <div style="font-size:40px; font-weight:800; margin:6px 0 2px;">¥ <?= number_format($balanceFen / 100, 2) ?></div>
      <div style="font-size:12px; opacity:.75;">单位：元 · 最近流水见下方</div>
    </mdui-card>

    <!-- 充值入口 -->
    <div class="sec-title" style="margin:22px 0 12px;">充值</div>
    <mdui-card variant="elevated" style="border-radius:16px; padding:20px;">
      <div style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
        <mdui-text-field id="amt" label="充值金额（元）" variant="outlined" type="number" min="0.01" max="1000" step="0.01" placeholder="输入金额" style="width:160px;"></mdui-text-field>
        <mdui-select id="channel" label="支付方式" value="alipay" style="width:150px;">
          <mdui-menu-item value="alipay">支付宝</mdui-menu-item>
          <mdui-menu-item value="wxpay">微信支付</mdui-menu-item>
        </mdui-select>
        <mdui-button id="btnTopup" variant="filled" icon="payments--outlined">充值</mdui-button>
      </div>
      <div id="topupMsg" style="margin-top:12px; font-size:13px; opacity:.7;">选择金额和支付方式，点击充值跳转支付。</div>
    </mdui-card>

    <!-- 卡密兑换 -->
    <div class="sec-title" style="margin:22px 0 12px;">卡密兑换</div>
    <mdui-card variant="elevated" style="border-radius:16px; padding:20px;">
      <div style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
        <mdui-text-field id="cardCode" label="充值卡密" variant="outlined" placeholder="XXXX-XXXX-XXXX-XXXX" style="width:260px;"></mdui-text-field>
        <mdui-button id="btnRedeem" variant="tonal" icon="redeem--outlined">兑换</mdui-button>
      </div>
      <div id="redeemMsg" style="margin-top:12px; font-size:13px; opacity:.7;">
        输入卡密自动充值到余额，支持 XXXX-XXXX-XXXX-XXXX 格式。
      </div>
    </mdui-card>

    <!-- 流水 -->
    <div class="sec-title" style="margin:22px 0 12px;">最近流水</div>
    <mdui-card variant="elevated" style="border-radius:16px;">
      <mdui-list>
        <?php if (!$txns): ?>
        <mdui-list-item nonclickable>还没有任何余额变动记录</mdui-list-item>
        <?php else: foreach ($txns as $t): ?>
        <?php
            [$label, $color] = $typeLabel[$t['type']] ?? ['未知', 'primary'];
            $sign = $t['amount'] >= 0 ? '+' : '';
        ?>
        <mdui-list-item nonclickable>
          <mdui-icon slot="icon" name="receipt_long--outlined" style="font-size:20px;"></mdui-icon>
          <div><?= $label ?>
            <?php if ($t['remark']): ?><span style="opacity:.6; font-size:12px; margin-left:6px;"><?= htmlspecialchars($t['remark']) ?></span><?php endif; ?>
          </div>
          <span slot="description" style="font-size:12px;"><?= htmlspecialchars($t['created_at']) ?><?= $t['reference'] ? ' · ' . htmlspecialchars($t['reference']) : '' ?></span>
          <span slot="end-icon" style="font-weight:700; color:<?= $t['amount'] >= 0 ? '#1b8a5a' : '#c62828' ?>;"><?= $sign ?><?= number_format($t['amount'] / 100, 2) ?></span>
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
// 充值：调 prepare 下单，返回支付 URL 或二维码
function getChannelVal() {
  const sel = document.getElementById('channel');
  return (sel && sel.value) ? sel.value : 'alipay';
}
document.getElementById('btnTopup').addEventListener('click', async () => {
  const amt = document.getElementById('amt').value;
  const msg = document.getElementById('topupMsg');
  if (!amt || parseFloat(amt) <= 0) { toast.warning('请输入有效金额'); return; }
  const t = toast.loading('正在发起充值...');
  try {
    const r = await fetch('/api/balance/recharge/prepare', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ amount_yuan: parseFloat(amt), channel: getChannelVal() }),
    });
    const r = await fetch('/api/balance/recharge/prepare', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ amount_yuan: amount, channel }),
    });
    const d = await r.json();
    if (r.ok) {
      const p = d;
      if (p.pay_url) {
        t.done('订单已生成', `金额 ¥${p.amount_yuan}，请前往支付`);
        msg.innerHTML = `订单 ${p.order_no} 已生成，金额 ¥${p.amount_yuan}。`;
        msg.innerHTML += `<br><a href="${p.pay_url}" target="_blank" rel="noopener"><mdui-button variant="filled" icon="open_in_new--outlined" style="margin-top:8px;">前往支付</mdui-button></a>`;
        msg.innerHTML += `<br><span style="font-size:12px;opacity:.6;">支付完成后自动刷新到账...</span>`;
        startPoll(p.order_no);
      } else if (p.qrcode) {
        t.done('订单已生成', `请扫码支付 ¥${p.amount_yuan}`);
        msg.innerHTML = `请扫码支付 ¥${p.amount_yuan}：<br><img id="payQr" alt="" style="width:180px;height:180px;margin-top:8px;"><br><span style="font-size:12px;opacity:.6;">支付完成后自动刷新到账...</span>`;
        const qrImg = document.getElementById('payQr');
        if (qrImg) qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(p.qrcode);
        startPoll(p.order_no);
      } else {
        t.fail('订单已生成', '但未能获取支付地址：' + p.order_no);
      }
    } else {
      t.fail('发起失败', d.error || d.message || '未知错误');
    }
  } catch (e) {
    t.fail('网络错误', e.message);
  }
});

// 轮询订单状态，支付成功后自动刷新页面
let pollTimer = null;
function startPoll(orderNo) {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    try {
      const r = await fetch('/api/balance/recharge/status?order_no=' + encodeURIComponent(orderNo));
      const d = await r.json();
      if (r.ok && d.paid) {
        clearInterval(pollTimer);
        toast.success('支付成功', `¥${d.amount_yuan} 已到账！`);
        setTimeout(() => location.reload(), 1500);
      }
    } catch (e) { /* 忽略临时错误，继续轮询 */ }
  }, 3000);
  // 3 分钟后停止轮询（防泄漏）
  setTimeout(() => { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }, 180000);
}

// 卡密兑换
document.getElementById('btnRedeem').addEventListener('click', async () => {
  const code = document.getElementById('cardCode').value.trim();
  if (!code) { toast.warning('请输入卡密'); return; }
  const t = toast.loading('正在兑换...');
  try {
    const r = await fetch('/api/balance/card/redeem', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code }),
    });
    const d = await r.json();
    if (r.ok) {
      t.done('兑换成功', `到账 ¥${d.amount_yuan}，当前余额 ¥${d.balance_yuan}`);
      setTimeout(() => location.reload(), 1200);
    } else {
      t.fail('兑换失败', d.error || d.message || '未知错误');
    }
  } catch (e) {
    t.fail('网络错误', e.message);
  }
});
</script>
