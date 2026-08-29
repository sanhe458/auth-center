# Auth Center 项目文档

> 统一身份认证系统 · 聚合登录 · 完整项目记忆
> 最后更新：2026-08-16

## 一、项目概述

Auth Center 是一个自建的统一身份认证系统（聚合登录），提供标准 OAuth 2.0 授权码模式的认证能力，让多个应用（Minecraft 模组、机器人、自建服务等）共用一个账号体系。

**核心能力**：
- OAuth 2.0 授权码全流程（authorize / consent / token / refresh / revoke）
- 应用注册、密钥管理、权限范围（scope）控制
- 常规用户控制台 + 开发者控制台 + 管理员后台 三套界面
- 多语言 SDK（PHP/Python/Node.js/Java/Go）+ 在线演示站
- 文档站（VitePress）

## 二、架构总览

```
                 ┌─────────────────────────────────────────┐
                 │              nginx (:80/:443)            │
                 │  auth.sanhe.com.mp    demo.sanhe.com.mp  │
                 └──────┬──────────────┬──────────┬─────────┘
                        │              │          │
              ┌─────────▼──┐   ┌───────▼──────┐   ▼
              │ PHP-FPM    │   │ PHP-FPM      │  静态
              │ :9000      │   │ :9000        │ (docs/sdk)
              │ auth 主站  │   │ demo 演示站   │
              └─────┬──────┘   └──────────────┘
                    │
        ┌───────────┼───────────┐
        ▼           ▼           ▼
    MariaDB     Redis 7      第三方图床
    :3306       :6379        (头像)
```

**技术栈**：
- 后端：PHP 8.2（原生，无框架，PDO + Redis 扩展）
- 数据库：MariaDB 10.11（MySQL 5.7 语法完全兼容，用户拍板）
- 缓存：Redis 7（令牌缓存、滑动窗口限流）
- 前端：MDUI 2（Material Design 3），服务端渲染，深浅色主题（Cookie 存储）
- 文档：VitePress（base=/docs/）
- 图床：第三方图床服务（头像存储）

## 三、域名与部署路径

| 域名 | 用途 | 站点根目录 |
|------|------|-----------|
| auth.sanhe.com.mp | 主站（认证/控制台/后台/文档/SDK） | /var/www/auth.sanhe.com.mp |
| demo.sanhe.com.mp | SDK 演示站（独立会话隔离） | /var/www/demo.sanhe.com.mp |
| api.sanhe.com.mp | 其他 API 服务（既有，不动） | /var/www/api.sanhe.com.mp |
| call.sanhe.com.mp | 既有服务 | — |
| gw.sanhe.com.mp | OpenClaw 网关 | — |

**域名解析**：sanhe.com.mp 泛解析 → 69.165.68.96（独立配置过的不受影响）

**SSL**：每个子域名独立 Let's Encrypt 证书（acme.sh 文件验证，webroot /usr/share/nginx/html），不能用泛域名证书（用户明确要求每站独立签）。

## 四、nginx 配置

配置文件：/etc/nginx/conf.d/auth.sanhe.com.mp.conf、demo.sanhe.com.mp.conf

auth.sanhe.com.mp 关键配置：
- 80 → 301 跳 443
- `location ~ \.php$` → PHP-FPM 127.0.0.1:9000
- `location /api/` → 重写 /api/index.php 路由
- `location /docs/` → VitePress 静态产物
- `location ^~ /sdk/` → **纯文本源码展示**（default_type text/plain，禁用 PHP 执行）+ autoindex 目录列表
- 静态资源缓存 7d

## 五、数据库（auth_center）

账号：auth_center（非 root），密码在 config.php
7 张表：

| 表 | 用途 | 关键字段 |
|----|------|---------|
| users | 用户 | uid(公开), email, password_hash(bcrypt), avatar, role(user/admin), status, balance(余额/分), points(积分) |
| balance_transactions | 余额流水 | user_id, type, amount(正入负出), balance_after, reference, remark |
| points_transactions | 积分流水 | user_id, type, amount(正入负出), points_after, reference, remark |
| checkins | 签到记录 | user_id, checkin_date(唯一), streak(连续天数), points(本次获得) |
| apps | 应用 | client_id, client_secret_hash(HMAC-SHA256+pepper), owner_id, callback_url, status(1开发/2上线/3吊销) |
| app_scopes | 应用权限 | app_id, scope(basic/netdisk/voice/notify) |
| api_keys | 应用密钥 | key_prefix, key_hash, status, 每应用≤5有效 |
| authorizations | 授权关系 | user_id, app_id, scopes, status |
| oauth_codes | 授权码 | code, 一次性, 10分钟过期 |
| oauth_tokens | 令牌 | access/refresh hash, 2h/30d, revoked |

## 六、后端 API（/var/www/auth.sanhe.com.mp/api/）

