<?php
/**
 * 用户端 · 应用余额（D+1）
 * 账户按用户维度：所有应用收款汇总到同一应用余额账户。
 * 应用余额 = 可提现(withdrawable) + 不可提现/待结算(pending)
 * 资金流：收款 → pending → 满24h(D+1) → withdrawable → 提现 → 通用余额
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';
require_once __DIR__ . '/../api/lib/app_balance.php';

$user = requireLoginPage();
$balance = appBalanceInfo((int)$user['id'], 30);

$typeLabel = [
    'income'   => ['余额收款', 'success'],
    'settle'   => ['D+1结算', 'tertiary'],
    'withdraw' => ['提现', 'primary'],
];

pageHead('应用余额', '<link rel="stylesheet" href="/css/user.css?v=20260817">');
pageNav($user);
echo '<div class="shell">';
pageSidebar('appbal');
contentOpen('应用余额', '所有应用收款汇总 · D+1 结算后可提现');
?>
    <!-- 余额卡片 -->
    <mdui-card variant="elevated" style="border-radius:16px; padding:24px; background:linear-gradient(135deg,#00696b,#004d40); color:#fff;">
      <div style="font-size:13px; opacity:.85;">可提现余额</div>
      <div style="font-size:40px; font-weight:800; margin:6px 0 2px;">¥ <?= number_format($balance['withdrawable'] / 100, 2) ?></div>
      <div style="font-size:12px; opacity:.75;">不可提现（待结算）¥ <?= number_format($balance['pending'] / 100, 2) ?> · 满 24 小时自动转可提现</div>
    </mdui-card>

    <!-- 提现 -->
    <div class="sec-title" style="margin:22px 0 12px;">提现到通用余额</div>
    <mdui-card variant="elevated" style="border-radius:16px; padding:20px;">
      <div style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
        <mdui-text-field id="wdAmt" label="提现金额（元）" variant="outlined" type="number" min="0.01" step="0.01" placeholder="可提现 ¥<?= number_format($balance['withdrawable'] / 100, 2) ?>" style="width:180px;"></mdui-text-field>
        <mdui-button id="btnWithdraw" variant="filled" icon="south_west--outlined">提现</mdui-button>
      </div>
      <div id="wdMsg" style="margin-top:12px; font-size:13px; opacity:.7;">提现后金额直接到账你的通用余额（我的余额）。</div>
    </mdui-card>

    <!-- 流水 -->
    <div class="sec-title" style="margin:22px 0 12px;">最近流水</div>
    <mdui-card variant="elevated" style="border-radius:16px;">
      <mdui-list>
        <?php if (!$balance['transactions']): ?>
        <mdui-list-item nonclickable>还没有应用余额变动记录</mdui-list-item>
        <?php else: foreach ($balance['transactions'] as $t): ?>
        <?php
            [$label, $color] = $typeLabel[$t['type']] ?? ['未知', 'primary'];
            $sign = $t['amount'] >= 0 ? '+' : '';
        ?>
        <mdui-list-item nonclickable>
          <mdui-icon slot="icon" name="savings--outlined" style="font-size:20px;"></mdui-icon>
          <div><?= $label ?>
            <?php if ($t['remark']): ?><span style="opacity:.6; font-size:12px; margin-left:6px;"><?= htmlspecialchars($t['remark']) ?></span><?php endif; ?>
          </div>
          <span slot="description" style="font-size:12px;">
            <?= htmlspecialchars($t['created_at']) ?>
            <?php if ($t['reference']): ?> · <?= htmlspecialchars($t['reference']) ?><?php endif; ?>
          </span>
          <span slot="end-icon" style="font-weight:700; color:<?= $t['amount'] >= 0 ? '#1b8a5a' : '#c62828' ?>;">
            <?= $sign ?><?= number_format($t['amount'] / 100, 2) ?>
          </span>
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
const MAX_WD = <?= (int)$balance['withdrawable'] ?>;
document.getElementById('btnWithdraw').addEventListener('click', async () => {
  const amt = document.getElementById('wdAmt').value;
  const msg = document.getElementById('wdMsg');
  if (!amt || parseFloat(amt) <= 0) { msg.textContent = '请输入有效金额'; return; }
  if (parseInt(amt * 100) > MAX_WD) { msg.textContent = '超出可提现余额'; return; }
  msg.textContent = '正在提现...';
  try {
    const r = await fetch('/api/app_balance/withdraw', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ amount_yuan: parseFloat(amt) }),
    });
    const d = await r.json();
    if (r.ok) {
      msg.innerHTML = `<span style="color:#1b8a5a;">✅ 提现成功 ¥${d.amount_yuan}，已到账通用余额！</span>`;
      setTimeout(() => location.reload(), 1500);
    } else {
      msg.textContent = '提现失败：' + (d.error || d.message || '未知错误');
    }
  } catch(e) {
    msg.textContent = '网络错误：' + e.message;
  }
});
</script>
