<?php
/**
 * Auth Center API 入口
 * 路由规则：/api/{controller}/{action}
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/redis.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/controllers/oauth.php';
require_once __DIR__ . '/controllers/user.php';
require_once __DIR__ . '/controllers/balance.php';
require_once __DIR__ . '/controllers/apps.php';
require_once __DIR__ . '/controllers/keys.php';
require_once __DIR__ . '/controllers/resource.php';
require_once __DIR__ . '/controllers/github.php';
require_once __DIR__ . '/controllers/rainbow.php';
require_once __DIR__ . '/controllers/gitee.php';
require_once __DIR__ . '/controllers/notify.php';
require_once __DIR__ . '/controllers/pay.php';
require_once __DIR__ . '/controllers/app_balance.php';
require_once __DIR__ . '/controllers/image.php';
require_once __DIR__ . '/controllers/captcha.php';

// CORS：仅允许白名单域名（反射任意 Origin + credentials 是漏洞）
$allowedOrigins = ['https://auth.sanhe.com.mp', 'https://demo.sanhe.com.mp'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$seg  = array_values(array_filter(explode('/', $path)));

// 去掉 api 前缀
if (($seg[0] ?? '') === 'api') array_shift($seg);

$controller = $seg[0] ?? '';
$action     = $seg[1] ?? '';
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($controller) {
        // OAuth 流程
        case 'oauth':
            switch ($action) {
                case 'gitee':
                    if (($seg[2] ?? '') === 'callback') { giteeCallback(); } else { giteeLogin(); }
                    break;
                case 'rainbow':
                    if (($seg[2] ?? '') === 'callback') { rainbowCallback(); } else { rainbowLogin(); }
                    break;
                case 'github':
                    // /api/oauth/github 与 /api/oauth/github/callback 区分
                    if (($seg[2] ?? '') === 'callback') { githubCallback(); } else { githubLogin(); }
                    break;
                case 'authorize': oauthAuthorize(); break;
                case 'consent':   oauthConsent(); break;
                case 'token':     oauthToken(); break;
                case 'revoke':    oauthRevoke(); break;
                default: fail(40000, '未知 OAuth 操作', 404);
            }
            break;

        // 用户
        case 'user':
            switch ($action) {
                case 'register': userRegister(); break;
                case 'login':    userLogin(); break;
                case 'logout':   userLogout(); break;
                case 'me':       userMe(); break;
                case 'avatar':
                    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'DELETE') { userAvatarRemove(); } else { userAvatarUpload(); }
                    break;
                default: fail(40000, '未知用户操作', 404);
            }
            break;

        // 余额
        case 'balance':
            switch ($action) {
                case 'info':         balanceInfo(); break;
                case 'transactions': balanceTransactions(); break;
                case 'recharge':
                    if (($seg[2] ?? '') === 'prepare') { balanceRechargePrepare(); }
                    elseif (($seg[2] ?? '') === 'notify') { balanceRechargeNotify(); }
                    elseif (($seg[2] ?? '') === 'status') { balanceRechargeStatus(); }
                    else { fail(40000, '未知充值操作', 404); }
                    break;
                case 'card':
                    if (($seg[2] ?? '') === 'redeem') { balanceCardRedeem(); }
                    else { fail(40000, '未知卡密操作', 404); }
                    break;
                default: fail(40000, '未知余额操作', 404);
            }
            break;

        // 应用管理
        case 'apps':
            switch ($action) {
                case 'list':        appsList(); break;
                case 'create':      appsCreate(); break;
                case 'update':      appsUpdate(); break;
                case 'delete':      appsDelete(); break;
                case 'icon':        appsIcon(); break;
                case 'icon_remove': appsIconRemove(); break;
                default: fail(40000, '未知应用操作', 404);
            }
            break;

        // 密钥管理
        case 'keys':
            switch ($action) {
                case 'list':   keysList(); break;
                case 'create': keysCreate(); break;
                case 'revoke': keysRevoke(); break;
                default: fail(40000, '未知密钥操作', 404);
            }
            break;

        // 通知（应用发邮件给已授权 notify 权限的用户）
        case 'notify':
            switch ($action) {
                case 'send':          notifySend(); break;
                case 'send_to_user':  notifySendToUser(); break;
                default: fail(40000, '未知通知操作', 404);
            }
            break;

        // 易支付收款（统一支付页）
        case 'pay':
            switch ($action) {
                case 'pay':     payPay(); break;
                case 'status':  payStatus(); break;
                default: fail(40000, '未知支付操作', 404);
            }
            break;

        // 应用余额（D+1）
        case 'app_balance':
            switch ($action) {
                case 'info':     appBalanceInfoApi(); break;
                case 'withdraw': appBalanceWithdrawApi(); break;
                case 'settle':   appBalanceSettleApi(); break;
                default: fail(40000, '未知应用余额操作', 404);
            }
            break;

        // 图床
        case 'image':
            switch ($action) {
                case 'upload':          imageUpload(); break;
                case 'upload_app':      imageUploadApp(); break;
                case 'upload_user':     imageUploadUser(); break;
                case 'list':            imageList(); break;
                case 'status':          imageStatus(); break;
                case 'unlock_prepare':  imageUnlockPrepare(); break;
                case 'unlock_confirm':  imageUnlockConfirm(); break;
                default: fail(40000, '未知图床操作', 404);
            }
            break;

        // 行为验证码（滑动拼图）
        case 'captcha':
            switch ($action) {
                case 'get':   captchaGet();  break;
                case 'img':   captchaImg();  break;
                case 'check': captchaCheck(); break;
                default: fail(40000, '未知验证码操作', 404);
            }
            break;

        // 资源接口（Bearer token）
        case 'authorizations':
            switch ($action) {
                case 'list':   authList(); break;
                case 'revoke': authRevoke(); break;
                default: fail(40000, '未知授权操作', 404);
            }
            break;

        // 用户信息（Bearer token 版，/api/user → /api/user/info 兼容）
        case 'info':
            apiUserInfo();
            break;

        case 'health':
            ok(['status' => 'up', 'time' => date('c')]);
            break;

        default:
            fail(40000, '接口不存在: /' . $controller . ($action ? '/' . $action : ''), 404);
    }
} catch (Throwable $e) {
    error_log('[auth-api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail(50000, '服务器内部错误', 500);
}
