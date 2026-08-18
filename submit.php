<?php
/**
 * 易支付兼容 页面跳转下单（submit.php）
 * ------------------------------------------------------------------
 * 兼容老式 GET 跳转下单：填好参数访问本页即创建订单并跳转到统一支付页。
 * 与彩虹易支付 V1 submit.php 一致，用于"点击跳转网页收银台"式对接。
 * 参数：pid/type/out_trade_no/notify_url/return_url/name/money/sitename/sign/sign_type
 */
require_once __DIR__ . '/api/lib/db.php';
require_once __DIR__ . '/api/lib/helpers.php';
require_once __DIR__ . '/api/lib/pay.php';

$params = array_merge($_GET, $_POST);
try {
    $data = payOrderCreate($params);
    // 成功 → 302 到统一支付页
    header('Location: ' . ($data['payurl'] ?? '/'));
    exit;
} catch (PayException $e) {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>下单失败</title></head>
<body style="font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f7;">
<div style="background:#fff;padding:32px 40px;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.08);text-align:center;max-width:360px;">
<div style="font-size:42px;margin-bottom:12px;">⚠️</div>
<div style="font-size:18px;font-weight:700;margin-bottom:8px;">下单失败</div>
<div style="font-size:14px;color:#666;">' . htmlspecialchars($e->getMessage()) . '</div>
</div></body></html>';
} catch (Throwable $e) {
    error_log('[pay/submit] ' . $e->getMessage());
    http_response_code(500);
    echo '系统繁忙，请稍后重试';
}
