<?php
/**
 * 易支付兼容收款核心库
 * ------------------------------------------------------
 * 把 Auth Center 变成"易支付兼容收款服务端"：
 *   第三方系统（任何已支持易支付的程序）填上我们的 商户ID(pid)+MD5密钥(key)，
 *   用易支付 V1 协议下单 → 拿到 payurl(本站统一支付页) → 用余额付款 → 异步回调商户
 *
 * 付款逻辑：付款人(任意登录用户)扣 balance → 收款商户(pid)加 balance，全程余额流转，
 *           不动真钱，所以必须用我们自研的统一支付页。
 * 约定金额单位：分(bigint)
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/app_balance.php'; // 应用余额系统
require_once __DIR__ . '/../controllers/balance.php'; // 复用 balanceChangeInTxn（用户余额增减）

/* ============================================================
 * 1. 签名（易支付 V1：参数按 ASCII 排序，去掉 sign/sign_type/空值，
 *    拼 k=v&... 末尾 + 商户密钥 做小写 MD5）
 * ============================================================ */

/** 生成 MD5 签名 */
function paySign(array $params, string $key): string
{
    ksort($params);
    $str = '';
    foreach ($params as $k => $v) {
        if ($k === 'sign' || $k === 'sign_type') continue;
        if ($v === '' || $v === null) continue;
        $str .= $k . '=' . $v . '&';
    }
    return strtolower(md5(rtrim($str, '&') . $key));
}

/** 校验 MD5 签名 */
function payVerify(array $params, string $key): bool
{
    $sign = $params['sign'] ?? '';
    if (!$sign) return false;
    return hash_equals(paySign($params, $key), strtolower($sign));
}

/* ============================================================
 * 2. 商户
 * ============================================================ */

/** 按 pid 查商户（启用态） */
function payMerchant(string $pid): ?array
{
    $st = db()->prepare('SELECT * FROM pay_merchants WHERE pid = ? AND status = 1 LIMIT 1');
    $st->execute([$pid]);
    $m = $st->fetch();
    return $m ?: null;
}

/** 按 pid 查商户归属用户 id */
function payMerchantOwner(string $pid): ?int
{
    $st = db()->prepare('SELECT owner_id FROM pay_merchants WHERE pid = ? LIMIT 1');
    $st->execute([$pid]);
    $row = $st->fetch();
    $owner = $row ? (int)($row['owner_id'] ?? 0) : 0;
    return $owner ?: null;
}

/** 生成易支付风格商户号（数字，从 10001 起，避免暴露自增 id） */
function genPid(): string
{
    $db = db();
    for ($i = 0; $i < 50; $i++) {
        $pid = (string)(10000 + random_int(1, 899999));
        $st = $db->prepare('SELECT 1 FROM pay_merchants WHERE pid = ? LIMIT 1');
        $st->execute([$pid]);
        if (!$st->fetch()) return $pid;
    }
    throw new RuntimeException('商户号生成失败');
}

/** 生成 MD5 密钥（32 位随机十六进制，易支付风格） */
function genPayKey(): string
{
    return strtolower(bin2hex(random_bytes(16)));
}

/** 商户余额 + 流水（事务内，供已有事务复用） */
function merchantBalanceChangeInTxn(
    string $pid,
    string $type,
    int $amount,
    string $tradeNo = '',
    ?int $payUserId = null,
    string $remark = ''
): int {
    if ($amount === 0) throw new RuntimeException('变动金额不能为 0');
    $db = db();
    $st = $db->prepare('SELECT balance FROM pay_merchants WHERE pid = ? FOR UPDATE');
    $st->execute([$pid]);
    $m = $st->fetch();
    if (!$m) throw new RuntimeException('商户不存在');
    $balance = (int)$m['balance'];
    $next = $balance + $amount;
    if ($next < 0) throw new RuntimeException('商户余额不足');

    $db->prepare('UPDATE pay_merchants SET balance = ? WHERE pid = ?')->execute([$next, $pid]);
    $db->prepare(
        'INSERT INTO pay_merchant_transactions (pid, type, amount, balance_after, trade_no, pay_user_id, remark) VALUES (?,?,?,?,?,?,?)'
    )->execute([$pid, $type, $amount, $next, $tradeNo ?: null, $payUserId, $remark ?: null]);
    return $next;
}

