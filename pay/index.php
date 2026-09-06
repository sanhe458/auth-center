<?php
/**
 * 统一支付页（自研核心）
 * ------------------------------------------------------------------
 * 易支付客户端下单后拿到的 payurl 指向本页 ?order_no=<平台单号>。
 * 三种支付方式：
 *   1. 余额支付：登录用户余额付款（余额为 0 / 不足时该方式变灰）
 *   2. 微信支付：上游易支付收款（无需余额）
 *   3. 支付宝支付：上游易支付收款（无需余额）
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

// 商家名称：订单 pid → 商户 → 关联应用 → 应用名
$merchantName = '';
if ($order && !empty($order['pid'])) {
    $st = db()->prepare(
        'SELECT a.name FROM pay_merchants m LEFT JOIN apps a ON a.id = m.app_id WHERE m.pid = ? LIMIT 1'
    );
    $st->execute([$order['pid']]);
    $merchantName = (string)$st->fetchColumn();
}

$balanceFen = 0;
if ($me) {
    $st = db()->prepare('SELECT balance FROM users WHERE id = ?');
    $st->execute([$me['id']]);
    $balanceFen = (int)$st->fetch()['balance'];
}

$statusLabel = [0 => '待支付', 1 => '已支付', 2 => '已关闭'];
$balanceOk = $balanceFen >= (int)($order['amount_fen'] ?? 0);

/** 拼易支付标准同步跳转参数 */
function buildReturnUrlPhp(?array $o): string {
    if (!$o || empty($o['return_url'])) return '';
    $sep = str_contains($o['return_url'], '?') ? '&' : '?';
    return $o['return_url'] . $sep
        . 'out_trade_no=' . urlencode((string)$o['out_trade_no'])
        . '&trade_no=' . urlencode((string)$o['trade_no'])
        . '&trade_status=TRADE_SUCCESS';
}

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
/* 支付方式选择 */
.channels{display:flex;flex-direction:column;gap:10px;margin:16px 0;}
.channel{display:flex;align-items:center;gap:12px;border:2px solid #e5e0f5;border-radius:12px;padding:12px 14px;cursor:pointer;transition:border-color .15s,background .15s;background:#fff;}
.channel:hover{border-color:#b9a8e8;}
.channel.sel{border-color:#6750a4;background:#f7f4ff;}
.channel.disabled{opacity:.45;cursor:not-allowed;background:#f5f5f7;border-color:#e3e3e8;}
.channel.disabled:hover{border-color:#e3e3e8;}
.channel input{accent-color:#6750a4;width:18px;height:18px;flex-shrink:0;}
.ch-ico{font-size:20px;width:34px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:9px;flex-shrink:0;}
.ch-ico.bal{background:#f0edff;color:#6750a4;}
.ch-ico.wx{background:#e6f9ef;color:#07c160;}
.ch-ico.al{background:#e8f2ff;color:#1677ff;}
.ch-name{font-size:14px;font-weight:600;}
.ch-desc{font-size:12px;color:#999;margin-top:1px;}
.ch-tag{font-size:11px;color:#c62828;margin-left:auto;flex-shrink:0;}
/* 二维码弹层 */
.qr-mask{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:99;}
.qr-mask.show{display:flex;}
.qr-box{background:#fff;border-radius:16px;padding:24px;text-align:center;max-width:320px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.qr-box h3{margin:0 0 4px;font-size:17px;}
.qr-box .sub{font-size:13px;color:#888;margin-bottom:16px;}
.qr-box img{width:220px;height:220px;border-radius:10px;padding:10px;border:1px solid #eee;background:#fff;}
.qr-box .wait{font-size:12px;color:#999;margin-top:12px;}
.qr-actions{display:flex;gap:10px;margin-top:16px;}
.qr-actions mdui-button{flex:1;}
</style>';
?>
</head><body>
<div class="pay-wrap">
  <div class="pay-card">
    <h1>收银台</h1>
    <div class="pay-sub">Auth Center 收款</div>
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
      <div class="row"><span class="k">商家</span><span class="v"><?= htmlspecialchars($merchantName ?: $order['pid']) ?></span></div>

      <?php if (!$me): ?>
        <div class="login-box">
          <p style="font-size:14px;color:#555;margin:4px 0 14px;">付款需登录 Auth Center 账号</p>
          <mdui-button class="btn" variant="filled" onclick="location.href='/login.php?next='+encodeURIComponent(location.pathname+location.search)">去登录</mdui-button>
        </div>
      <?php else: ?>
        <div class="channels" id="channels">
          <label class="channel<?= $balanceOk ? ' sel' : ' disabled' ?>" data-ch="balance">
            <input type="radio" name="ch" value="balance" <?= $balanceOk ? 'checked' : 'disabled' ?>>
            <span class="ch-ico bal">💰</span>
            <span>
              <span class="ch-name">余额支付</span>
              <span class="ch-desc">余额 ¥ <?= number_format($balanceFen / 100, 2) ?></span>
            </span>
            <?php if (!$balanceOk): ?><span class="ch-tag">余额不足</span><?php endif; ?>
          </label>
          <label class="channel" data-ch="wxpay">
            <input type="radio" name="ch" value="wxpay">
            <span class="ch-ico wx">💚</span>
            <span>
              <span class="ch-name">微信支付</span>
              <span class="ch-desc">扫码支付，无需余额</span>
            </span>
          </label>
          <label class="channel" data-ch="alipay">
            <input type="radio" name="ch" value="alipay">
            <span class="ch-ico al">💙</span>
            <span>
              <span class="ch-name">支付宝</span>
              <span class="ch-desc">扫码支付，无需余额</span>
            </span>
          </label>
        </div>
        <mdui-button class="btn" variant="filled" icon="lock--outlined" id="btnPay">立即支付</mdui-button>
        <div class="hint" id="payHint"></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- 二维码弹层 -->
<div class="qr-mask" id="qrMask">
  <div class="qr-box">
    <h3 id="qrTitle">扫码支付</h3>
    <div class="sub" id="qrSub">请使用对应 App 扫码</div>
    <img id="qrImg" alt="二维码">
    <div class="wait" id="qrWait">等待支付结果…</div>
    <div class="qr-actions">
      <mdui-button variant="tonal" id="qrClose">关闭</mdui-button>
      <mdui-button variant="filled" id="qrOpen" onclick="window.open(document.getElementById('qrOpen').dataset.url,'_blank')">打开收银台</mdui-button>
    </div>
  </div>
</div>

<?php if ($order && (int)$order['status'] === 0 && $me): ?>
<script>
const ORDER_NO = <?= json_encode($order['trade_no']) ?>;
const PAID_RETURN_URL = <?= json_encode($order ? buildReturnUrlPhp($order) : '') ?>;

// 支付方式切换
const channels = document.querySelectorAll('.channel');
channels.forEach(el => {
  el.addEventListener('click', () => {
    if (el.classList.contains('disabled')) return;
    channels.forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    el.querySelector('input').checked = true;
  });
});

// 二维码弹层
const qrMask = document.getElementById('qrMask');
const qrImg = document.getElementById('qrImg');
const qrWait = document.getElementById('qrWait');
const qrOpenBtn = document.getElementById('qrOpen');
let pollTimer = null;

function showQr(channel, data) {
  qrImg.src = data.qrcode || '';
  qrOpenBtn.dataset.url = data.payurl || '';
  qrWait.textContent = '等待支付结果…';
  qrMask.classList.add('show');
  pollTimer = setInterval(pollStatus, 3000);
}

function hideQr() {
  qrMask.classList.remove('show');
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}
document.getElementById('qrClose').addEventListener('click', hideQr);

async function pollStatus() {
  try {
    const r = await fetch('/api/pay/status?order_no=' + encodeURIComponent(ORDER_NO));
    const d = await r.json();
    if (d.paid) {
      clearInterval(pollTimer); pollTimer = null;
      qrWait.textContent = '✅ 支付成功';
      setTimeout(() => {
        hideQr();
        if (PAID_RETURN_URL) location.href = PAID_RETURN_URL;
        else location.reload();
      }, 1200);
    }
  } catch (e) { /* 网络抖动忽略，继续轮询 */ }
}

document.getElementById('btnPay').addEventListener('click', async () => {
  const sel = document.querySelector('.channel.sel');
  const channel = sel ? sel.dataset.ch : 'wxpay';
  const btn = document.getElementById('btnPay');
  const hint = document.getElementById('payHint');
  btn.disabled = true; btn.textContent = '正在发起…'; hint.textContent = '';
  try {
    const r = await fetch('/api/pay/channel', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ order_no: ORDER_NO, channel }),
    });
    const d = await r.json();
    if (!r.ok) {
      btn.textContent = '支付失败';
      hint.textContent = d.error || '未知错误';
      setTimeout(()=>{ btn.disabled=false; btn.textContent='立即支付'; }, 2000);
      return;
    }
    if (channel === 'balance') {
      btn.textContent = '✅ 支付成功';
      setTimeout(() => {
        if (PAID_RETURN_URL) location.href = PAID_RETURN_URL;
        else location.reload();
      }, 1200);
    } else {
      btn.textContent = '等待扫码…';
      if (d.qrcode) {
        showQr(channel, d);
      } else if (d.payurl) {
        // 无二维码图片：直接跳转收银台（如 17yf cashier.php）
        btn.textContent = '正在跳转收银台…';
        setTimeout(() => { location.href = d.payurl; }, 500);
      } else {
        btn.textContent = '支付失败';
        hint.textContent = '未获取到支付链接';
        setTimeout(()=>{ btn.disabled=false; btn.textContent='立即支付'; }, 2000);
      }
    }
  } catch(e) {
    btn.textContent = '立即支付';
    btn.disabled = false;
    hint.textContent = '网络错误：' + e.message;
  }
});
</script>
<?php endif; ?>

<script>
// 已支付页：返回商户（带同步结果参数）
const btnDone = document.getElementById('btnDone');
if (btnDone) {
  const paidRet = <?= json_encode($order && (int)$order['status'] === 1 ? buildReturnUrlPhp($order) : '') ?>;
  btnDone.addEventListener('click', () => {
    if (paidRet) location.href = paidRet; else location.href = '/';
  });
}
</script>
<?php pageFoot(); ?>