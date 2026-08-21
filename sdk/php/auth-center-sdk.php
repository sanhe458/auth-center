<?php
/**
 * Auth Center PHP SDK（单文件，无依赖）
 *
 * 用法：
 *   $sdk = new AuthCenter([
 *       'client_id'     => '你的client_id',
 *       'client_secret' => '你的client_secret',
 *       'redirect_uri'  => 'https://yourapp.com/callback',
 *   ]);
 *
 *   // 1. 生成授权链接
 *   $url = $sdk->getAuthorizeUrl('basic', $state);
 *
 *   // 2. 回调里换令牌
 *   $tokens = $sdk->exchangeCode($_GET['code']);
 *
 *   // 3. 获取用户信息
 *   $user = $sdk->getUserInfo($tokens['access_token']);
 *
 *   // 4. 刷新令牌
 *   $tokens = $sdk->refreshToken($tokens['refresh_token']);
 */
class AuthCenter
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct(array $config)
    {
        $this->baseUrl      = rtrim($config['base_url'] ?? 'https://<AUTH_SERVER>', '/');
        $this->clientId     = $config['client_id'];
        $this->clientSecret = $config['client_secret'];
        $this->redirectUri  = $config['redirect_uri'];
    }

    /** 生成授权链接（引导用户跳转） */
    public function getAuthorizeUrl(string $scope = 'basic', string $state = ''): string
    {
        $params = [
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'scope'         => $scope,
            'state'         => $state,
        ];
        return $this->baseUrl . '/api/oauth/authorize?' . http_build_query($params);
    }

    /** 授权码换令牌 */
    public function exchangeCode(string $code): array
    {
        return $this->post('/api/oauth/token', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
        ]);
    }

    /** 刷新令牌 */
    public function refreshToken(string $refreshToken): array
    {
        return $this->post('/api/oauth/token', [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
    }

    /** 获取用户信息（Bearer token） */
    public function getUserInfo(string $accessToken): array
    {
        return $this->get('/api/info', $accessToken);
    }

    /** 吊销令牌 */
    public function revokeToken(string $token): array
    {
        return $this->post('/api/oauth/revoke', ['token' => $token]);
    }

    /**
     * 发送通知邮件
     * 给所有已授权 notify 权限的用户发邮件（带频率限制）
     * POST /api/notify/send
     * 需要应用在控制台申请了 notify（通知）权限，否则会被拒。
     */
    public function sendNotify(string $title, string $bodyHtml): array
    {
        return $this->post('/api/notify/send', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'title'         => $title,
            'body'          => $bodyHtml,
        ]);
    }

    /**
     * 发送通知邮件（定向）
     * 只发给指定用户（需该用户已授权 notify 权限）
     * POST /api/notify/send_to_user
     * $userId 可以是数据库 id 或公开 uid（如 u_xxxx）
     */
    public function sendNotifyToUser(string $userId, string $title, string $bodyHtml): array
    {
        return $this->post('/api/notify/send_to_user', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'user_id'       => $userId,
            'title'         => $title,
            'body'          => $bodyHtml,
        ]);
    }

    private function get(string $path, string $token = ''): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = [];
        if ($token) $headers[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
        ]);
        return $this->exec($ch);
    }

    private function post(string $path, array $body): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 15,
        ]);
        return $this->exec($ch);
    }

    private function exec($ch): array
    {
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException('请求失败: ' . $err);
        }
        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            throw new RuntimeException('响应解析失败 (HTTP ' . $http . ')');
        }
        // 标准格式：非 2xx 视为失败，取 error/message 报错；成功直接返回顶层数据
        if ($http < 200 || $http >= 300) {
            throw new RuntimeException((string)($data['error'] ?? $data['message'] ?? ('请求失败 (HTTP ' . $http . ')')));
        }
        return $data;
    }
}

/* ============ 使用示例 ============ */
/*
// 配置
$sdk = new AuthCenter([
    'client_id'     => '你的client_id',
    'client_secret' => '你的client_secret',
    'redirect_uri'  => 'https://yourapp.com/callback',
]);

// ① 登录页：生成授权链接跳转
session_start();
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
header('Location: ' . $sdk->getAuthorizeUrl('basic', $state));
exit;

// ② 回调页 callback.php：换令牌 + 拿用户信息
session_start();
if (($_GET['state'] ?? '') !== ($_SESSION['oauth_state'] ?? '')) {
    die('state 校验失败');
}
try {
    $tokens = $sdk->exchangeCode($_GET['code']);
    $user   = $sdk->getUserInfo($tokens['access_token']);
    echo "欢迎，{$user['nickname']}！";
    // 把 $tokens 存数据库，过期后用 refreshToken() 续期
} catch (Exception $e) {
    die('登录失败: ' . $e->getMessage());
}
*/
