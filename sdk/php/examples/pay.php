<?php
/**
 * Auth Center 余额支付接入示例（易支付 V1 协议）
 * ------------------------------------------------------
 * 任何支持易支付的系统都能直接对接：把收款接口地址、商户ID(pid)、MD5密钥(key)
 * 改成 Auth Center 的即可，协议与彩虹易支付 V1 完全兼容，无需修改代码。
 *
 * 本示例演示最简对接：
 *   1. 用 pid+key 调 mapi.php 下单（易支付协议，type 会被忽略）
 *   2. 拿到 trade_no / payurl → 跳到统一支付页
 *   3. 用户用 Auth Center 余额付款
 *   4. 平台异步回调你的 notify_url（见 pay_notify.php）
 */
require_once __DIR__ . '/auth-center-sdk.php';

// ====== 配置（改成你的商户信息） ======
$EPAY_BASE   = 'https://<AUTH_SERVER>';      // Auth Center 站点
$PID         = '你的商户ID';                  // 商户ID(pid)
$KEY         = '你的MD5密钥';                 // 商户MD5密钥（后台可复制）
$NOTIFY_URL  = 'https://yourapp.com/pay_notify.php'; // 异步回调地址
$RETURN_URL  = 'https://yourapp.com/pay_done.php';   // 同步跳转地址
// ======================================

/** 易支付 MD5 签名（与平台一致的算法） */
function epay_sign(array $params, string $key): string {
    ksort($params);
    $str = '';
    foreach ($params as $k => $v) {
        if ($k === 'sign' || $k === 'sign_type') continue;
        if ($v === '' || $v === null) continue;
        $str .= $k . '=' . $v . '&';
    }
    return strtolower(md5(rtrim($str, '&') . $key));
}

$result = null;
$error  = null;

if (($_POST['pay'] ?? '') === '1') {
    $amount = trim($_POST['amount'] ?? '');
    $name   = trim($_POST['name'] ?? '') ?: '商品';
    if (!is_numeric($amount) || (float)$amount <= 0) {
        $error = '请输入有效金额';
    } else {
        $out_trade_no = 'ORD' . date('YmdHis') . mt_rand(1000, 9999);
        $params = [
            'pid'          => $PID,
            'type'         => 'alipay',   // 可随便填，平台会忽略渠道
            'out_trade_no' => $out_trade_no,
            'notify_url'   => $NOTIFY_URL,
            'return_url'   => $RETURN_URL,
            'name'         => $name,
            'money'        => number_format((float)$amount, 2, '.', ''),
            'sitename'     => '我的网站',
            'sign_type'    => 'MD5',
        ];
        $params['sign'] = epay_sign($params, $KEY);

        // 调 mapi.php 下单
        $ch = curl_init($EPAY_BASE . '/mapi.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        $data = json_decode((string)$resp, true);
        if ($err || !$data || (int)($data['code'] ?? 0) !== 200) {
            $error = '下单失败：' . ($data['msg'] ?? $err ?? '未知错误');
        } else {
            $result = $data;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="UTF-8"><title>余额支付示例</title>
<style>
body{font-family:system-ui,sans-serif;background:#0f0f13;color:#e8e4ee;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}
.card{max-width:480px;width:100%;background:#17171d;border:1px solid #26262e;border-radius:20px;padding:28px}
input{width:100%;box-sizing:border-box;background:#202028;border:1px solid #333;color:#e8e4ee;border-radius:8px;padding:10px;font-size:14px}
.btn{display:block;width:100%;background:linear-gradient(135deg,#ffb74d,#ff7043);color:#3a1d00;border:none;padding:11px;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;margin-top:14px;text-align:center;text-decoration:none}
.ok{background:rgba(74,222,128,.1);color:#4ade80;border-radius:8px;padding:10px;font-size:13px;margin-top:14px}
.err{background:rgba(255,80,80,.1);color:#ff6b6b;border-radius:8px;padding:10px;font-size:13px;margin-top:14px}
.mono{font-family:monospace;font-size:12px;color:#ffb74d;word-break:break-all}
</style></head><body>
<div class="card">
  <h2>💳 余额支付示例</h2>
  <?php if ($result): ?>
    <div class="ok">✅ 下单成功，订单号 <?= htmlspecialchars($result['out_trade_no']) ?></div>
    <p style="font-size:13px;line-height:1.8;">金额 <b>¥<?= htmlspecialchars($result['money']) ?></b><br>
      平台单号：<span class="mono"><?= htmlspecialchars($result['trade_no']) ?></span></p>
    <a class="btn" href="<?= htmlspecialchars($result['payurl']) ?>" target="_blank">前往支付页付款 →</a>
  <?php else: ?>
    <?php if ($error): ?><div class="err">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="pay" value="1">
      <p style="font-size:13px;color:#a9a2b3;">商品名称</p>
      <input type="text" name="name" value="示例商品" required>
      <p style="font-size:13px;color:#a9a2b3;">金额（元）</p>
      <input type="number" name="amount" value="1.00" min="0.01" step="0.01" required>
      <button class="btn" type="submit">✅ 余额下单支付</button>
    </form>
  <?php endif; ?>
</div></body></html>
