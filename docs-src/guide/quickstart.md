# 快速对接（3 步）

本文面向**想接入 Auth Center 登录的开发者**。你的应用可以是网站、模组、机器人或任何能发 HTTP 请求的程序。

> 对接环境：`https://<AUTH_SERVER>`

---

## 第 1 步：注册应用

在[控制台](https://<AUTH_SERVER>/user/app-create.php)创建应用，填写：

| 字段 | 必填 | 说明 |
|------|:---:|------|
| 应用名称 | ✅ | 展示给用户的名称 |
| 应用简介 | | 一句话描述 |
| 回调地址 | ✅ | 授权后跳转的 URL，**必须与注册完全一致**（含协议和路径） |
| 应用主页 | | 可选 |

同时勾选需要的权限（scope）。创建成功后你会得到：

- **client_id**：应用公开标识，可出现在前端
- **client_secret**：应用机密，**只能存在你的后端**，绝不能暴露

::: warning
client_secret 只显示一次，关闭页面后无法再次查看。泄露后请在控制台吊销并重新生成。
:::

---

## 第 2 步：发起授权

把用户引导到授权页（浏览器跳转，或模组/机器人打开链接）：

```
GET https://<AUTH_SERVER>/api/oauth/authorize
    ?response_type=code
    &client_id={你的client_id}
    &redirect_uri={你的回调地址}
    &scope=basic,voice
    &state={随机串}
```

| 参数 | 必填 | 说明 |
|------|:---:|------|
| `response_type` | ✅ | 固定 `code` |
| `client_id` | ✅ | 应用标识 |
| `redirect_uri` | ✅ | 回调地址，必须与注册一致 |
| `scope` | | 逗号分隔的权限，默认 `basic` |
| `state` | 推荐 | 防 CSRF 随机串，回调时会原样带回 |

**流程：**
- 用户未登录 → 跳转登录页，登录后自动回到授权页
- 用户看到琥珀色的授权确认页 → 点「同意授权」
- 浏览器跳回你的回调地址：

```
https://yourapp.com/callback?code=xxxxxxxx&state=你的state
```

---

## 第 3 步：换取令牌

在**你的后端**用授权码换 access_token：

```bash
curl -X POST https://<AUTH_SERVER>/api/oauth/token \
  -H "Content-Type: application/json" \
  -d '{
    "grant_type": "authorization_code",
    "code": "上一步拿到的code",
    "client_id": "你的client_id",
    "client_secret": "你的client_secret",
    "redirect_uri": "你的回调地址"
  }'
```

**响应：**

```json
{
  "access_token": "xxx",
  "token_type": "Bearer",
  "expires_in": 7200,
  "refresh_token": "xxx",
  "scope": "basic"
}
```

- `access_token` 有效期 **2 小时**
- `refresh_token` 有效期 **30 天**，过期后需要用户重新授权
- 建议把令牌存在你的服务端，不发给前端

---

## 第 4 步：获取用户信息

```bash
curl https://<AUTH_SERVER>/api/info \
  -H "Authorization: Bearer {access_token}"
```

**响应：**

```json
{
  "id": "u_xxx",
  "nickname": "豁达的金雕",
  "avatar": null,
  "created_at": "2026-08-16 16:21:43",
  "email": "user@example.com"
}
}
```

`email` 仅在应用拥有 `basic` 权限时返回。

---

## 这就对接完了 🎉

完整流程见 [OAuth 授权码流程](/guide/oauth-flow)，可直接抄的代码见 [对接示例代码](/guide/examples)，所有接口见 [API 参考](/api/overview)。