/* ============================================================
 * 3. 下单（易支付兼容入口的公共实现）
 * ============================================================ */

/**
 * 统一下单：校验商户+验签+落 pay_orders，返回易支付风格 JSON 数据
 * @param array $params 已合并的易支付下单参数(GET/POST)
 * @return array 易支付响应数据数组
 */
function payOrderCreate(array $params): array
{
    // 严格按易支付 V1 必填项校验
    $pid        = (string)($params['pid'] ?? '');
    $outTradeNo = (string)($params['out_trade_no'] ?? '');
    $money      = (string)($params['money'] ?? '');
    $notifyUrl  = (string)($params['notify_url'] ?? '');
    $returnUrl  = (string)($params['return_url'] ?? '');
    $name       = (string)($params['name'] ?? '');
    $type       = (string)($params['type'] ?? '');
    $signType   = (string)($params['sign_type'] ?? 'MD5'); // 忽略，按 MD5 处理

    if ($pid === '' || mb_strlen($pid) > 20)                       throw new PayException('商户ID不能为空');
    if ($outTradeNo === '' || mb_strlen($outTradeNo) > 64)         throw new PayException('商户订单号不能为空');
    if (!$notifyUrl || !preg_match('#^https?://#i', $notifyUrl))   throw new PayException('异步通知地址不能为空');
    if (!$returnUrl || !preg_match('#^https?://#i', $returnUrl))   throw new PayException('跳转通知地址不能为空');
    if (!is_numeric($money) || (float)$money <= 0)                 throw new PayException('商品金额不正确');

    $merchant = payMerchant($pid);
    if (!$merchant) throw new PayException('商户不存在或已禁用');

    // 验签（用该商户自己的 key）
    if (!payVerify($params, $merchant['key_plain'])) throw new PayException('签名校验失败');

    $amountFen = (int)round((float)$money * 100);
    if ($amountFen <= 0 || $amountFen > 1000000000) throw new PayException('金额超出范围');

    // 平台单号
    $tradeNo = 'P' . date('YmdHis') . substr(bin2hex(random_bytes(6)), 0, 10);

    try {
        db()->prepare(
            'INSERT INTO pay_orders (pid, out_trade_no, trade_no, type, name, amount_fen, notify_url, return_url, status) VALUES (?,?,?,?,?,?,?,?,0)'
        )->execute([$pid, $outTradeNo, $tradeNo, $type, mb_substr($name, 0, 120), $amountFen, $notifyUrl, $returnUrl]);
    } catch (Throwable $e) {
        // 唯一键冲突：out_trade_no 重复
        if ($e->getCode() === 23000) throw new PayException('商户订单号已存在');
        throw $e;
    }

    // 统一支付页地址（忽略渠道）
    $payUrl = APP_BASE . '/pay/index.php?order_no=' . urlencode($tradeNo);

    return [
        'code'         => 200,
        'msg'          => '下单成功',
        'money'        => sprintf('%.2f', $amountFen / 100),
        'type'         => $type,
        'trade_no'     => $tradeNo,
        'out_trade_no' => $outTradeNo,
        'payurl'       => $payUrl,       // 扩展：直接给跳转地址（易支付客户端通用字段）
        'qrcode'       => $payUrl,       // 兼容：扫码场景给同一支付页（简化，实际由页面展示）
    ];
}

/* ============================================================
 * 4. 余额付款（统一支付页确认支付时调用）
 * ============================================================ */

/**
 * 用付款人余额支付一笔订单
 * @param int    $payerUserId 付款人（登录用户）
 * @param string $tradeNo     平台单号
 * @return array [订单, 付款后余额, 商户收款后余额]
 * @throws PayException
 */
