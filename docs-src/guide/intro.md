# 简介

**Auth Center** 是运行在 `auth.sanhe.com.mp` 的统一身份认证系统（聚合登录），提供 OAuth 2.0 授权能力，让多个应用共用一个账号体系。

## 你能用它做什么

- **一键登录**：你的应用接入后，用户用 Auth Center 账号即可登录，不用再注册
- **授权管理**：应用只能获取用户授予的权限（scope）
- **应用注册**：任何开发者都可以注册应用接入
- **密钥管理**：应用密钥生成、吊销、轮换

## 对接方式

Auth Center 使用标准 OAuth 2.0 授权码模式，任何语言都能对接：

- **PHP / Python / Java / Go / Node.js**：直接用 HTTP 请求即可
- **Minecraft 模组 / 机器人**：引导用户打开授权链接，回调拿 code 换令牌
- 不需要任何 SDK，一个 HTTP 客户端就够

## 快速上手

1. 在[控制台](https://auth.sanhe.com.mp/user/app-create.php)注册应用，拿到 `client_id` 和 `client_secret`
2. 跳转授权页 → 用户同意 → 回调拿 `code`
3. 用 `code` 换 `access_token` → 调 `/api/info` 拿用户信息

完整流程见 [快速对接](/guide/quickstart)。

## 对接原则

- 基于标准 **OAuth 2.0 授权码模式**，任何语言、任何平台均可对接
- 接口行为与内部实现无关——你只需要关注本文档描述的请求/响应格式
- 内部实现细节（存储、缓存、部署架构等）不影响对接，无需了解

## 对接环境

| 项目 | 值 |
|------|------|
| API 基础地址 | `https://auth.sanhe.com.mp/api` |
| 授权页 | `https://auth.sanhe.com.mp/api/oauth/authorize` |
| 令牌接口 | `https://auth.sanhe.com.mp/api/oauth/token` |
| 用户信息 | `https://auth.sanhe.com.mp/api/info` |
| 文档 | `https://auth.sanhe.com.mp/docs/` |
