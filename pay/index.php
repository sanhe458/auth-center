<?php
/**
 * 统一支付页（自研核心）
 * ------------------------------------------------------------------
 * 易支付客户端下单后拿到的 payurl 指向本页 ?order_no=<平台单号>。
 * 付款人必须是 Auth Center 登录用户（有余额），确认支付即用余额付款：
 *   扣付款人 balance → 商户(pid) 加 balance → 触发 async 通知商户。
 *
 * 页面忽略渠道（支付方式），只专注"用余额付款"这一件事。
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';
require_once __DIR__ . '/../api/lib/pay.php';

$me = currentUser();  // 可为 null（未登录）

$tradeNo = trim($_GET['order_no'] ?? '');
$order = null;
if ($tradeNo) {
    $st = db()->prepare('SELECT * FROM pay_orders WHERE trade_no = ? LIMIT 1');
    $st->execute([$tradeNo]);
    $order = $st->fetch() ?: null;
}

$balanceFen = 0;
if ($me) {
    $st = db()->prepare('SELECT balance FROM users WHERE id = ?');
    $st->execute([$me['id']]);
    $balanceFen = (int)$st->fetch()['balance'];
}

$statusLabel = [0 => '待支付', 1 => '已支付', 2 => '已关闭'];

pageHead('收银台');
echo '<style>
.pay-wrap{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;padding:24px;background:linear-gradient(160deg,#f4f0ff,#eef2ff);}
.pay-card{width:100%;max-width:420px;background:#fff;border-radius:20px;box-shadow:0 12px 40px rgba(80,60,180,.12);padding:32px;box-sizing:border-box;}
.pay-card h1{font-size:20px;margin:0 0 4px;}
.pay-sub{font-size:13px;color:#666;margin-bottom:20px;}
.amount{font-size:42px;font-weight:800;background:linear-gradient(135deg,#6750a4,#3700b3);-webkit-background-clip:text;background-clip:text;color:transparent;margin:12px 0;}
.row{display:flex;justify-content:space-between;font-size:14px;padding:8px 0;border-bottom:1px dashed #eee;}
.row:last-of-type{border-bottom:none;}
.row .k{color:#888;}
.row .v{font-weight:600;word-break:break-all;text-align:right;max-width:60%;}
.bal{background:#f4f2ff;border-radius:12px;padding:14px 16px;font-size:14px;margin:16px 0;}
.bal b{color:#3700b3;font-size:18px;}
.err{background:#fff3f3;color:#c62828;border:1px solid #ffd6d6;border-radius:12px;padding:12px 16px;font-size:14px;margin-bottom:16px;}
.btn{width:100%;}
.hint{font-size:12px;color:#999;text-align:center;margin-top:14px;line-height:1.6;}
.login-box{text-align:center;padding-top:8px;}
</style>';
?>
</head><body>
<div class="pay-wrap">
  <div class="pay-card">
    <h1>收银台</h1>
    <div class="pay-sub">Auth Center 余额支付</div>
    <?php if (!$order): ?>
      <div class="err">订单不存在或链接无效</div>
    <?php elseif ((int)$order['status'] === 2): ?>
      <div class="err">该订单已关闭，请重新下单</div>
      <mdui-button class="btn" variant="tonal" onclick="history.back()">返回</mdui-button>
    <?php elseif ((int)$order['status'] === 1): ?>
      <div class="bal">✅ 本订单已于 <?= htmlspecialchars($order['paid_at'] ?? '') ?> 支付完成。</div>
      <mdui-button class="btn" variant="filled" id="btnDone">返回商户</mdui-button>
    <?php else: ?>
      <div class="amount">¥ <?= number_format($order['amount_fen'] / 100, 2) ?></div>
      <div class="row"><span class="k">商品</span><span class="v"><?= htmlspecialchars($order['name'] ?: '-') ?></span></div>
      <div class="row"><span class="k">订单号</span><span class="v"><?= htmlspecialchars($order['trade_no']) ?></span></div>
      <div class="row"><span class="k">商家</span><span class="v"><?= htmlspecialchars($order['pid']) ?></span></div>

      <?php if (!$me): ?>
        <div class="login-box">
          <p style="font-size:14px;color:#555;margin:4px 0 14px;">付款需登录 Auth Center 账号</p>
          <mdui-button class="btn" variant="filled" onclick="location.href='/login.php?next='+encodeURIComponent(location.pathname+location.search)">去登录</mdui-button>
        </div>
      <?php elseif ($balanceFen < (int)$order['amount_fen']): ?>
        <div class="bal">账户余额 <b>¥ <?= number_format($balanceFen / 100, 2) ?></b> 不足</div>
        <div class="err">余额不足，请先充值再回来支付。</div>
        <mdui-button class="btn" variant="filled" onclick="location.href='/user/wallet.php'">去充值</mdui-button>
        <div class="hint">充值后会回到本站，重新打开本页即可继续支付。</div>
      <?php else: ?>
        <div class="bal">账户余额 <b>¥ <?= number_format($balanceFen / 100, 2) ?></b></div>
        <mdui-button class="btn" variant="filled" icon="lock--outlined" id="btnPay">确认支付 ¥ <?= number_format($order['amount_fen'] / 100, 2) ?></mdui-button>
        <div class="hint">确认后将从您的余额扣除该笔金额并支付给「<?= htmlspecialchars($order['pid']) ?>」。</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($order && (int)$order['status'] === 0 && $me && $balanceFen >= (int)$order['amount_fen']): ?>
<script>
const ORDER_NO = <?= json_encode($order['trade_no']) ?>;
const RETURN_URL = <?= json_encode($order['return_url']) ?>;

document.getElementById('btnPay').addEventListener('click', async () => {
  const btn = document.getElementById('btnPay');
  btn.disabled = true; btn.textContent = '正在支付...';
  let done = false;
  try {
    const r = await fetch('/api/pay/pay', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ order_no: ORDER_NO }),
    });
    const d = await r.json();
    if (r.ok && d.success) {
      btn.textContent = '✅ 支付成功';
      done = true;
      // 触发通知商户 + 跳转
      setTimeout(() => {
        if (RETURN_URL) location.href = RETURN_URL;
        else location.reload();
      }, 1500);
    } else {
      btn.textContent = '支付失败';
      const err = d.error || '未知错误';
      alert('支付失败：' + err);
      setTimeout(()=>{ btn.disabled=false; btn.textContent='确认支付'; }, 2000);
    }
  } catch(e) {
    btn.textContent = '确认支付';
    btn.disabled = false;
    alert('网络错误：' + e.message);
  }
});
</script>
<?php endif; ?>

<script>
// 已支付页：返回商户
const btnDone = document.getElementById('btnDone');
if (btnDone) {
  btnDone.addEventListener('click', () => {
    const ret = <?= json_encode($order['return_url'] ?? '') ?>;
    if (ret) location.href = ret; else location.href = '/';
  });
}
</script>
<?php pageFoot(); ?>
