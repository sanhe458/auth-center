<?php
/**
 * 积分接口：查询积分 / 流水
 * 积分 = 平台内奖励点数（不可提现），与余额（钱）分离
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

/**
 * 事务增减积分（核心封装）
 * @param int    $userId  用户 id
 * @param string $type    类型: reward/consume/refund/admin_adjust
 * @param int    $amount  变动积分（正入负出，不可为 0）
 * @param string $reference 关联单号/来源（可选）
 * @param string $remark    备注（可选）
 * @return int 变动后的积分；失败抛异常回滚
 */
function pointsChange(int $userId, string $type, int $amount, string $reference = '', string $remark = ''): int
{
    $db = db();
    $db->beginTransaction();
    try {
        $next = pointsChangeInTxn($userId, $type, $amount, $reference, $remark);
        $db->commit();
        return $next;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 事务内增减积分（不自行提交，供已有事务复用）
 */
function pointsChangeInTxn(int $userId, string $type, int $amount, string $reference = '', string $remark = ''): int
{
    if ($amount === 0) {
        throw new RuntimeException('变动积分不能为 0');
    }
    $db = db();
    // 行锁读取当前积分
    $st = $db->prepare('SELECT points FROM users WHERE id = ? FOR UPDATE');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) throw new RuntimeException('用户不存在');

    $points = (int)$row['points'];
    $next = $points + $amount;
    // 消费类不允许透支
    if ($next < 0) {
        throw new RuntimeException('积分不足');
    }

    $db->prepare('UPDATE users SET points = ? WHERE id = ?')->execute([$next, $userId]);
    $db->prepare(
        'INSERT INTO points_transactions (user_id, type, amount, points_after, reference, remark) VALUES (?,?,?,?,?,?)'
    )->execute([$userId, $type, $amount, $next, $reference ?: null, $remark ?: null]);

    return $next;
}

/**
 * GET /points/info  当前积分 + 最近流水（登录态）
 */
function pointsInfo(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    $db = db();
    $st = $db->prepare('SELECT points FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) fail(41008, '用户不存在', 404);

    $limit = min(50, max(1, (int)param('limit', 20)));
    $st = $db->prepare('SELECT type, amount, points_after, reference, remark, created_at FROM points_transactions WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit);
    $st->execute([$userId]);
    $txns = $st->fetchAll();

    ok([
        'points'       => (int)$row['points'],
        'transactions' => $txns,
    ]);
}

/**
 * GET /points/transactions  全部流水（分页，登录态）
 * ?page=1&size=20
 */
function pointsTransactions(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    $page = max(1, (int)param('page', 1));
    $size = min(100, max(1, (int)param('size', 20)));
    $off  = ($page - 1) * $size;

    $db = db();
    $st = $db->prepare('SELECT COUNT(*) c FROM points_transactions WHERE user_id = ?');
    $st->execute([$userId]);
    $total = (int)$st->fetch()['c'];

    $st = $db->prepare('SELECT type, amount, points_after, reference, remark, created_at FROM points_transactions WHERE user_id = ? ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $off);
    $st->execute([$userId]);

    ok([
        'total' => $total,
        'page'  => $page,
        'size'  => $size,
        'list'  => $st->fetchAll(),
    ]);
}
