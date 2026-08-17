# 对接示例代码

完整可跑的对接示例，覆盖常见语言。所有示例假设：

- 你的回调地址：`https://yourapp.com/callback`
- 你的凭据：`client_id` / `client_secret`（从控制台获取）

## PHP（原生）

**1. 引导授权：** 把用户跳去授权页

```php
<?php
$clientId    = '你的client_id';
$redirectUri = 'https://yourapp.com/callback';
$state       = bin2hex(random_bytes(16)); // 防 CSRF

// 把 state 存起来（session / cookie / 数据库）
session_start();
$_SESSION['oauth_state'] = $state;

$params = http_build_query([
    'response_type' => 'code',
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'scope'         => 'basic,voice',
    'state'         => $state,
]);
header('Location: https://auth.sanhe.com.mp/api/oauth/authorize?' . $params);
```

**2. 回调处理：** 用 code 换令牌 + 拿用户信息

```php
<?php
session_start();

// 校验 state（防 CSRF）
if (($_GET['state'] ?? '') !== ($_SESSION['oauth_state'] ?? '')) {
    die('state 校验失败');
}
if (isset($_GET['error'])) {
    die('用户拒绝了授权: ' . htmlspecialchars($_GET['error']));
}

$code        = $_GET['code'];
$clientId    = '你的client_id';
$clientSecret = '你的client_secret';
$redirectUri = 'https://yourapp.com/callback';

// 换令牌
$ch = curl_init('https://auth.sanhe.com.mp/api/oauth/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
    ]),
]);
$resp = json_decode(curl_exec($ch), true);
curl_close($ch);

if (($resp['code'] ?? 1) !== 0) {
    die('换令牌失败: ' . $resp['message']);
}

$accessToken = $resp['data']['access_token'];
$refreshToken = $resp['data']['refresh_token'];

// 拿用户信息
$ch = curl_init('https://auth.sanhe.com.mp/api/info');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer $accessToken"],
]);
$user = json_decode(curl_exec($ch), true)['data'];
curl_close($ch);

echo "欢迎，{$user['nickname']}！";
```

**3. 刷新令牌**

```php
<?php
$ch = curl_init('https://auth.sanhe.com.mp/api/oauth/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
    ]),
]);
$resp = json_decode(curl_exec($ch), true);
$newAccessToken = $resp['data']['access_token']; // 刷新后 access_token 会更新
```

## JavaScript（浏览器端）

浏览器端**只能做跳转**，换令牌必须在你的后端完成（否则 client_secret 会泄露）。

```html
<!-- 登录按钮 -->
<button onclick="location.href='/api/oauth/authorize?response_type=code&client_id=你的client_id&redirect_uri=https://yourapp.com/callback&scope=basic&state=xxxx'">
  使用 Auth Center 登录
</button>
```

回调页拿到 `code` 后 POST 给你的后端，由后端完成换令牌。

## cURL（调试用）

```bash
# 1. 换令牌
curl -X POST https://auth.sanhe.com.mp/api/oauth/token \
  -H "Content-Type: application/json" \
  -d '{
    "grant_type": "authorization_code",
    "code": "回调拿到的code",
    "client_id": "你的client_id",
    "client_secret": "你的client_secret",
    "redirect_uri": "https://yourapp.com/callback"
  }'

# 2. 拿用户信息
curl https://auth.sanhe.com.mp/api/info \
  -H "Authorization: Bearer 上一步的access_token"

# 3. 刷新令牌
curl -X POST https://auth.sanhe.com.mp/api/oauth/token \
  -H "Content-Type: application/json" \
  -d '{
    "grant_type": "refresh_token",
    "refresh_token": "你的refresh_token",
    "client_id": "你的client_id",
    "client_secret": "你的client_secret"
  }'
```

## Python（requests）

```python
import requests

BASE = 'https://auth.sanhe.com.mp'

# 换令牌
resp = requests.post(f'{BASE}/api/oauth/token', json={
    'grant_type': 'authorization_code',
    'code': code,
    'client_id': client_id,
    'client_secret': client_secret,
    'redirect_uri': 'https://yourapp.com/callback',
}).json()

access_token = resp['data']['access_token']

# 拿用户信息
user = requests.get(
    f'{BASE}/api/info',
    headers={'Authorization': f'Bearer {access_token}'},
).json()['data']

print(f"欢迎，{user['nickname']}！")
```

## 常见对接错误

| 现象 | 原因 | 解决 |
|------|------|------|
| 回调带 `error=invalid_request` | redirect_uri 与注册不一致 | 检查注册时的回调地址，**完全一致**（含 http/https、路径、末尾斜杠） |
| `client_secret 错误` | secret 不对 | 控制台重新生成密钥 |
| `授权码无效` | code 用过了或超 10 分钟 | 用最新回调的 code，且只能换一次 |
| `缺少访问令牌` | 没带 Authorization 头 | 格式：`Authorization: Bearer xxx` |
| 浏览器端报跨域 | 在浏览器直接调 token 接口 | 换令牌必须放后端，浏览器只做跳转 |
