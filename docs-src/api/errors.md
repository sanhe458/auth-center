# 错误码

所有接口返回 `code` 字段，`0` 表示成功，非 0 表示失败。

## 通用错误

| code | HTTP | 说明 |
|------|------|------|
| 0 | 200 | 成功 |
| 40000 | 400 | 参数错误 / 不支持的 grant_type / 接口不存在 |
| 40010 | 429 | 请求过于频繁（限流） |
| 50000 | 500 | 服务器内部错误 |

## OAuth 认证错误

| code | HTTP | 说明 |
|------|------|------|
| 40001 | 400 | 授权码无效、已过期或已使用（授权码一次性，10 分钟有效） |
| 40002 | 400 | 刷新令牌无效或已过期 |
| 40003 | 401 | client_id 不存在 |
| 40004 | 401 | client_secret 错误 |
| 40005 | 401 | 访问令牌无效或已过期 |

## 授权页错误（回调 URL 参数形式）

授权失败时通过回调地址携带错误：

```
https://yourapp.com/callback
    ?error=access_denied
    &error_description=用户拒绝了授权
    &state=xxx
```

| error | 说明 |
|-------|------|
| `invalid_request` | 请求缺少必要参数或 redirect_uri 不匹配 |
| `unauthorized_client` | 应用不存在或未上线 |
| `invalid_scope` | 请求了应用未申请的权限 |
| `access_denied` | 用户拒绝授权 |
| `unsupported_response_type` | response_type 不支持 |
| `server_error` | 服务器内部错误 |

## 排查建议

1. 先确认请求参数是否完整（尤其是 `state`）
2. 检查 client_id / client_secret 是否对应且未吊销
3. 确认回调地址与注册时**完全一致**（含协议、路径、末尾斜杠）
4. 授权码只能使用一次，且 10 分钟内有效——换令牌失败先重新走授权
5. 遇到 429 说明触发限流，稍等 1 分钟再试
6. 500 错误查看服务端日志定位
