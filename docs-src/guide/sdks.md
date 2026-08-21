# 示例 SDK

Auth Center 提供多语言示例 SDK，覆盖主流后端语言，**零第三方依赖**（Java 的 JSON 解析除外，生产环境建议换成 Jackson/Gson）。

所有 SDK 都放在：

```
<AUTH_SERVER>/sdk/
```

## 下载（ZIP 打包）

一键下载各语言 SDK（含完整示例代码）：

| 语言 | 下载 |
|------|------|
| 全语言总包 | [auth-center-sdk-all.zip](<AUTH_SERVER>/sdk/auth-center-sdk-all.zip) |
| PHP | [auth-center-sdk-php.zip](<AUTH_SERVER>/sdk/auth-center-sdk-php.zip) |
| Python | [auth-center-sdk-python.zip](<AUTH_SERVER>/sdk/auth-center-sdk-python.zip) |
| Node.js | [auth-center-sdk-nodejs.zip](<AUTH_SERVER>/sdk/auth-center-sdk-nodejs.zip) |
| Java | [auth-center-sdk-java.zip](<AUTH_SERVER>/sdk/auth-center-sdk-java.zip) |
| Go | [auth-center-sdk-go.zip](<AUTH_SERVER>/sdk/auth-center-sdk-go.zip) |

每个 zip 内包含：SDK 源码 + `examples/` 完整可运行的登录示例。

## 在线浏览

也可以直接在线查看源码：<AUTH_SERVER>/sdk/

## 快速选择



| 语言 | 文件 | 依赖 |
|------|------|------|
| PHP | `/sdk/php/auth-center-sdk.php` | 无（cURL） |
| Python | `/sdk/python/auth_center.py` | requests |
| Node.js | `/sdk/nodejs/auth-center-sdk.js` | 无（Node 18+ 原生 fetch） |
| Java | `/sdk/java/AuthCenter.java` | JDK 11+（JSON 解析建议 Jackson） |
| Go | `/sdk/go/authcenter/authcenter.go` | 无 |

## 统一用法（五语言对照）

所有 SDK 都提供 5 个方法：

| 方法 | 说明 |
|------|------|
| `getAuthorizeUrl(scope, state)` | 生成授权链接 |
| `exchangeCode(code)` | 授权码换令牌 |
| `refreshToken(refresh_token)` | 刷新令牌 |
| `getUserInfo(access_token)` | 获取用户信息 |
| `revokeToken(token)` | 吊销令牌 |

### 配置

```text
base_url       = <AUTH_SERVER>
client_id      = 你的应用ID
client_secret  = 你的应用密钥
redirect_uri   = 你的回调地址
```

## PHP 示例

```php
$sdk = new AuthCenter([
    'client_id'     => '你的client_id',
    'client_secret' => '你的client_secret',
    'redirect_uri'  => 'https://yourapp.com/callback',
]);

// 登录入口：跳转授权页
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
header('Location: ' . $sdk->getAuthorizeUrl('basic,voice', $state));

// 回调：换令牌 + 拿用户信息
$tokens = $sdk->exchangeCode($_GET['code']);
$user   = $sdk->getUserInfo($tokens['access_token']);
echo "欢迎，{$user['nickname']}！";
```

## Python 示例

```python
sdk = AuthCenter(
    client_id='你的client_id',
    client_secret='你的client_secret',
    redirect_uri='https://yourapp.com/callback',
)

tokens = sdk.exchange_code(code)
user = sdk.get_user_info(tokens['access_token'])
print(f"欢迎，{user['nickname']}！")
```

## Node.js 示例

```javascript
const sdk = new AuthCenter({
  client_id: '你的client_id',
  client_secret: '你的client_secret',
  redirect_uri: 'https://yourapp.com/callback',
});

const tokens = await sdk.exchangeCode(code);
const user = await sdk.getUserInfo(tokens.access_token);
console.log(`欢迎，${user.nickname}！`);
```

## Java 示例

```java
AuthCenter sdk = new AuthCenter(
    "你的client_id", "你的client_secret", "https://yourapp.com/callback");

var tokens = sdk.exchangeCode(code);
var user = sdk.getUserInfo((String) tokens.get("access_token"));
System.out.println("欢迎，" + user.get("nickname") + "！");
```

## Go 示例

```go
sdk := authcenter.New(authcenter.Config{
    ClientID:     "你的client_id",
    ClientSecret: "你的client_secret",
    RedirectURI:  "https://yourapp.com/callback",
})

tokens, err := sdk.ExchangeCode(code)
user, err := sdk.GetUserInfo(tokens.AccessToken)
fmt.Printf("欢迎，%s！\n", user.Nickname)
```

## 完整回调示例（独立可运行）

每个语言目录下都有 `examples/` 文件夹，包含**可直接运行**的完整登录示例：

| 语言 | 文件 | 运行方式 |
|------|------|----------|
| PHP | `/sdk/php/examples/login.php` + `callback.php` | 放服务器直接访问 |
| Python | `/sdk/python/examples/app.py` | `pip install flask requests && python app.py` |
| Node.js | `/sdk/nodejs/examples/app.js` | `npm install express express-session && node app.js` |
| Java | `/sdk/java/examples/LoginCallbackExample.java` | 两个 Servlet 部署到 Tomcat |
| Go | `/sdk/go/examples/main.go` | `go run main.go`（需 gorilla/sessions） |

**示例包含完整流程：**

```
① 登录入口（/login）
   生成 state → 存 session → 跳转授权页

② 回调处理（/callback）
   校验 state（防 CSRF）→ 处理用户拒绝 → 换令牌 → 拿用户信息 → 创建登录会话

③ 已登录页 + 退出（演示）
```

**回调处理是接入的重点**，所有语言的 callback 都包含这几步：

1. **校验 state**：`$_GET['state']` 必须等于登录时存的 state，不一致直接拒绝（防 CSRF）
2. **处理拒绝**：带 `error` 参数说明用户点了拒绝
3. **换令牌**：`exchangeCode(code)` → access_token + refresh_token
4. **拿用户信息**：`getUserInfo(access_token)` → 昵称/头像/邮箱
5. **创建你的会话**：用 `user['id']` 关联你的用户体系，把 tokens 存数据库

> 每个示例都是完整可跑的 Web 应用（含首页和退出按钮），改 3 个配置项即可运行。

## 安全提醒

- `client_secret` **只能存在服务端**，绝不下发到浏览器/客户端
- 每个 SDK 都内置了错误处理（统一抛出带错误码的异常）
- 令牌过期用 `refreshToken()` 续期，避免频繁重新授权

## 完整示例

每个 SDK 文件末尾都附带了完整的使用示例（含登录入口 + 回调处理 + state 校验），可以直接参考。
