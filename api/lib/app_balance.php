<?php
/**
 * 应用余额系统（D+1）
 * ------------------------------------------------------
 * 账户按用户维度：单个用户所有应用收汇到同一个应用余额账户。
 * 应用余额分两块数值：
 *   withdrawable 可提现
 *   pending      不可提现（待结算）
 *
 * 资金流：
 *   收款(顾客付款) → pending(不可提现)
 *   → 满24h(D+1) cron 结算 → withdrawable(可提现)
 *   → 用户提现 → users.balance(通用余额)
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

/** 取应用余额账户（不存在则创建，返回行） */
function appBalanceGet(int $userId): array
{
    $db = db();
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT * FROM app_balances WHERE user_id = ? FOR UPDATE');
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row) {
            $db->prepare('INSERT INTO app_balances (user_id) VALUES (?)')->execute([$userId]);
            $row = ['user_id' => $userId, 'withdrawable' => 0, 'pending' => 0];
        }
        $db->commit();
        return $row;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** 事务内取应用余额（复用在外部事务中，不自行提交） */
function appBalanceGetInTxn(int $userId): array
{
    $db = db();
    $st = $db->prepare('SELECT * FROM app_balances WHERE user_id = ? FOR UPDATE');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) {
        $db->prepare('INSERT INTO app_balances (user_id) VALUES (?)')->execute([$userId]);
        // 重新读
        $st->execute([$userId]);
        $row = $st->fetch();
    }
    return $row;
}

/** 应用余额账（内部：更新数值+写流水，事务内调用） */
function appBalanceMoveInTxn(
    int $userId,
    string $type,
    int $withdrawableDelta,
    int $pendingDelta,
    string $reference = '',
    string $remark = ''
): array {
    $db = db();
    $acc = appBalanceGetInTxn($userId);
    $w = (int)$acc['withdrawable'] + $withdrawableDelta;
    $p = (int)$acc['pending'] + $pendingDelta;
    if ($w < 0 || $p < 0) throw new RuntimeException('应用余额不足');

    $db->prepare('UPDATE app_balances SET withdrawable = ?, pending = ? WHERE user_id = ?')
        ->execute([$w, $p, $userId]);
    $db->prepare(
        'INSERT INTO app_balance_transactions (user_id, type, amount, withdrawable_after, pending_after, reference, remark) VALUES (?,?,?,?,?,?,?)'
    )->execute([$userId, $type, $withdrawableDelta + $pendingDelta, $w, $p, $reference ?: null, $remark ?: null]);
    return ['withdrawable' => $w, 'pending' => $p];
}

/** 收款入账：加 pending（不可提现），并记待结算明细 —— 事务内版（复用外部事务，不自行提交） */
function appBalanceIncomeInTxn(int $userId, int $amount, string $tradeNo, string $reference = '', string $remark = ''): array
{
    $db = db();
    $res = appBalanceMoveInTxn($userId, 'income', 0, $amount, $reference, $remark);
    $db->prepare('INSERT INTO app_balance_pending (user_id, amount, trade_no, source) VALUES (?,?,?,?)')
        ->execute([$userId, $amount, $tradeNo ?: null, 'pay']);
    return $res;
}

/** 收款入账：加 pending（不可提现），并记待结算明细 */
function appBalanceIncome(int $userId, int $amount, string $tradeNo, string $reference = '', string $remark = ''): array
{
    $db = db();
    $db->beginTransaction();
    try {
        // 加应用余额 · 不可提现
        $res = appBalanceMoveInTxn($userId, 'income', 0, $amount, $reference, $remark);
        // 记待结算明细（D+1 依据）
        $db->prepare('INSERT INTO app_balance_pending (user_id, amount, trade_no, source) VALUES (?,?,?,?)')
            ->execute([$userId, $amount, $tradeNo ?: null, 'pay']);
        $db->commit();
        return $res;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * D+1 结算：把所有满 24 小时的待结算明细滚进可提现
 * @return int 结算的笔数
 */
function appBalanceSettleD1(): int
{
    $db = db();
    $rows = $db->query(
        'SELECT id, user_id, amount FROM app_balance_pending
         WHERE settled = 0 AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    )->fetchAll();

    $done = 0;
    foreach ($rows as $r) {
        $db->beginTransaction();
        try {
            // 防重复：行锁该明细
            $st = $db->prepare('SELECT * FROM app_balance_pending WHERE id = ? FOR UPDATE');
            $st->execute([$r['id']]);
            $row = $st->fetch();
            if (!$row || (int)$row['settled'] === 1) { $db->rollBack(); continue; }

            // pending 减、withdrawable 加
            appBalanceMoveInTxn((int)$r['user_id'], 'settle', (int)$r['amount'], -(int)$r['amount'],
                $row['trade_no'] ?? '', 'D+1 结算');
            $db->prepare('UPDATE app_balance_pending SET settled = 1, settled_at = NOW() WHERE id = ?')
                ->execute([$r['id']]);
            $db->commit();
            $done++;
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[app_balance] 结算失败 pending_id=' . $r['id'] . ' ' . $e->getMessage());
        }
    }
    return $done;
}

/** 提现：可提现 → 通用余额 */
function appBalanceWithdraw(int $userId, int $amount, string $remark = ''): array
{
    if ($amount <= 0) throw new RuntimeException('提现金额不合法');
    $db = db();
    $db->beginTransaction();
    try {
        // 应用余额 · 可提现减少
        $acc = appBalanceGetInTxn($userId);
        if ((int)$acc['withdrawable'] < $amount) throw new RuntimeException('可提现余额不足');
        $res = appBalanceMoveInTxn($userId, 'withdraw', -$amount, 0, '', $remark ?: '提现到通用余额');

        // 通用余额增加
        balanceChangeInTxn($userId, 'recharge', $amount, '', $remark ?: '应用余额提现');

        $db->commit();
        return $res;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** 应用余额账户 + 流水 查询 */
function appBalanceInfo(int $userId, int $limit = 20): array
{
    $db = db();
    $acc = appBalanceGet($userId);
    $limit = min(100, max(1, $limit));
    $st = $db->prepare(
        'SELECT type, amount, withdrawable_after, pending_after, reference, remark, created_at
         FROM app_balance_transactions WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit
    );
    $st->execute([$userId]);
    return [
        'withdrawable'      => (int)$acc['withdrawable'],
        'withdrawable_yuan' => sprintf('%.2f', $acc['withdrawable'] / 100),
        'pending'           => (int)$acc['pending'],
        'pending_yuan'      => sprintf('%.2f', $acc['pending'] / 100),
        'transactions'      => $st->fetchAll(),
    ];
}
