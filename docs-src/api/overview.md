# 接口总览

所有接口的基础地址：

```
https://auth.sanhe.com.mp/api
```

## 认证方式

第三方应用通过 **OAuth 授权码流程**对接，拿到 **Bearer Token** 后调用资源接口：

| 认证方式 | 适用接口 | 说明 |
|----------|----------|------|
| **Bearer Token** | 资源接口（`/info`） | OAuth 授权后拿到的 access_token，供第三方应用调用 |

## 请求格式

- 请求与响应均为 JSON（`Content-Type: application/json`）
- 字符编码 UTF-8
- 时间格式：`YYYY-MM-DD HH:MM:SS`（服务器时区 Asia/Shanghai）

## 统一响应结构

### 成功

成功直接返回业务数据（HTTP 200）：

```json
{
  "id": "u_admin001",
  "nickname": "三河"
}
```

### 失败

失败返回 `error` 字段 + 非 2xx HTTP 状态码（`code` 为业务错误码，见[错误码](/api/errors)）：

```json
{
  "error": "client_secret 错误",
  "code": 40004
}
```

判断成功与否以 HTTP 状态码为准：`2xx` 成功，其他为失败。

## 接口列表

### OAuth 认证（对接入口）

| 接口 | 方法 | 说明 |
|------|------|------|
| `/api/oauth/authorize` | GET | 发起授权（浏览器跳转） |
| `/api/oauth/consent` | POST | 授权确认页提交 |
| `/api/oauth/token` | POST | 授权码/刷新令牌换 access_token |
| `/api/oauth/revoke` | POST | 吊销令牌 |

### 用户信息服务

| 接口 | 方法 | 说明 |
|------|------|------|
| `/api/info` | GET | 用户信息（Bearer Token） |

### 通知（应用发邮件）

应用给该应用已授权的用户发邮件（需要 `notify` 权限，详见[通知接口](/api/notify)）。

| 接口 | 方法 | 说明 |
|------|------|------|
| `/api/notify/send` | POST | 群发：给所有已授权 `notify` 权限的用户发邮件 |
| `/api/notify/send_to_user` | POST | 定向：给指定用户发邮件（需该用户已授权 `notify`） |

### 其他

| 接口 | 方法 | 说明 |
|------|------|------|
| `/api/health` | GET | 健康检查 |

## 限流

认证类接口有频率限制：

| 接口 | 限制 |
|------|------|
| `/api/oauth/token` | 每 IP 每分钟 30 次 |
| `/api/notify/send` | 每应用每分钟 10 次 |
| `/api/notify/send_to_user` | 每应用每分钟 10 次 |

超出返回 `429`，`code` 为 `40010`。
