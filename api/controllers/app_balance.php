<?php
/**
 * 应用余额 API（D+1）
 * ------------------------------------------------------
 * 账户按用户维度，单用户所有应用收汇到同一个应用余额账户。
 * 应用余额 = 可提现(withdrawable) + 不可提现/待结算(pending)
 *
 * 接口：
 *   GET  /api/app_balance/info      查余额+流水（登录态）
 *   POST /api/app_balance/withdraw  提现 可提现 → 通用余额（登录态）
 *   GET  /api/app_balance/settle    手动触发 D+1 结算（admin）
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/app_balance.php';
require_once __DIR__ . '/balance.php'; // balanceChangeInTxn

/** GET /api/app_balance/info */
function appBalanceInfoApi(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);
    ok(appBalanceInfo((int)$userId, (int)param('limit', 20)));
}

/** POST /api/app_balance/withdraw  { amount_yuan } */
function appBalanceWithdrawApi(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    $amountYuan = (float)param('amount_yuan', 0);
    if ($amountYuan <= 0 || $amountYuan > 1000000) fail(43001, '提现金额需大于 0', 400);
    $amountFen = (int)round($amountYuan * 100);

    try {
        $res = appBalanceWithdraw((int)$userId, $amountFen, '提现到通用余额');
    } catch (RuntimeException $e) {
        fail(43002, $e->getMessage(), 400);
    }

    ok([
        'withdrawable'      => $res['withdrawable'],
        'withdrawable_yuan' => sprintf('%.2f', $res['withdrawable'] / 100),
        'pending'           => $res['pending'],
        'pending_yuan'      => sprintf('%.2f', $res['pending'] / 100),
        'amount_yuan'       => sprintf('%.2f', $amountFen / 100),
    ]);
}

/** POST /api/app_balance/settle  D+1 结算（管理员） */
function appBalanceSettleApi(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);
    $st = db()->prepare('SELECT role FROM users WHERE id = ?');
    $st->execute([(int)$userId]);
    $role = $st->fetch()['role'] ?? 'user';
    if ($role !== 'admin') fail(43003, '无权操作', 403);

    $n = appBalanceSettleD1();
    ok(['settled' => $n]);
}
