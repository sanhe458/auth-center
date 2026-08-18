# 认证架构

Auth Center 的认证看似复杂，其实由几套**相互独立、凭证互不通用**的认证组成。本文帮你分清它们各自的用途、凭证和出入，方便对接时对号入座。

> 说明：本文不涉及「用户登录本系统」这套（仅面向人操作后台，开发者对接用不到）。

Auth Center 内有 **3 套**面向开发者的认证：

| | 认证目的 | 凭证 | 适用接口 |
|---|---------|------|---------|
| **A. 应用 API 鉴权** | 应用自己调平台功能 | `client_id` + `client_secret` | 通知、图床(upload_app) |
| **B. OAuth 授权码** | 替用户获取身份 | 全套 OAuth 2.0 | 登录、拿 access_token |
| **C. Bearer 令牌** | 替用户访问资源 | `Authorization: Bearer <access_token>` | 用户信息、图床(upload_user) |

它们**分层递进**：B 签发 C 要用的 token；A 与 B/C 完全独立。

---

## 1. 应用 API 鉴权（A）

应用**自己**调用平台功能，不经过任何终端用户。

```
POST /api/image/upload_app
POST /api/notify/send
  → 传 client_id + client_secret
  → 校验: 应用存在? secret 哈希对上? 有对应权限?
  → 通过 => 干活
```

**凭证**：`client_id`（公开）+ `client_secret`（私密，只存哈希于库）。

**权限**：靠应用申请的 scope（`notify` / `image`），存于 `app_scopes` 表。应用没申请该权限，接口直接拒绝（403）。

**特点**：只认"应用"，不认"用户"。图会传到**应用 owner 自己的账户**。

---

## 2. OAuth 授权码流程（B）

标准 OAuth 2.0 **授权码模式**，专供应用**替用户登录并取得身份**。这是"复杂"的主要来源，因为它要把"用户是谁"从人转移到应用。

```
① 用户在应用点「用 Auth Center 登录」
   → 浏览器 302 到 /api/oauth/authorize?client_id=...&scope=...&redirect_uri=...
② 系统判断用户登录态（这套面向人，不在本文范围）
③ 判断对该应用是否已授权：
     - 已授权且权限完全覆盖当前请求 → 无感直过，直接发 code
     - 有新权限 / 未授权 → 弹授权确认页，用户勾选同意
④ 系统发一次性 code，302 跳回应用 redirect_uri?code=***&state=...
⑤ 应用拿 code + client_secret → POST /api/oauth/token → 换 access_token
⑥ 此后应用用 access_token 代表该用户调受保护接口
```

**授权页与 scope 的关系（安全关键）**：
- 授权确认页会**列出应用申请的所有权限**，用户可勾选/取消，`basic` 必选不可取消。
- 用户同意后，已授权的 scope 存进 `authorizations.scopes`。
- **若开发者给应用新增权限**，下次授权不会静默直过——因为已授权 scope 覆盖不了新权限，系统会**强制重新弹授权页**，用户知情后再同意。

**一句话**：B 是"应用替用户要身份"的入口，权限确认发生在 `authorizations` 表。

---

## 3. Bearer 令牌访问（C）

用 B 拿到的 `access_token`，以**用户身份**访问受保护资源。

```
GET /api/user
POST /api/image/upload_user
  → 请求头 Authorization: Bearer <access_token>
  → requireToken():
      1. hash(token) 查库 / 查 Redis 缓存
      2. 未过期? 用户/应用/授权仍有效?
      3. 返回 { user_id, app_id, scope }
  → 用 user_id 定位是哪个用户, 用 scope 判断能否做这事
```

**凭证**：`access_token`，来自 B 的签发。存储为 SHA-256 哈希，带 Redis 缓存加速。

**scope 作用**：
- `user_id` 决定"操作谁的资源"（如：图传到哪个用户名下）
- `scope` 决定"有没有权限做"（如：调 `upload_user` 必须该 token 已授权 `image`）

**特点**：token 里同时携带"哪个应用 + 替哪个用户 + 什么权限"，所以能替用户做事。

---

## 三者关系图

```
                开发者应用                        Auth Center
                  
   ┌────────────────────┐    A: client_id+secret   ┌────────────────┐
   │ 自己调用功能(通知/    │ ───────────────────────▶ │ 鉴权: 应用+权限   │
   │ 自己图床 upload_app) │                          └────────────────┘
   └────────────────────┘
   
   ┌────────────────────┐    B: OAuth 授权码全套      ┌───────────────┐
   │ 替用户登录/拿 token   │ ───────────────────────▶ │ authorize→code │
   └────────────────────┘                           │      ↓        │
        │                                           │ token 换取      │
        │   C: Bearer token + 所需scope              └───────────────┘
        ▼              ┌──────────────────┐
   GET /api/user ─────▶│ user_id + scope   │
   图床 upload_user    └──────────────────┘
```

- **A**：应用身份，直连快，不需要用户。
- **B**：把"用户身份"签发给应用（产出 token）。
- **C**：用这个 token 以用户身份做事。

---

## 常见疑问

**Q：为什么有 A 还要有 C？**
A 只代表"应用自己"；C 代表"某用户"。替用户动他的资源（图床、数据）必须用 C，否则就是越权。

**Q：scope 只有 basic/notify/image 吗？**
目前是。加新权限在 `api/lib/scopes.php` 的 `scopeDefs()` 里加一行即可，创建/编辑应用的表单、授权页、校验白名单会全部自动生效。

**Q：图床为什么有两个上传接口？**
`upload_app`（A）传到自己名下；`upload_user`（C）用用户 token 传到用户名下。一个开发者自己用，一个替用户用，互不混淆。
