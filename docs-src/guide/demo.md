# 在线体验

不用搭环境，直接点下面的链接，完整跑一遍 Auth Center 的登录流程。

## 🚀 体验入口

**👉 https://demo.sanhe.com.mp/**

这是一个用 **PHP SDK** 搭建的示例站，模拟了真实第三方应用的接入方式。

## 你能体验什么

```
1. 打开演示站首页 → 点「使用 Auth Center 登录」
2. 跳转到 auth.sanhe.com.mp 的登录页（未登录时）
3. 登录后看到授权确认页（首次授权时显示）
4. 点「同意授权」→ 自动跳回演示站
5. 演示站展示：用户信息 + access_token + 权限范围
```

| 步骤 | 发生什么 |
|------|----------|
| ① 点登录 | 演示站生成 `state` 存 session，跳转授权接口 |
| ② 登录 | Auth Center 登录页，登录后自动回跳 |
| ③ 授权确认 | 展示应用申请的权限（basic / voice），可同意或拒绝 |
| ④ 回调换令牌 | 演示站用 `code` + `client_secret` 换 access_token |
| ⑤ 展示结果 | 昵称、头像、用户 ID、token 有效期、权限 |

## 可以测试的细节

- **拒绝授权**：在授权确认页点「拒绝」，看回调如何处理（演示站会显示"授权失败"）
- **二次登录**：同意一次后，再次登录会**跳过授权页直接成功**（OAuth 标准行为：同一用户对同一应用只确认一次）
- **撤回授权**：在 [Auth Center 控制台 → 授权管理](https://auth.sanhe.com.mp/user/auth.php) 撤回「SDK演示站」的授权，再登录就会重新出现授权页
- **退出登录**：演示站退出只清演示站自己的会话，**不影响你的 Auth Center 账号**

## 体验说明

- 演示站和 Auth Center 是**两个独立域名**（demo.sanhe.com.mp 和 auth.sanhe.com.mp），会话完全隔离
- 演示站用的应用 `client_id`（示例用，部署后自行到控制台注册）,任何人都可以体验
- 体验过程中产生的授权记录可以在控制台随时撤回

## 想看代码？

演示站的完整源码就在 SDK 里：

- PHP 版：[sdk/php/examples/](https://auth.sanhe.com.mp/sdk/php/examples/login.php)
- 其他语言：[示例 SDK](/guide/sdks)

> 想用自己的应用体验？按 [快速对接](/guide/quickstart) 三步注册即可。
