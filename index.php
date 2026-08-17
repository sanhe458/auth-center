<?php
/**
 * 首页（服务端渲染，登录态感知）
 */
require_once __DIR__ . '/api/lib/db.php';
require_once __DIR__ . '/api/lib/helpers.php';
require_once __DIR__ . '/api/lib/page.php';

$me = currentUser();

pageHead('统一身份认证');
?>
<button class="theme-toggle fixed" onclick="toggleTheme()" title="切换主题"><mdui-icon id="theme-icon" name="dark_mode--outlined"></mdui-icon></button>

<style>
.hero {
  min-height: 92vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 24px;
}
.hero .logo {
  width: 84px; height: 84px; margin: 0 auto 24px;
  border-radius: 24px;
  background: linear-gradient(135deg, #ffb74d, #ff7043);
  display: flex; align-items: center; justify-content: center;
  font-size: 38px; font-weight: 800; color: #3a1d00;
  box-shadow: 0 16px 44px rgba(255,167,38,.4);
}
.hero h1 { margin: 0; font-size: 34px; font-weight: 800; letter-spacing: .5px; }
.hero p { margin: 14px 0 30px; font-size: 15px; opacity: .65; max-width: 480px; line-height: 1.8; }
.hero .cta { display: flex; gap: 14px; flex-wrap: wrap; justify-content: center; }
.features {
  max-width: 720px; margin: 56px auto 0; padding: 0 24px 40px;
  display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;
}
.feat-card {
  background: rgb(var(--mdui-color-surface-container));
  border-radius: 16px; padding: 20px;
  text-align: left;
}
.feat-card .ic { width: 40px; height: 40px; border-radius: 12px; margin-bottom: 12px; display: flex; align-items: center; justify-content: center; }
.feat-card .t { font-size: 15px; font-weight: 700; }
.feat-card .d { font-size: 12.5px; opacity: .6; margin-top: 5px; line-height: 1.7; }
</style>

<div class="hero">
  <div class="logo"><img src="/logo.svg" alt="Auth Center" style="width:100%;height:100%;border-radius:24px;"></div>
  <h1>Auth Center</h1>
  <p>统一身份认证系统，一个账号通行所有应用。<br>OAuth 2.0 授权、应用管理、密钥管理一站式搞定。</p>
  <div class="cta">
    <?php if ($me): ?>
    <mdui-button variant="filled" icon="dashboard--outlined" onclick="location.href='/user/index.php'">进入控制台</mdui-button>
    <?php else: ?>
    <mdui-button variant="filled" icon="login" onclick="location.href='/login.php'">立即登录</mdui-button>
    <mdui-button variant="tonal" icon="person_add" onclick="location.href='/register.php'">注册账号</mdui-button>
    <?php endif; ?>
    <mdui-button variant="tonal" icon="menu_book--outlined" onclick="location.href='/docs/'">查看文档</mdui-button>
  </div>

  <div style="margin-top:14px;">
    <mdui-button variant="text" icon="open_in_new--outlined" onclick="location.href='/docs/api/overview.html'" style="font-size:13px;">开发者 API 文档</mdui-button>
  </div>
</div>

<div class="features">
  <div class="feat-card">
    <div class="ic" style="background:rgba(255,167,38,.15); color:#ffb74d;"><mdui-icon name="verified_user--outlined"></mdui-icon></div>
    <div class="t">OAuth 2.0 认证</div>
    <div class="d">标准授权码流程，scope 权限粒度控制，授权页面可视化确认。</div>
  </div>
  <div class="feat-card">
    <div class="ic" style="background:rgba(255,167,38,.15); color:#ffb74d;"><mdui-icon name="apps--outlined"></mdui-icon></div>
    <div class="t">应用管理</div>
    <div class="d">注册应用、配置回调地址、管理权限范围，随时调整。</div>
  </div>
  <div class="feat-card">
    <div class="ic" style="background:rgba(255,167,38,.15); color:#ffb74d;"><mdui-icon name="key--outlined"></mdui-icon></div>
    <div class="t">密钥管理</div>
    <div class="d">应用密钥生成、吊销、轮换，密钥只显示一次，安全第一。</div>
  </div>
  <div class="feat-card">
    <div class="ic" style="background:rgba(255,167,38,.15); color:#ffb74d;"><mdui-icon name="palette--outlined"></mdui-icon></div>
    <div class="t">多端适配</div>
    <div class="d">桌面侧边栏导航，移动触屏优化，深浅色主题随心切换。</div>
  </div>
</div>
<?php pageFoot(); ?>