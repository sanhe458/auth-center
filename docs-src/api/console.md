# 控制台管理接口

应用/密钥/授权管理接口，**需要登录状态（Session Cookie）**。用于在控制台管理你自己的应用。

## 应用列表

```
GET /api/apps/list
```

### 响应

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "apps": [
      {
        "client_id": "xxx",
        "name": "应用名",
        "description": "简介",
        "callback": "https://yourapp.com/callback",
        "homepage": "https://yourapp.com",
        "status": 1,
        "status_text": "开发中",
        "scopes": ["basic", "voice"],
        "created_at": "2026-08-16 16:21:50",
        "updated_at": "2026-08-16 16:21:50"
      }
    ]
  }
}
```

| 字段 | 说明 |
|------|------|
| `status` | 1 开发中 / 2 已上线 / 3 已吊销 |
| `scopes` | 应用申请的权限范围 |

## 创建应用

```
POST /api/apps/create
Content-Type: application/json
```

```json
{
  "name": "应用名",
  "description": "简介",
  "callback_url": "https://yourapp.com/callback",
  "homepage": "https://yourapp.com",
  "scopes": ["basic", "voice"]
}
```

### 响应

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "client_id": "xxx",
    "client_secret": "sk-xxx",
    "name": "应用名",
    "scopes": ["basic", "voice"]
  }
}
```

::: danger
`client_secret` 只返回这一次！请立即保存。
:::

## 更新应用

```
POST /api/apps/update
Content-Type: application/json
```

```json
{
  "client_id": "要更新的应用",
  "name": "新名称",
  "description": "新简介",
  "callback_url": "https://yourapp.com/callback",
  "homepage": "https://yourapp.com",
  "scopes": ["basic", "netdisk"]
}
```

字段均可选（传哪个更新哪个），`scopes` 传数组时整体替换。

## 删除应用

```
POST /api/apps/delete
Content-Type: application/json
```

```json
{
  "client_id": "要删除的应用"
}
```

::: danger
不可逆操作！删除后该应用的密钥、授权、令牌全部清除。
:::

## 密钥列表

```
GET /api/keys/list?client_id=可选
```

### 响应

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "keys": [
      {
        "id": 1,
        "app_name": "应用名",
        "client_id": "xxx",
        "key_prefix": "sk-xxx",
        "name": "密钥名",
        "status": 1,
        "last_used_at": null,
        "created_at": "2026-08-16 16:22:00"
      }
    ]
  }
}
```

密钥只返回前缀，完整值只在创建时显示一次。

## 生成密钥

```
POST /api/keys/create
Content-Type: application/json
```

```json
{
  "client_id": "应用标识",
  "name": "密钥名（可选）"
}
```

### 响应

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "key": "sk-xxxx",
    "key_prefix": "sk-xxx"
  }
}
```

每个应用最多 5 个有效密钥（超出返回 `43001`）。

## 吊销密钥

```
POST /api/keys/revoke
Content-Type: application/json
```

```json
{
  "key_id": 1
}
```

## 授权列表

```
GET /api/authorizations/list
```

返回当前用户授权的所有应用。

## 撤回授权

```
POST /api/authorizations/revoke
Content-Type: application/json
```

```json
{
  "authorization_id": 1
}
```

撤回后，该应用给此用户签发的所有令牌立即失效。

## 错误码

| code | HTTP | 说明 |
|------|------|------|
| 41007 | 401 | 未登录 |
| 42001 | 400 | 应用名称需 2-30 个字符 |
| 42002 | 400 | 回调地址格式错误 |
| 42003 | 400 | 应用主页格式错误 |
| 42004 | 404 | 应用不存在或无权操作 |
| 43001 | 400 | 每应用最多 5 个有效密钥 |
| 43002 | 404 | 密钥不存在或无权操作 |
| 44001 | 404 | 授权记录不存在 |
