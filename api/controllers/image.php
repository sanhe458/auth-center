<?php
/**
 * 图床 API
 * ------------------------------------------------------
 * 用户端：POST /api/image/upload     登录态上传（multipart 或 base64）
 *         GET  /api/image/list       我的图片列表（登录态）
 *         GET  /api/image/status     永久解锁状态（登录态）
 *
 * 开发者：POST /api/image/upload_app  应用凭证(client_id+client_secret+image权限)上传
 *         上传归属到 应用owner 的图床
 *
 * 过期档位：tier = 1d / 7d / 30d / 90d / 180d / forever（后三者需解锁）
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/image_host.php';
require_once __DIR__ . '/resource.php'; // requireToken（Bearer token 校验）

/** 开发者上传频率限制（每应用每分钟） */
const IMAGE_RATE_MAX = 60;

/**
 * 校验应用凭证 + image 权限，返回应用（开发者 API）
 */
function imageAuthApp(): array
{
    $clientId  = (string)param('client_id', '');
    $clientSec = (string)param('client_secret', '');
    if ($clientId === '' || $clientSec === '') {
        fail(40000, '缺少 client_id 或 client_secret', 401);
    }
    $st = db()->prepare('SELECT * FROM apps WHERE client_id = ? AND status != 3 LIMIT 1');
    $st->execute([$clientId]);
    $app = $st->fetch();
    if (!$app) fail(40003, 'client_id 不存在或应用已吊销', 401);
    if (!hash_equals($app['client_secret_hash'], hashSecret($clientSec))) {
        fail(40004, 'client_secret 错误', 401);
    }
    $st = db()->prepare('SELECT COUNT(*) FROM app_scopes WHERE app_id = ? AND scope = ?');
    $st->execute([$app['id'], 'image']);
    if ((int)$st->fetchColumn() === 0) {
        fail(40000, '该应用未申请 image（图床）权限', 403);
    }
    if (!rateLimit('image:' . $app['id'], IMAGE_RATE_MAX, 60)) {
        fail(429, '上传过于频繁，请稍后再试', 429);
    }
    return $app;
}

/** 从请求提取图片二进制（multipart 文件 或 base64 字段），返回 [bin, name] */
function imageExtractPayload(): array
{
    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $f = $_FILES['image'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            fail(44001, '文件上传失败（错误码 ' . $f['error'] . '）', 400);
        }
        $bin = (string)file_get_contents($f['tmp_name']);
        return [$bin, $f['name'] ?? 'image'];
    }
    $b64 = (string)(param('image') ?? param('image_data') ?? '');
    if ($b64 === '') fail(44002, '缺少图片内容（file 或 base64）', 400);
    if (preg_match('#^data:[a-z0-9/]+;base64,#i', $b64)) {
        $b64 = (string)preg_replace('#^data:[^,]+;base64,#i', '', $b64);
    }
    $bin = base64_decode($b64, true);
    if ($bin === false) fail(44003, 'base64 解析失败', 400);
    $name = (string)param('name', 'image.png');
    return [$bin, $name];
}

/**
 * 核心上传逻辑
 */
function imageDoUpload(int $userId, ?int $appId): void
{
    [$bin, $name] = imageExtractPayload();
    if ($bin === '' || strlen($bin) > 32 * 1024 * 1024) {
        fail(44004, '图片不能为空且不能超过 32MB', 400);
    }

    $tierKey = (string)param('tier', '30d');
    try {
        [$expirySeconds, $tierKey] = imageResolveTier($tierKey, $userId);
    } catch (RuntimeException $e) {
        fail(44005, $e->getMessage(), 400);
    }
    $isPermanent = ($tierKey === 'forever');

    $base64 = base64_encode($bin);
    try {
        $imgbb = imageUploadToImgbb($base64, $name, $expirySeconds);
    } catch (RuntimeException $e) {
        fail(44006, $e->getMessage(), 502);
    }

    $rec = imageRecordSave($userId, $appId, $name, $imgbb, $expirySeconds, $isPermanent, $tierKey);
    ok(['success' => true, 'image' => $rec]);
}

/** POST /api/image/upload 用户登录态上传 */
function imageUpload(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);
    imageDoUpload((int)$userId, null);
}

/** POST /api/image/upload_app 开发者 API 上传（归属应用 owner） */
function imageUploadApp(): void
{
    $app = imageAuthApp();
    imageDoUpload((int)$app['owner_id'], (int)$app['id']);
}

/**
 * POST /api/image/upload_user 开发者替终端用户上传（OAuth Bearer token）
 * 用用户的 access_token，上传到该用户自己的图床，记到用户名下。
 * 需该 token 已授权 image（图床）权限。
 */