```
index.php          路由入口（/api/{controller}/{action}）
config.php         配置（DB/Redis/密钥 pepper/图床 key）
lib/
  db.php           PDO 单例
  redis.php        Redis + 滑动窗口限流 rateLimit()
  helpers.php      响应/token/哈希/参数工具
  page.php         页面布局库（pageHead/Nav/Sidebar/登录检查）
controllers/
  oauth.php        authorize/consent/token/refresh/revoke
  user.php         注册/登录/登出/me/头像
  apps.php         应用 CRUD
  keys.php         密钥管理
  resource.php     Bearer 资源接口 + 授权管理
  balance.php      余额查询/流水/充值/卡密（balanceChange 事务封装）
  points.php       积分查询/流水（pointsChange 事务封装）
  checkin.php      每日签到（7 天循环奖励 10/12/14/16/18/20/30）
```

**接口清单**：
- OAuth：/api/oauth/authorize(授权页) /consent /token /revoke
- 用户：/api/user/register /login /logout /me /avatar(POST上传/DELETE移除)
- 应用：/api/apps/list /create /update /delete
- 密钥：/api/keys/list /create /revoke
- 授权：/api/authorizations/list /revoke
- 余额：/api/balance/info /transactions /recharge/prepare /recharge/notify /card/redeem
- 积分：/api/points/info /transactions
- 签到：/api/checkin/do /status
- 资源：/api/info（Bearer 拿用户信息）
- 其他：/api/health

**安全设计**：
- 密码 bcrypt（8-72位校验）；密钥 HMAC-SHA256 + SECRET_PEPPER 加盐
- 授权码一次性、10分钟；回调地址严格等于注册值
- access_token 2h / refresh_token 30d，refresh 轮换（旧 token 吊销）
- Redis 缓存 access_token（TTL 2h），缓存命中校验 tokenStillValid（用户未禁/应用未吊销/授权有效）
- 限流（Redis 滑动窗口）：token 30次/分/IP，login 20次/分/IP，reg 10次/时/IP
- session：HttpOnly + SameSite=Lax + Secure + strict_mode（FPM php.ini）
- CORS 白名单（auth/demo 两个域名）

## 七、页面结构（服务端渲染）

```
/var/www/auth.sanhe.com.mp/
├── index.php          首页（登录态感知）
├── login.php / register.php / oauth.php
├── avatar.php         占位头像生成器（SVG，?n=昵称&s=uid&size=）
├── user/              常规用户控制台
│   ├── index.php      总览（已授权/已撤回/收到授权）
│   ├── auth.php       授权管理
│   ├── wallet.php     我的余额（充值/卡密/流水）
│   ├── points.php     我的积分（积分卡片/流水）
│   └── profile.php    个人设置（昵称/密码/头像上传）
├── developer/         开发者控制台
│   ├── index.php      开发总览（应用/密钥/上线/授权统计）
│   ├── apps.php       应用列表
│   ├── app-create.php 创建应用（client_secret 只显示一次）
│   ├── app-detail.php 应用详情（保存/删除/权限）
│   ├── keys.php       密钥列表（吊销）
│   └── key-create.php 生成密钥（每应用≤5）
├── admin/             管理后台（requireAdminPage）
│   ├── index.php      仪表盘（6 项统计）
│   ├── users.php      用户管理（禁用/设管理员/调余额/调积分）
│   ├── apps.php       应用管理（上线/吊销）
│   ├── auths.php      授权管理（强制撤回）
│   └── tokens.php     令牌管理（筛选/吊销）
├── api/               后端
├── docs/              VitePress 文档站（构建产物）
├── sdk/               多语言 SDK（zip 打包 + 源码浏览）
└── css/ lib/ icons/   前端资源
```

## 八、管理后台

- 权限模型：users.role（admin/user），requireAdminPage() 未登录 302 / 非管理员 403
- 管理员：sanhe458@qq.com（三河真实邮箱）
- 后台操作连带吊销：禁用用户→吊销其令牌；吊销应用→吊销全部令牌；撤回授权→吊销对应令牌
- 不能操作自己的账号（防手滑撤销管理员）

## 九、头像功能

- 图床选型：踩过葫芦侠/新野邮政/京东图床的坑（接口挂或海外 IP 不通）→ 最终选定正规老牌第三方图床
- 上传：浏览器端 Canvas 压缩（超 2MB 自动压，最长边 1280px，质量 0.85→0.45 循环，GIF 不压）→ POST /api/user/avatar → 图床 → 存 users.avatar
- 占位头像：/avatar.php 动态 SVG，按 uid 稳定选 8 组渐变（品牌琥珀橙为主），无头像时全站显示
- 品牌色：琥珀金→珊瑚橙渐变（#ffb74d→#ff7043），深色模式 OLED 纯黑（#000）

## 十、多语言 SDK（/sdk/）+ 安卓客户端（/android/）

5 语言，统一 5 方法：getAuthorizeUrl / exchangeCode / refreshToken / getUserInfo / revokeToken

| 语言 | 文件 | 依赖 |
|------|------|------|
| PHP | auth-center-sdk.php | 无（cURL） |
| Python | auth_center.py | requests |
| Node.js | auth-center-sdk.js | 无（Node18+ fetch） |
| Java | AuthCenter.java | JDK11+（JSON 建议 Jackson） |
| Go | authcenter/authcenter.go | 无 |
| **安卓 App** | **android/（Kotlin+Compose）** | **Gradle 8.7 / AGP 8.5 / SDK 34** |

