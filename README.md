# Auth Center

基于 OAuth 2.0 的统一认证中心，提供登录、注册、应用与密钥管理、授权流程及完整的文档站。

## 功能

- 统一登录 / 注册 / 授权（OAuth 2.0 流程）
- 应用管理：创建、生命周期（开发中/已上线/已吊销）
- API 密钥管理：按环境区分、独立吊销、轮换
- 完整文档（`docs-src/`，VitePress 构建）

## 目录结构

```
auth-center/
├── index.html        # 入口页
├── login.html        # 登录页
├── register.html     # 注册页
├── oauth.html        # OAuth 授权页
├── user/             # 用户控制台页面
├── css/              # 样式
├── js/               # 脚本
├── lib/              # 前端库（mdui、material-icons 等）
├── icons/            # 图标
└── docs-src/         # 文档站源码（VitePress）
```

## 文档

文档源码在 `docs-src/`，构建输出到 `docs/`：

```bash
cd docs-src
npm install
npm run docs:build
```

## 许可

ISC