function imageUploadUser(): void
{
    $ctx = requireToken();
    $scopes = array_filter(array_map('trim', explode(',', $ctx['scope'])));
    if (!in_array('image', $scopes, true)) {
        fail(44020, '当前授权缺少 image（图床）权限，请重新授权', 403);
    }
    imageDoUpload((int)$ctx['user_id'], (int)$ctx['app_id']);
}

/** GET /api/image/list 我的图片列表 */
function imageList(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    $page = max(1, (int)param('page', 1));
    $size = min(50, max(1, (int)param('size', 20)));
    $off  = ($page - 1) * $size;

    $db = db();
    $st = $db->prepare('SELECT COUNT(*) c FROM images WHERE user_id = ?');
    $st->execute([$userId]);
    $total = (int)$st->fetch()['c'];

    $st = $db->prepare(
        'SELECT id, name, url, page_url, size, mime, expires_at, is_permanent, created_at
         FROM images WHERE user_id = ? ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $off
    );
    $st->execute([$userId]);
    ok(['total' => $total, 'page' => $page, 'size' => $size, 'list' => $st->fetchAll()]);
}

/** GET /api/image/status 永久解锁状态 */
function imageStatus(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);
    ok([
        'permanent' => imageIsPermanent((int)$userId),
        'tiers'     => [
            ['key' => '1d',     'label' => '1 天',  'locked' => false],
            ['key' => '7d',     'label' => '7 天',  'locked' => false],
            ['key' => '30d',    'label' => '30 天', 'locked' => false],
            ['key' => '90d',    'label' => '90 天', 'locked' => true],
            ['key' => '180d',   'label' => '180 天','locked' => true],
            ['key' => 'forever','label' => '永久',  'locked' => true],
        ],
    ]);
}

/** 图床商户 pid（解锁收款方） */
function imageMerchant(): array
{
    static $c = null;
    if ($c === null) {
        $st = db()->prepare("SELECT m.* FROM pay_merchants m JOIN apps a ON a.id = m.app_id WHERE a.name = '图床' AND m.status = 1 LIMIT 1");
        $st->execute();
        $c = $st->fetch() ?: null;
    }
    return $c;
}

/**
 * POST /api/image/unlock_prepare  创建 10 元永久解锁订单，返回支付页 URL
 */
function imageUnlockPrepare(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);
    if (imageIsPermanent((int)$userId)) fail(44007, '您已解锁永久，无需重复购买', 400);

    $merchant = imageMerchant();
    if (!$merchant) fail(44008, '图床收款未配置', 500);

    // 固定 10 元
    $amountFen = 1000;
    $tradeNo = 'IU' . date('YmdHis') . substr(bin2hex(random_bytes(6)), 0, 8);
    $outTradeNo = 'UNLOCK' . $userId;

    // 图床应用 owner = 收款归属
    $db = db();
    try {
        $db->prepare('INSERT INTO pay_orders (pid, out_trade_no, trade_no, type, name, amount_fen, notify_url, return_url, status) VALUES (?,?,?,?,?,?,?,?,0)')
            ->execute([
                $merchant['pid'], $outTradeNo, $tradeNo, 'balance',
                '图床永久解锁', $amountFen,
                APP_BASE . '/api/image/unlock_notify',
                APP_BASE . '/',
            ]);
    } catch (Throwable $e) {
        fail(44009, '创建订单失败', 500);
    }

    ok([
        'order_no'    => $tradeNo,
        'amount_yuan' => '10.00',
        'pay_url'     => APP_BASE . '/pay/index.php?order_no=' . urlencode($tradeNo),
    ]);
}

/**
 * POST /api/image/unlock_confirm  { order_no } 支付完成后确认解锁
 */
function imageUnlockConfirm(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    $tradeNo = trim(param('order_no', ''));
    if (!$tradeNo) fail(44010, '缺少订单号', 400);

    // 校验订单：属于该用户、已支付、且必须是图床解锁订单（防用任意订单白嫖解锁）
    $st = db()->prepare('SELECT * FROM pay_orders WHERE trade_no = ? LIMIT 1');
    $st->execute([$tradeNo]);
    $o = $st->fetch();
    if (!$o) fail(44011, '订单不存在', 404);
    if ((int)$o['status'] !== 1) fail(44012, '订单未支付', 400);
    if ((int)$o['pay_user_id'] !== (int)$userId) fail(44013, '订单不属于当前用户', 403);
    // 必须是图床解锁订单（out_trade_no=UNLOCK+uid、金额 1000 分）
    if ($o['out_trade_no'] !== 'UNLOCK' . $userId || (int)$o['amount_fen'] !== 1000) {
        fail(44014, '订单类型不正确，不能用于解锁', 400);
    }

    $unlocked = imageUnlockPermanent((int)$userId);
    ok(['success' => true, 'permanent' => true, 'first_time' => $unlocked]);
}
