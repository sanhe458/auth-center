<?php
/**
 * 易支付收款 API（供统一支付页调用）
 * ------------------------------------------------------
 * POST /api/pay/pay    { order_no }  用当前登录用户余额付款
 * GET  /api/pay/status ?order_no=x   查询订单状态（支付页轮询）
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/pay.php';

/** POST /api/pay/pay 用登录用户余额支付 */
function payPay(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    $tradeNo = trim(param('order_no', ''));
    if (!$tradeNo) fail(42101, '缺少订单号', 400);

    try {
        [$order, $afterPayer, $afterPending] = payOrderPay((int)$userId, $tradeNo);
    } catch (PayException $e) {
        fail(42102, $e->getMessage(), 400);
    }

    // 异步通知商户（尽力而为，不阻塞返回）
    if ((int)$order['status'] === 1) {
        @paySendNotify($tradeNo);
    }

    ok([
        'success'           => true,
        'order_no'          => $tradeNo,
        'paid'              => true,
        'amount_yuan'       => sprintf('%.2f', $order['amount_fen'] / 100),
        'balance_yuan'      => sprintf('%.2f', $afterPayer / 100),
        'pending_yuan'      => sprintf('%.2f', $afterPending / 100),
    ]);
}

/** GET /api/pay/status 查询订单状态 */
function payStatus(): void
{
    $tradeNo = trim(param('order_no', ''));
    if (!$tradeNo) fail(42103, '缺少订单号', 400);

    $st = db()->prepare('SELECT trade_no, amount_fen, status, paid_at FROM pay_orders WHERE trade_no = ? LIMIT 1');
    $st->execute([$tradeNo]);
    $o = $st->fetch();
    if (!$o) fail(42104, '订单不存在', 404);

    ok([
        'order_no'    => $o['trade_no'],
        'amount_yuan' => sprintf('%.2f', $o['amount_fen'] / 100),
        'status'      => [0 => 'pending', 1 => 'paid', 2 => 'closed'][(int)$o['status']] ?? 'pending',
        'paid'        => (int)$o['status'] === 1,
        'paid_at'     => $o['paid_at'],
    ]);
}

/**
 * POST /api/pay/channel 选择支付方式发起支付
 * 参数: order_no + channel（balance | wxpay | alipay）
 *  - balance  用登录用户余额支付（余额不足会报错）
 *  - wxpay/alipay 走上游易支付收款（无需有余额），返回二维码/收银台
 */
function payChannel(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    $tradeNo = trim(param('order_no', ''));
    $channel = trim(param('channel', ''));
    if (!$tradeNo) fail(42101, '缺少订单号', 400);
    if (!in_array($channel, ['balance', 'wxpay', 'alipay'], true)) {
        fail(42105, '不支持的支付方式', 400);
    }

    $st = db()->prepare('SELECT * FROM pay_orders WHERE trade_no = ? LIMIT 1');
    $st->execute([$tradeNo]);
    $order = $st->fetch();
    if (!$order) fail(42104, '订单不存在', 404);
    if ((int)$order['status'] === 1) fail(42106, '订单已支付', 400);
    if ((int)$order['status'] === 2) fail(42107, '订单已关闭', 400);

    if ($channel === 'balance') {
        try {
            [$order, $afterPayer, $afterPending] = payOrderPay((int)$userId, $tradeNo);
        } catch (PayException $e) {
            fail(42102, $e->getMessage(), 400);
        }
        if ((int)$order['status'] === 1) {
            @paySendNotify($tradeNo);
        }
        ok([
            'success'      => true,
            'channel'      => 'balance',
            'paid'         => true,
            'order_no'     => $tradeNo,
            'balance_yuan' => sprintf('%.2f', $afterPayer / 100),
        ]);
    }

    // 微信/支付宝：上游易支付下单
    try {
        $epay = payEpayCreate($order, $channel);
    } catch (PayException $e) {
        fail(42108, $e->getMessage(), 400);
    }
    ok([
        'success'  => true,
        'channel'  => $channel,
        'paid'     => false,
        'order_no' => $tradeNo,
        'qrcode'   => $epay['qrcode'],
        'payurl'   => $epay['payurl'],
    ]);
}

/**
 * POST /api/pay/epay_notify 上游易支付异步回调（公网可达）
 * 易支付协议：验签通过且业务成功 → 输出 success
 */
function payEpayNotify(): void
{
    // 兼容 GET/POST 参数（易支付客户端可能用 GET 回跳，异步通知用 POST）
    $params = array_merge($_GET, $_POST);
    $ok = payEpayHandleNotify($params);
    if (!$ok) {
        http_response_code(400);
        echo 'fail';
        return;
    }
    echo 'success';
}
