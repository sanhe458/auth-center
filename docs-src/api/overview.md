# 接口总览

所有接口的基础地址：

```
https://<AUTH_SERVER>/api
```

## 认证方式

系统有两套认证体系，对应两类接口：

| 认证方式 | 适用接口 | 说明 |
|----------|----------|------|
| **Session Cookie** | 控制台接口（`/apps` `/keys` `/authorizations`） | 用户在浏览器登录后的会话，用于管理自己的应用和密钥 |
| **Bearer Token** | 资源接口（`/info`） | OAuth 授权后拿到的 access_token，供第三方应用调用 |

## 请求格式

- 请求与响应均为 JSON（`Content-Type: application/json`）
- 字符编码 UTF-8
- 时间格式：`YYYY-MM-DD HH:MM:SS`（服务器时区 Asia/Shanghai）

## 统一响应结构

### 成功

```json
{
  "code": 0,
  "message": "ok",
  "data": { }
}
```

### 失败

```json
{
  "code": 40004,
  "message": "client_secret 错误"
}
```

`code` 为 0 表示成功，非 0 表示失败（见[错误码](/api/errors)）。

## 接口列表

### OAuth 认证（第三方应用对接）

| 接口 | 方法 | 说明 |
|------|------|------|
| `/api/oauth/authorize` | GET | 发起授权（浏览器跳转） |
| `/api/oauth/consent` | POST | 授权确认页提交 |
| `/api/oauth/token` | POST | 授权码/刷新令牌换 access_token |
| `/api/oauth/revoke` | POST | 吊销令牌 |

### 用户

| 接口 | 方法 | 说明 |
|------|------|------|
| `/api/user/register` | POST | 注册 |
| `/api/user/login` | POST | 登录 |
| `/api/user/logout` | POST | 登出 |
| `/api/user/me` | GET | 当前登录用户（Session） |
| `/api/info` | GET | 用户信息（Bearer Token） |

### 控制台管理（Session）

| 接口 | 方法 | 说明 |
|------|------|------|
| `/api/apps/list` | GET | 应用列表 |
| `/api/apps/create` | POST | 创建应用 |
| `/api/apps/update` | POST | 更新应用 |
| `/api/apps/delete` | POST | 删除应用 |
| `/api/keys/list` | GET | 密钥列表 |
| `/api/keys/create` | POST | 生成密钥 |
| `/api/keys/revoke` | POST | 吊销密钥 |
| `/api/authorizations/list` | GET | 授权列表 |
| `/api/authorizations/revoke` | POST | 撤回授权 |

### 其他

| 接口 | 方法 | 说明 |
|------|------|------|
| `/api/health` | GET | 健康检查 |

## 限流

认证类接口有频率限制：

| 接口 | 限制 |
|------|------|
| `/api/oauth/token` | 每 IP 每分钟 30 次 |
| `/api/user/login` | 每 IP 每分钟 20 次 |
| `/api/user/register` | 每 IP 每小时 10 次 |

超出返回 `429`，`code` 为 `40010`。
