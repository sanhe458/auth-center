# 认证接口

OAuth 2.0 认证相关接口，供第三方应用对接。

## 发起授权

浏览器跳转，获取授权码。

```
GET /api/oauth/authorize
```

### 参数

| 参数 | 必填 | 说明 |
|------|:---:|------|
| `response_type` | ✅ | 固定 `code` |
| `client_id` | ✅ | 应用标识 |
| `redirect_uri` | ✅ | 回调地址（必须与注册一致） |
| `scope` | | 逗号分隔权限，默认 `basic` |
| `state` | 推荐 | 防 CSRF 随机串 |

### 响应

- 未登录：302 跳转 `/login.php?next=原请求`，登录后回跳
- 已登录已授权：302 跳回调地址携带 `code` 和 `state`
- 已登录未授权：渲染授权确认页 HTML

### 成功回调

```
https://yourapp.com/callback?code=XXXX&state=YYYY
```

### 错误回调

```
https://yourapp.com/callback?error=access_denied&error_description=...&state=YYYY
```

## 授权确认

授权确认页表单提交（一般由用户点击触发，应用无需手动调用）。

```
POST /api/oauth/consent
Content-Type: application/x-www-form-urlencoded
```

| 参数 | 必填 | 说明 |
|------|:---:|------|
| `token` | ✅ | 授权会话令牌（来自授权页隐藏字段） |
| `decision` | ✅ | `allow` 同意 / `deny` 拒绝 |

## 换取令牌

授权码或刷新令牌换取 access_token。

```
POST /api/oauth/token
Content-Type: application/json
```

### 授权码模式

```json
{
  "grant_type": "authorization_code",
  "code": "授权码",
  "client_id": "应用标识",
  "client_secret": "应用密钥",
  "redirect_uri": "回调地址"
}
```

### 刷新模式

```json
{
  "grant_type": "refresh_token",
  "refresh_token": "刷新令牌",
  "client_id": "应用标识",
  "client_secret": "应用密钥"
}
```

### 响应

```json
{
  "access_token": "xxx",
  "token_type": "Bearer",
  "expires_in": 7200,
  "refresh_token": "xxx",
  "scope": "basic"
}
```

| 字段 | 说明 |
|------|------|
| `access_token` | 访问令牌，2 小时有效 |
| `token_type` | 固定 `Bearer` |
| `expires_in` | 有效期秒数 |
| `refresh_token` | 刷新令牌，30 天有效 |
| `scope` | 实际授予的权限 |

## 吊销令牌

```
POST /api/oauth/revoke
Content-Type: application/json
```

```json
{
  "token": "要吊销的 access_token 或 refresh_token"
}
```

吊销后该令牌立即失效。

## 错误码

| code | HTTP | 说明 |
|------|------|------|
| 40000 | 400 | 参数错误 / 不支持的 grant_type |
| 40001 | 400 | 授权码无效、已过期或已使用 |
| 40002 | 400 | 刷新令牌无效或已过期 |
| 40003 | 401 | client_id 不存在 |
| 40004 | 401 | client_secret 错误 |
| 40010 | 429 | 请求过于频繁 |
