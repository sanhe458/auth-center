# 用户资源接口

用户相关的注册登录与信息获取接口。

## 注册

```
POST /api/user/register
Content-Type: application/json
```

```json
{
  "nickname": "昵称",
  "email": "user@example.com",
  "password": "至少8位密码"
}
```

### 响应

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": "u_xxx",
    "nickname": "昵称",
    "email": "user@example.com"
  }
}
```

注册成功后自动登录（设置 Session Cookie）。

## 登录

```
POST /api/user/login
Content-Type: application/json
```

```json
{
  "email": "user@example.com",
  "password": "密码"
}
```

### 响应

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": "u_xxx",
    "nickname": "昵称",
    "avatar": null,
    "email": "user@example.com"
  }
}
```

登录成功后设置 Session Cookie，后续控制台接口带上 Cookie 即可。

## 登出

```
POST /api/user/logout
```

清除 Session。无参数。

## 当前登录用户（Session）

```
GET /api/user/me
```

需要登录状态（Session Cookie）。

### 响应

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": "u_xxx",
    "nickname": "昵称",
    "avatar": null,
    "email": "user@example.com",
    "created_at": "2026-08-16 16:21:43"
  }
}
```

未登录返回 `41007 未登录`。

## 用户信息（Bearer Token）

第三方应用获取用户信息。**这是对接登录最常用的接口。**

```
GET /api/info
Authorization: Bearer {access_token}
```

### 响应

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": "u_xxx",
    "nickname": "昵称",
    "avatar": null,
    "created_at": "2026-08-16 16:21:43",
    "email": "user@example.com"
  }
}
```

| 字段 | 说明 |
|------|------|
| `id` | 用户唯一 ID（u_ 开头） |
| `nickname` | 昵称 |
| `avatar` | 头像 URL（暂无则 null） |
| `created_at` | 注册时间 |
| `email` | 邮箱（需 basic 权限） |

令牌无效或过期返回 `40005`。

## 错误码

| code | HTTP | 说明 |
|------|------|------|
| 41001 | 400 | 昵称需 2-30 个字符 |
| 41002 | 400 | 邮箱格式不正确 |
| 41003 | 400 | 密码需 8-72 位 |
| 41004 | 409 | 该邮箱已注册 |
| 41005 | 401 | 邮箱或密码错误 |
| 41006 | 403 | 账号已被禁用 |
| 41007 | 401 | 未登录 |
| 41008 | 404 | 用户不存在 |
| 40005 | 401 | 访问令牌无效或已过期 |
| 40010 | 429 | 请求过于频繁 |
