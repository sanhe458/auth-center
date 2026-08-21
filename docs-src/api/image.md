# 图床接口

应用通过 Auth Center 图床能力，帮你上传图片到用户的图床，得到直链。

## 前置条件

1. 应用在控制台**申请了 `image`（图床）权限**
2. 目标用户已解锁图床永久（上传 90 天 / 180 天 / 永久 档位时）

上传会**归属到应用 owner 的图床**（即谁拥有这个应用，图就传到谁名下）。

## 鉴权

与通知接口一致，用应用的 `client_id` + `client_secret` 校验身份，并且应用必须拥有 `image` 权限。限频每应用每分钟 60 次。

## POST /api/image/upload_app

用应用身份上传图片。

### 请求参数

两种传图方式任选其一：

| 参数 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `client_id` | 是 | string | 应用 client_id |
| `client_secret` | 是 | string | 应用 client_secret |
| `tier` | 否 | string | 过期档位，默认 `30d` |
| `image` | 是* | file / base64 | 图片文件（multipart）或 base64 字符串 |
| `image_data` | 是* | string | base64 图片数据（`image` 未用时） |
| `name` | 否 | string | 文件名（base64 方式时用） |

\* `image`（文件）和 `image_data`（base64）至少提供一个。

### 过期档位 tier

| tier | 说明 | 权限 |
|------|------|------|
| `1d` | 1 天 | 免费 |
| `7d` | 7 天 | 免费 |
| `30d` | 30 天 | 免费 |
| `90d` | 90 天 | 需永久解锁 |
| `180d` | 180 天 | 需永久解锁 |
| `forever` | 永久 | 需永久解锁 |

> 90 天 / 180 天 / 永久 需用户在页面花 10 元解锁（一次付费，终身有效）。

### 示例（multipart）

```bash
curl -X POST <AUTH_SERVER>/api/image/upload_app \
  -F "client_id=你的client_id" \
  -F "client_secret=你的client_secret" \
  -F "tier=30d" \
  -F "image=@/path/to/pic.jpg;type=image/jpeg"
```

### 示例（base64）

```bash
curl -X POST <AUTH_SERVER>/api/image/upload_app \
  -F "client_id=你的client_id" \
  -F "client_secret=你的client_secret" \
  -F "tier=1d" \
  -F "image_data=<BASE64>" \
  -F "name=pic.png"
```

### 响应

```json
{
  "success": true,
  "image": {
    "id": 2,
    "name": "pic.png",
    "url": "https://i.ibb.co/xxxx/pic.png",
    "page_url": "https://ibb.co/xxxx",
    "delete_url": "https://ibb.co/xxxx/deletehash",
    "size": 12345,
    "mime": "image/png",
    "expires_at": "2026-08-19 11:47:29",
    "is_permanent": false,
    "tier": "30d"
  }
}
```

- `url`：图片直链，直接用于展示
- `expires_at`：过期时间，`null` 表示永久

### 常见错误

| 场景 | 返回 |
|------|------|
| 缺少 client_id/secret | `400 缺少 client_id 或 client_secret` |
| 凭证错误 | `400 client_secret 错误` |
| 应用未申请 `image` 权限 | `403 该应用未申请 image（图床）权限` |
| 未解锁却用 90/180/永久 | `400 该选项需解锁图床永久权限` |
| 图片为空或超 32MB | `400` |
| 上传过于频繁 | `429 上传过于频繁` |
