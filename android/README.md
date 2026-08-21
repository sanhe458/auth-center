# AuthCenter Android 客户端

Auth Center 统一身份认证的安卓原生客户端（Kotlin + Jetpack Compose）。

## 功能

- 🔐 **OAuth 2.0 授权码登录**：WebView 内完成 Auth Center 登录 + 授权确认，回调自动拦截
- 👤 **用户信息**：展示昵称 / 公开 uid / 邮箱 / 注册时间
- 🔑 **令牌管理**：access_token 2h 自动刷新（refresh_token 轮换），退出时吊销令牌
- 📧 **通知发送**：使用应用 notify 权限给当前用户发邮件（需应用已申请 notify 且用户已授权）
- ⚙️ **可配置**：服务器地址 / client_id / client_secret / 回调地址均可设置

## 构建

要求：JDK 17+，Android SDK 34（`compileSdk = 34`，minSdk 26）。

```bash
# 首次：设置 SDK 路径
echo "sdk.dir=/path/to/android-sdk" > local.properties

# 构建 Debug APK
./gradlew :app:assembleDebug
# 产物：app/build/outputs/apk/debug/app-debug.apk
```

> 本机内存较小的环境可保持 `gradle.properties` 里的低内存配置（-Xmx1024m、in-process Kotlin 编译）。

## 使用

1. 到 Auth Center 开发者控制台注册应用：
   - 回调地址填 `authcenter://callback`（与 App 内默认回调一致，可改）
   - 权限勾选：**basic**（必选）+ **notify**（若要发通知）
2. 打开 App，填入服务器地址（默认 `https://auth.sanhe.com.mp`）、client_id、client_secret、回调地址
3. 点「使用 AuthCenter 登录」→ WebView 里登录授权 → 自动进入主页
4. 主页可查看用户信息、发送通知、刷新/退出

## 目录结构

```
android/
├── app/src/main/java/com/sanhe/authcenter/
│   ├── MainActivity.kt          入口（Compose）
│   ├── data/
│   │   ├── AuthStore.kt         DataStore 持久化（配置/令牌/用户）
│   │   ├── AuthCenterApi.kt     OAuth 2.0 客户端（token/info/revoke/notify）
│   │   ├── AuthRepository.kt    仓储层
│   │   └── model/Models.kt      数据模型
│   ├── ui/
│   │   ├── LoginScreen.kt       配置 + 登录入口
│   │   ├── OAuthWebView.kt      授权 WebView（回调拦截）
│   │   ├── HomeScreen.kt        用户信息 / 通知 / 退出
│   │   └── theme/Theme.kt       品牌主题（琥珀金→珊瑚橙）
│   └── vm/MainViewModel.kt      状态机（NotConfigured/LoggedOut/Authorizing/LoggedIn）
└── gradle/libs.versions.toml    依赖版本
```

## 接口对接（与 Web SDK 同契约）

| 功能 | 端点 | 说明 |
|------|------|------|
| 授权页 | `GET /api/oauth/authorize` | WebView 加载，登录态 Cookie 自动携带 |
| 换令牌 | `POST /api/oauth/token` | grant_type=authorization_code，form 表单 |
| 刷新 | `POST /api/oauth/token` | grant_type=refresh_token，旧 refresh 自动轮换 |
| 用户信息 | `GET /api/info` | Bearer token，返回标准 UserInfo 顶层格式 |
| 吊销 | `POST /api/oauth/revoke` | 退出时吊销 |
| 通知 | `POST /api/notify/send_to_user` | client 凭证 + 目标 uid + title/body |
