<?php
/**
 * 易支付兼容 API 下单接口（mapi.php）
 * ------------------------------------------------------------------
 * 与彩虹易支付 V1 的 mapi.php 完全兼容：
 *   POST https://<AUTH_SERVER>/mapi.php
 *   pid / type / out_trade_no / notify_url / return_url / name / money / sitename / sign / sign_type
 *
 * 返回 JSON：code=200 成功，附 trade_no / payurl（本站统一支付页）
 * type 一律忽略（付款走我们自研的余额支付页），但字段照常接收以保兼容。
 */
require_once __DIR__ . '/api/lib/db.php';
require_once __DIR__ . '/api/lib/helpers.php';
require_once __DIR__ . '/api/lib/pay.php';

header('Content-Type: application/json; charset=utf-8');

$params = array_merge($_GET, $_POST);
try {
    $data = payOrderCreate($params);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (PayException $e) {
    echo json_encode(['code' => -1, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[pay/mapi] ' . $e->getMessage());
    echo json_encode(['code' => -1, 'msg' => '系统繁忙，请稍后重试'], JSON_UNESCAPED_UNICODE);
}
