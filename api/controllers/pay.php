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
        [$order, $afterPayer, $afterMerchant] = payOrderPay((int)$userId, $tradeNo);
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
        'merchant_income'   => sprintf('%.2f', $afterMerchant / 100),
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
