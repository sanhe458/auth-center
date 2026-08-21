# Auth Center 示例 SDK

接入 Auth Center 统一登录的多语言示例代码。

**服务地址**：`https://auth.sanhe.com.mp`

## 快速开始

1. 在[控制台](https://auth.sanhe.com.mp/user/app-create.php)注册应用，拿到 `client_id` 和 `client_secret`
2. 按你的语言选择目录：
   - [PHP](php/)
   - [Python](python/)
   - [Node.js](nodejs/)
   - [Java](java/)
   - [Go](go/)
3. 把示例里的 `client_id` / `client_secret` / `redirect_uri` 换成你自己的

## 核心流程（所有语言一致）

```text
1. 生成授权链接 → 引导用户跳转
2. 用户同意 → 回调地址收到 code
3. 用 code + client_secret 换 access_token
4. 用 access_token 获取用户信息
```

## 各语言 SDK 能力

| 能力 | PHP | Python | Node.js | Java | Go |
|------|:---:|:------:|:-------:|:----:|:--:|
| 生成授权链接 | ✅ | ✅ | ✅ | ✅ | ✅ |
| 授权码换令牌 | ✅ | ✅ | ✅ | ✅ | ✅ |
| 刷新令牌 | ✅ | ✅ | ✅ | ✅ | ✅ |
| 获取用户信息 | ✅ | ✅ | ✅ | ✅ | ✅ |
| 吊销令牌 | ✅ | ✅ | ✅ | ✅ | ✅ |

## 配置说明

所有 SDK 只需配置 4 个参数：

```text
base_url       = https://auth.sanhe.com.mp
client_id      = 你的应用ID
client_secret  = 你的应用密钥
redirect_uri   = 你的回调地址
```

> 注意：`client_secret` 只能存在服务端，绝不能暴露给浏览器/客户端。

## 完整对接文档

见 [Auth Center 文档](https://auth.sanhe.com.mp/docs/)。