**ZIP 下载**：/sdk/auth-center-sdk-{lang}.zip + all.zip（全语言总包 20KB）
**完整示例**：每个语言 examples/ 目录含可运行登录+回调 Demo
**注意**：文档站 markdown 里 /sdk/ 链接必须写完整 URL（VitePress base=/docs/ 会加前缀）

**安卓客户端**（2026-08-21 新增，`android/` 完整 Android Studio 工程）：
- 功能：OAuth 授权码登录（WebView 内授权、回调拦截、state 防 CSRF）、用户信息、token 自动刷新、退出吊销、notify 发信
- 包名 `com.sanhe.authcenter`，minSdk 26 / targetSdk 34，构建 `./gradlew :app:assembleDebug`（本机已验证出 APK）
- 回调地址默认 `authcenter://callback`，服务器/client_id/secret 应用内可配
- **内置官方应用**：后端 `api/scripts/seed_official_app.php` 幂等 seed，创建 `authcenter_android` 应用（callback `authcenter://callback`，scopes basic+notify，归管理员）；App 预填此凭据，开箱即用无需注册（生产库已执行，app id=7）
- 注意：App 内置 client_secret 无法保密，自用/内部场景可接受，对外分发需 PKCE 或中转后端

## 十一、演示站（demo.sanhe.com.mp）

- 用 PHP SDK 搭建的第三方应用示例，模拟真实接入
- **独立域名原因**：原来放 auth.sanhe.com.mp/demo/ 同域共用 session，demo 退出会把 Auth Center 会话一起清掉
- 演示应用「SDK演示站」client_id（示例用，部署后自行到控制台注册）
- 体验入口：/docs/guide/demo.html（文档站置顶）

## 十二、文档站（/docs/）

VitePress，源码在 /root/.openclaw/workspace/auth-center/docs-src/
**构建部署**：`npm run build` → 产物复制到 /var/www/auth.sanhe.com.mp/docs/

结构：在线体验 / 简介 / 快速对接(3步) / OAuth流程 / 示例代码 / 示例SDK / 应用与密钥管理 / API参考(总览/认证/用户/控制台/错误码)

**文档原则**（用户要求）：只写接口契约（参数/响应/错误码/限流阈值），不暴露技术栈实现细节（Redis/MariaDB 等内部实现一律不写）

## 十三、安全检查记录（2026-08-16）

发现并修复 5 个 bug：
1. **严重**：撤回授权/禁用/吊销后 Redis 缓存未清，token 仍可用 → tokenStillValid() 缓存命中校验 + authRevoke 清缓存
2. **严重**：CORS 反射任意 Origin + credentials → 白名单
3. **中等**：已吊销应用仍可换 token → oauthToken 加 status != 3
4. **中等**：login.php open redirect（next=//evil.com）→ 安全校验
5. **中等**：页面登录无限流 → login 20次/分、reg 10次/时

加固：session cookie 全开安全属性；清理测试遗留数据

## 十四、账号与凭据（敏感）

- 测试用户：test@sanhe.com.mp（密码已重置）
- 管理员：sanhe458@qq.com（密码用户自持，初始密码文件已失效）
- 数据库：auth_center 账号，密码在 /var/www/auth.sanhe.com.mp/api/config.php
- IMGBB_KEY：config.php
- SECRET_PEPPER：config.php（随机生成）
- Redis 前缀：ac:

## 十五、踩坑记录

1. Mimo/图床类接口从海外 IP 访问国内服务经常不通
2. curl -d 密码含 $ 符号会被 shell 展开 → 排查登录问题用 Python 脚本绕过
3. python 改 PHP 代码时 \$ 转义会写坏 → 用 PHP 脚本或 sed 改更稳
4. VitePress 死链检查会拦 /sdk/ 站内链接（不在构建产物里）→ 写完整 URL
5. 限流测试会把本机 IP 锁住导致登录 302 误判 → 清了 ac:rl:* 恢复
6. heredoc 里不能嵌 PHP 代码（oauth.php 授权页主题）→ 计算放 heredoc 外
7. 页面改 .php 后所有 .html 链接要跟着改（含 oauth.php 里的跳转路径）

## 十六、待办 / 已知限制

- [ ] 页面表单无 CSRF token（SameSite=Lax 缓解，可接受）
- [ ] 授权码/令牌无定期清理任务（表会缓慢增长）
- [ ] 无邮箱验证流程（注册即可用）
- [ ] 无忘记密码流程（登录页按钮是占位的）
- [ ] js/ 目录、shot.js、devserver.py 是静态版残留，可清理
- [ ] auth-center 工作区（/root/.openclaw/workspace/auth-center/）是静态版，以 /var/www/auth.sanhe.com.mp 为准

## 十七、相关服务

- TTS+LLM 网关：69.165.68.96:8770（DeepSeek + MiMo + 硅基流动，Verity-CE 用）
- Verity-CE 音色：voice_verity_20260816（B 站素材克隆）
- OpenClaw：gw.sanhe.com.mp → 18789
- 本机 IP：69.165.68.96