function payOrderPay(int $payerUserId, string $tradeNo): array
{
    $db = db();
    $db->beginTransaction();
    try {
        // 行锁查单
        $st = $db->prepare('SELECT * FROM pay_orders WHERE trade_no = ? FOR UPDATE');
        $st->execute([$tradeNo]);
        $order = $st->fetch();
        if (!$order) throw new PayException('订单不存在');

        if ((int)$order['status'] === 1) {
            // 已支付：幂等返回当前状态（付款人可能重复点）
            $db->rollBack();
            $o = $order;
            $afterPayer = null;
            $afterPending = null;
            // 查付款人的余额
            $st = $db->prepare('SELECT balance FROM users WHERE id = ?');
            $st->execute([$payerUserId]);
            $afterPayer = (int)($st->fetch()['balance'] ?? 0);
            // 查商户归属用户的应用余额·不可提现
            $ownerId = payMerchantOwner((string)$order['pid']);
            if ($ownerId) {
                $acc = appBalanceGet($ownerId);
                $afterPending = (int)($acc['pending'] ?? 0);
            } else {
                $afterPending = 0;
            }
            return [$o, $afterPayer, $afterPending];
        }
        if ((int)$order['status'] === 2) throw new PayException('订单已关闭');

        $amountFen = (int)$order['amount_fen'];
        $pid = $order['pid'];

        // 商户归属用户
        $ownerId = payMerchantOwner($pid);
        if (!$ownerId) throw new PayException('商户未绑定归属用户');

        // 扣付款人余额（消费）
        $afterPayer = balanceChangeInTxn($payerUserId, 'consume', -$amountFen, $order['out_trade_no'] ?: $tradeNo, '余额支付 ' . ($order['name'] ?: '商户收款'));

        // 入账到商户归属用户的应用余额 · 不可提现（D+1）
        $appRes = appBalanceIncomeInTxn($ownerId, $amountFen, $tradeNo, $order['out_trade_no'] ?: $tradeNo, '余额收款 D+1');
        $afterPending = (int)$appRes['pending'];

        // 标记订单已支付
        $db->prepare("UPDATE pay_orders SET status = 1, pay_user_id = ?, paid_at = NOW() WHERE id = ?")
            ->execute([$payerUserId, $order['id']]);

        $db->commit();

        // 刷新订单快照（status 变为 1，供上层判断是否已支付/发通知）
        $st = $db->prepare('SELECT * FROM pay_orders WHERE trade_no = ? LIMIT 1');
        $st->execute([$tradeNo]);
        $order = $st->fetch();

        return [$order, $afterPayer, $afterPending];
    } catch (Throwable $e) {
        $db->rollBack();
        if ($e instanceof PayException) throw $e;
        throw new PayException($e->getMessage());
    }
}

/* ============================================================
 * 5. 异步通知（向商户 notify_url 发易支付标准回调）
 * ============================================================ */

/**
 * 构造并发送易支付标准异步回调到商户
 * @param string $tradeNo 平台单号
 * @return bool 商户是否返回 success
 */
function paySendNotify(string $tradeNo): bool
{
    $st = db()->prepare('SELECT * FROM pay_orders WHERE trade_no = ? LIMIT 1');
    $st->execute([$tradeNo]);
    $order = $st->fetch();
    if (!$order || !$order['notify_url']) return false;

    $merchant = payMerchant($order['pid']);
    if (!$merchant) return false;

    // 易支付异步通知字段
    $params = [
        'pid'          => $order['pid'],
        'trade_no'     => $order['trade_no'],
        'out_trade_no' => $order['out_trade_no'],
        'type'         => $order['type'] ?: 'alipay',
        'name'         => $order['name'] ?: '',
        'money'        => sprintf('%.2f', $order['amount_fen'] / 100),
        'trade_status' => 'TRADE_SUCCESS',
        'sign_type'    => 'MD5',
    ];
    $params['sign'] = paySign($params, $merchant['key_plain']);

    $ch = curl_init($order['notify_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) { error_log('[pay] 回调发送失败: ' . $order['notify_url'] . ' ' . $err); return false; }
    return trim((string)$resp) === 'success';
}

/* ============================================================
 * 6. 运行时异常
 * ============================================================ */

class PayException extends RuntimeException {}
