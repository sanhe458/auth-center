<?php
/**
 * 余额接口：查询余额 / 流水 / 充值占位
 * 充值通道已预留：收到支付渠道回调后，在 balanceRechargeCallback 里接入即可
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

/**
 * 事务增减余额（核心封装）
 * @param int    $userId  用户 id
 * @param string $type    类型: recharge/consume/refund/gift/admin_adjust
 * @param int    $amount  变动金额（分，正入负出，不可为 0）
 * @param string $reference 渠道单号（可选）
 * @param string $remark    备注（可选）
 * @return int 变动后的余额；失败抛异常回滚
 */
function balanceChange(int $userId, string $type, int $amount, string $reference = '', string $remark = ''): int
{
    $db = db();
    $db->beginTransaction();
    try {
        $next = balanceChangeInTxn($userId, $type, $amount, $reference, $remark);
        $db->commit();
        return $next;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 事务内增减余额（不自行提交，供已有事务复用）
 */
function balanceChangeInTxn(int $userId, string $type, int $amount, string $reference = '', string $remark = ''): int
{
    if ($amount === 0) {
        throw new RuntimeException('变动金额不能为 0');
    }
    $db = db();
    // 行锁读取当前余额
    $st = $db->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) throw new RuntimeException('用户不存在');

    $balance = (int)$row['balance'];
    $next = $balance + $amount;
    // 消费类不允许透支
    if ($next < 0) {
        throw new RuntimeException('余额不足');
    }

    $db->prepare('UPDATE users SET balance = ? WHERE id = ?')->execute([$next, $userId]);
    $db->prepare(
        'INSERT INTO balance_transactions (user_id, type, amount, balance_after, reference, remark) VALUES (?,?,?,?,?,?)'
    )->execute([$userId, $type, $amount, $next, $reference ?: null, $remark ?: null]);

    return $next;
}

/**
 * GET /balance/info  当前余额 + 最近流水（登录态）
 */
function balanceInfo(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    $db = db();
    $st = $db->prepare('SELECT balance FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) fail(41008, '用户不存在', 404);

    $limit = min(50, max(1, (int)param('limit', 20)));
    $st = $db->prepare('SELECT type, amount, balance_after, reference, remark, created_at FROM balance_transactions WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit);
    $st->execute([$userId]);
    $txns = $st->fetchAll();

    ok([
        'balance'      => (int)$row['balance'],
        'balance_yuan' => sprintf('%.2f', $row['balance'] / 100),
        'transactions' => $txns,
    ]);
}

/**
 * GET /balance/transactions  全部流水（分页，登录态）
 * ?page=1&size=20
 */
function balanceTransactions(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    $page = max(1, (int)param('page', 1));
    $size = min(100, max(1, (int)param('size', 20)));
    $off  = ($page - 1) * $size;

    $db = db();
    $st = $db->prepare('SELECT COUNT(*) c FROM balance_transactions WHERE user_id = ?');
    $st->execute([$userId]);
    $total = (int)$st->fetch()['c'];

    $st = $db->prepare('SELECT type, amount, balance_after, reference, remark, created_at FROM balance_transactions WHERE user_id = ? ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $off);
    $st->execute([$userId]);

    ok([
        'total' => $total,
        'page'  => $page,
        'size'  => $size,
        'list'  => $st->fetchAll(),
    ]);
}

/**
 * POST /balance/recharge/prepare  发起充值（真实对接易支付 V1）
 * { amount_yuan: float, channel?: string }
 */
function balanceRechargePrepare(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    $amountYuan = (float)param('amount_yuan', 0);
    if ($amountYuan <= 0 || $amountYuan > 100000) fail(42001, '充值金额需大于 0', 400);
    $channel = (string)param('channel', 'alipay');
    // 易支付商户实际开通的通道（三河确认只有这两种）
    $support = ['alipay', 'wxpay'];
    if (!in_array($channel, $support, true)) fail(42002, '不支持的支付方式', 400);

    $amountFen = (int)round($amountYuan * 100);
    $orderNo   = 'AC' . date('YmdHis') . substr(bin2hex(random_bytes(5)), 0, 10);
    $clientIp  = clientIp();

    // 落本地订单（待支付）
    db()->prepare('INSERT INTO recharge_orders (order_no, user_id, amount_fen, status, pay_channel) VALUES (?,?,?,0,?)')
        ->execute([$orderNo, $userId, $amountFen, $channel]);

    // 构造易支付下单参数
    $paramArr = [
        'pid'          => cfg('epay_mch_id'),
        'type'         => $channel,
        'out_trade_no' => $orderNo,
        'notify_url'   => APP_BASE . '/api/balance/recharge/notify',
        'return_url'   => APP_BASE . '/user/wallet.php?paid=1',
        'name'         => 'Auth Center 充值',
        'money'        => sprintf('%.2f', $amountFen / 100),
        'clientip'     => $clientIp,
        'sign_type'    => 'MD5',
    ];
    $paramArr['sign'] = epaySign($paramArr);

    // POST 到 mapi.php
    $ch = curl_init(cfg('epay_api_url') . 'mapi.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => http_build_query($paramArr),
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp  = curl_exec($ch);
    $err   = curl_error($ch);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if (!$data || (int)($data['code'] ?? 0) !== 1) {
        error_log('[epay] 下单失败: ' . ($err ?: $resp));
        fail(42003, '支付下单失败：' . ($data['msg'] ?? '未知错误'), 502);
    }

    // 更新平台单号 + 支付链接
    db()->prepare('UPDATE recharge_orders SET platform_trade_no = ?, pay_url = ? WHERE order_no = ?')
        ->execute([$data['trade_no'] ?? '', $data['payurl'] ?? $data['qrcode'] ?? '', $orderNo]);

    ok([
        'order_no'    => $orderNo,
        'amount_yuan' => sprintf('%.2f', $amountFen / 100),
        'amount_fen'  => $amountFen,
        'channel'     => $channel,
        'pay_url'     => $data['payurl'] ?? '',      // 跳转支付 URL
        'qrcode'      => $data['qrcode'] ?? '',      // 二维码内容
        'trade_no'    => $data['trade_no'] ?? '',
    ]);
}

/**
 * 易支付 MD5 签名（V1 旧版）
 * 参数按 ASCII 排序（a-z），sign/sign_type/空值不参与，拼接 kid=val 后 + KEY 做 md5（小写）
 */
function epaySign(array $params): string
{
    ksort($params); // 按 key ASCII 升序
    $str = '';
    foreach ($params as $k => $v) {
        if ($k === 'sign' || $k === 'sign_type') continue;
        if ($v === '' || $v === null) continue; // 空值不参与
        $str .= $k . '=' . $v . '&';
    }
    $str = rtrim($str, '&');
    return strtolower(md5($str . cfg('epay_key')));
}

/**
 * POST /balance/recharge/notify  支付回调（真实：验签+防重+入账）
 * 易支付以 GET/POST 回调（文档写 GET，稳妥起见 GET+POST 都读）
 * 成功返回 success（纯文本）
 */
function balanceRechargeNotify(): void
{
    // 易支付回调参数（GET 或 POST）
    $params = array_merge($_GET, $_POST);
    if (!$params) {
        echo 'fail';
        exit;
    }

    // 验签
    if (!epayVerify($params)) {
        error_log('[epay] 回调签名校验失败: ' . json_encode($params));
        echo 'fail';
        exit;
    }

    // 仅成功状态入账
    if (($params['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
        echo 'success'; // 非成功通知也返回 success，避免平台重试
        exit;
    }

    $orderNo = $params['out_trade_no'] ?? '';
    $platformTradeNo = $params['trade_no'] ?? '';
    $moneyYuan = (float)($params['money'] ?? 0);
    $amountFen = (int)round($moneyYuan * 100);

    $db = db();
    $db->beginTransaction();
    try {
        // 行锁查订单
        $st = $db->prepare('SELECT * FROM recharge_orders WHERE order_no = ? FOR UPDATE');
        $st->execute([$orderNo]);
        $order = $st->fetch();
        if (!$order) throw new RuntimeException('订单不存在');

        // 已入账则幂等返回 success（防重复通知）
        if ((int)$order['status'] === 1) {
            $db->rollBack();
            echo 'success';
            exit;
        }

        // 金额校验（分，防篡改）
        if ($amountFen !== (int)$order['amount_fen']) {
            throw new RuntimeException('回调金额不匹配');
        }

        // 标记已支付 + 入账
        $db->prepare('UPDATE recharge_orders SET status = 1, platform_trade_no = ?, paid_at = NOW() WHERE id = ?')
            ->execute([$platformTradeNo, $order['id']]);
        $after = balanceChangeInTxn((int)$order['user_id'], 'recharge', $amountFen, $orderNo, '在线充值');

        $db->commit();
        echo 'success';
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('[epay] 回调入账失败: ' . $e->getMessage());
        echo 'fail';
    }
    exit;
}

/**
 * 易支付回调验签
 */
function epayVerify(array $params): bool
{
    $sign = $params['sign'] ?? '';
    if (!$sign) return false;
    return hash_equals(epaySign($params), strtolower($sign));
}

/**
 * GET /balance/recharge/status  查询充值订单状态（前端轮询用）
 * ?order_no=xxx
 * 优先读本地订单表（防刷），返回 status
 */
function balanceRechargeStatus(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    $orderNo = trim(param('order_no', ''));
    if (!$orderNo) fail(42004, '缺少订单号', 400);

    $st = db()->prepare('SELECT order_no, amount_fen, status, pay_channel, platform_trade_no, paid_at, created_at FROM recharge_orders WHERE order_no = ? AND user_id = ? LIMIT 1');
    $st->execute([$orderNo, $userId]);
    $o = $st->fetch();
    if (!$o) fail(42005, '订单不存在', 404);

    $statusMap = [0 => 'pending', 1 => 'paid', 2 => 'closed', 3 => 'refunded'];
    ok([
        'order_no'    => $o['order_no'],
        'amount_yuan' => sprintf('%.2f', $o['amount_fen'] / 100),
        'status'      => $statusMap[(int)$o['status']] ?? 'pending',
        'paid'        => (int)$o['status'] === 1,
        'paid_at'     => $o['paid_at'],
    ]);
}

/**
 * ===== 卡密充值 =====
 */

/**
 * 生成卡密兑换码
 */
function genCardCode(): string
{
    // 16位纯字符（去易混字符），入库存纯16位，展示时前端自行格式化
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $raw = '';
    for ($i = 0; $i < 16; $i++) {
        $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $raw;
}

/**
 * POST /balance/card/redeem  用户兑换卡密
 * { code: "XXXX-XXXX-XXXX-XXXX" }
 */
function balanceCardRedeem(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim(param('code', ''))));
    if (strlen($code) !== 16) {
        fail(42010, '卡密格式不正确', 400);
    }

    $db = db();
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT id, amount_fen, status FROM recharge_cards WHERE code = ? FOR UPDATE');
        $st->execute([$code]);
        $card = $st->fetch();
        if (!$card) throw new RuntimeException('卡密不存在');

        if ((int)$card['status'] === 1) throw new RuntimeException('该卡密已被使用');
        if ((int)$card['status'] === 2) throw new RuntimeException('该卡密已作废');

        // 标记已使用
        $db->prepare('UPDATE recharge_cards SET status = 1, used_by = ?, used_at = NOW() WHERE id = ?')
            ->execute([$userId, $card['id']]);

        // 入账（走 balanceChange，但它在内部开了自己的事务——这里需要兼容）
        $after = balanceChangeInTxn($userId, 'recharge', (int)$card['amount_fen'], $code, '卡密充值');
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fail(42011, $e->getMessage(), 400);
    }

    ok([
        'amount_yuan' => sprintf('%.2f', $card['amount_fen'] / 100),
        'balance_after' => $after,
        'balance_yuan'  => sprintf('%.2f', $after / 100),
    ]);
}

/**
 * POST /admin/cards/generate  管理员批量生成卡密（走 Api 或页面）
 * 这里做成可被 admin 页面和 API 调用的函数
 * { count, amount_yuan, batch? }
 */
function adminCardsGenerate(array $params): array
{
    $count = max(1, min(1000, (int)($params['count'] ?? 0)));
    $amountFen = (int)round((float)($params['amount_yuan'] ?? 0) * 100);

    if ($amountFen <= 0 || $amountFen > 100000000) {
        throw new RuntimeException('面值需大于 0');
    }

    $db = db();
    $adminId = (int)($params['admin_id'] ?? 0);
    $batch = substr(strtoupper(bin2hex(random_bytes(6))), 0, 12);
    $codes = [];
    $st = $db->prepare('INSERT INTO recharge_cards (code, amount_fen, created_by, batch) VALUES (?,?,?,?)');

    // 生成时可能撞唯一码，重试
    $tries = 0;
    while (count($codes) < $count && $tries < $count * 3 + 10) {
        $tries++;
        $code = genCardCode();
        try {
            $st->execute([$code, $amountFen, $adminId, $batch]);
            $codes[] = $code;
        } catch (Throwable $e) {
            // 唯一冲突，重试
        }
    }

    return ['codes' => $codes, 'batch' => $batch, 'amount_yuan' => sprintf('%.2f', $amountFen / 100)];
}
