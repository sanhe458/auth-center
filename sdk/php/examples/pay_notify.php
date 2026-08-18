<?php
/**
 * Auth Center 余额支付 · 异步回调示例（pay_notify.php）
 * ------------------------------------------------------
 * 用户在统一支付页用余额付款成功后，平台会按你的 notify_url 异步通知：
 *   收到通知 → 用商户 MD5 密钥验签 → 校验金额/状态 → 处理业务 → 返回 "success"
 *
 * 要点：
 *   - 返回 "success"（纯文本）才表示处理成功，否则平台会重试
 *   - 同样的通知可能重复发送，务必做幂等（按 out_trade_no 判断是否已处理）
 *   - trade_status=TRADE_SUCCESS 才表示支付成功
 */
require_once __DIR__ . '/auth-center-sdk.php';

// ====== 配置（改成你的商户信息，与 pay.php 一致） ======
$KEY = '你的MD5密钥';
// =====================================================

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

$params = array_merge($_GET, $_POST);
$sign   = $params['sign'] ?? '';

// 1. 验签
if (!hash_equals(epay_sign($params, $KEY), strtolower($sign))) {
    http_response_code(400);
    echo 'fail';   // 验签失败返回 fail
    exit;
}

// 2. 提取关键字段
$outTradeNo = $params['out_trade_no'] ?? '';  // 商户订单号
$tradeNo    = $params['trade_no'] ?? '';      // 平台订单号
$money      = $params['money'] ?? '';         // 金额（元，字符串）
$status     = $params['trade_status'] ?? '';  // 支付状态

// 3. 处理业务（幂等！）
if ($status === 'TRADE_SUCCESS') {
    // 先查你的订单是否已处理过（按 out_trade_no）
    // 若未处理：校验 $money 与订单金额一致后，再进行发货/开卡等业务
}

// 4. 返回 success 告知平台停止重试
echo 'success';
exit;
