# 通知接口

应用通过 Auth Center 给用户发送邮件。分为**群发**和**定向**两种。

## 前置条件

1. 应用在控制台**申请了 `notify`（通知）权限**
2. 目标用户登录后**授权同意**了该应用的 `notify` 权限

只有满足以上条件，应用的邮件请求才会被接受；未授权 `notify` 权限的用户**不会收到任何邮件**。

## 鉴权

两个接口都通过应用的 `client_id` + `client_secret` 校验身份（同 OAuth 换令牌时的应用凭据），并且**共享同一套频率限制**：

- 每应用每分钟最多 **10** 封
- 超出返回 `429`，`code` 为 `40010`

## 群发：POST /api/notify/send

给**所有已授权该应用 `notify` 权限**的用户发邮件。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `client_id` | string | 是 | 应用 client_id |
| `client_secret` | string | 是 | 应用 client_secret |
| `title` | string | 是 | 邮件主题 |
| `body` | string | 是 | 邮件正文（支持 HTML） |

### 示例

```bash
curl -X POST https://<AUTH_SERVER>/api/notify/send \
  -d "client_id=你的client_id" \
  -d "client_secret=你的client_secret" \
  -d "title=系统维护通知" \
  -d "body=<p>今晚 22:00 系统维护。</p>"
```

### 响应

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "sent": 5,
    "failed": 1,
    "failures": [
      { "email": "user@example.com", "error": "RCPT TO 失败" }
    ]
  }
}
```

- `sent`：成功发送的封数
- `failed`：失败封数
- `failures`：失败明细（收件邮箱 + 原因）

## 定向：POST /api/notify/send_to_user

只给**指定用户**发邮件。目标用户必须是已授权该应用 `notify` 权限、账号正常且绑定了邮箱的用户，否则拒绝（`403`）。

### 请求参数

比群发多一个 `user_id`：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `user_id` | string | 是 | 目标用户 ID，**数据库 id 或公开 uid（`u_xxx`）均可** |

其余参数（`client_id` / `client_secret` / `title` / `body`）同群发。

### 示例

用公开 uid 定向发送：

```bash
curl -X POST https://<AUTH_SERVER>/api/notify/send_to_user \
  -d "client_id=你的client_id" \
  -d "client_secret=你的client_secret" \
  -d "user_id=u_admin001" \
  -d "title=专属优惠" \
  -d "body=<p>给你的一份独家福利。</p>"
```

### 响应

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "sent": 1,
    "email": "user@example.com",
    "user": { "id": 2, "nickname": "三河" }
  }
}
```

## SDK 用法

PHP SDK 内置对应方法（需要你安装 auth-center-sdk）：

```php
// 群发
$sdk->sendNotify('邮件主题', '<p>正文 HTML</p>');

// 定向（uid 或 id 均可）
$sdk->sendNotifyToUser('u_admin001', '邮件主题', '<p>正文 HTML</p>');
```

## 常见错误

| 场景 | 返回 |
|------|------|
| 应用未申请 `notify` 权限 | `403 该应用未申请 notify（通知）权限` |
| 目标用户未授权该应用 `notify` | `403 目标用户未授权该应用的 notify 权限` |
| 目标用户账号不可用 / 未绑邮箱 | `403` / `400` |
| 发送过于频繁 | `429 发送过于频繁，请稍后再试` |
