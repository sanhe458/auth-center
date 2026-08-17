/**
 * Auth Center 登录完整示例（Express + express-session）
 *
 * 安装：npm install express express-session
 * 运行：node app.js
 * 访问：http://127.0.0.1:3000/login 发起登录
 */
const express = require('express');
const session = require('express-session');
const crypto = require('crypto');
const { AuthCenter } = require('../auth-center-sdk');

// ====== 配置（改成你的） ======
const sdk = new AuthCenter({
  client_id: '你的client_id',
  client_secret: '***',
  redirect_uri: 'http://127.0.0.1:3000/callback',
});
const SCOPE = 'basic';
// ==============================

const app = express();
app.use(session({
  secret: crypto.randomBytes(32).toString('hex'), // 生产环境换成固定随机值
  resave: false,
  saveUninitialized: true,
}));

// ① 登录入口
app.get('/login', (req, res) => {
  const state = crypto.randomBytes(16).toString('hex');
  req.session.oauth_state = state; // 存 session，回调时校验
  res.redirect(sdk.getAuthorizeUrl(SCOPE, state));
});

// ② 回调：校验 state → 换令牌 → 拿用户信息 → 创建登录会话
app.get('/callback', async (req, res) => {
  const { code, state, error, error_description } = req.query;

  // 校验 state（防 CSRF）
  if (state !== req.session.oauth_state) {
    return res.status(400).send('state 校验失败，请重新发起登录');
  }

  // 用户拒绝授权
  if (error) {
    return res.status(400).send(`授权失败：${error_description || error}`);
  }

  if (!code) {
    return res.status(400).send('缺少授权码');
  }

  try {
    // 换令牌
    const tokens = await sdk.exchangeCode(code);
    // 拿用户信息
    const user = await sdk.getUserInfo(tokens.access_token);

    // ===== 在这里创建你自己的登录会话 =====
    // 用 user.id 关联你的用户体系，tokens 存数据库
    req.session.logged_in = true;
    req.session.user = user;
    req.session.tokens = tokens;

    res.send(`欢迎，${user.nickname}！<a href="/">进入首页</a>`);
  } catch (e) {
    res.status(500).send(`登录失败：${e.message}`);
  }
});

// ③ 已登录页面（演示用）
app.get('/', (req, res) => {
  if (!req.session.user) {
    return res.send('<a href="/login">使用 Auth Center 登录</a>');
  }
  res.send(`你好，${req.session.user.nickname}！<a href="/logout">退出</a>`);
});

app.get('/logout', (req, res) => {
  req.session.destroy();
  res.redirect('/');
});

app.listen(3000, () => {
  console.log('示例已启动: http://127.0.0.1:3000/login');
});
