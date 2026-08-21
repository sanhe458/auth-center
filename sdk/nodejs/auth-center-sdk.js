/**
 * Auth Center Node.js SDK（零依赖，原生 fetch）
 * 需要 Node.js 18+（自带 fetch）
 *
 * 用法：
 *   const { AuthCenter } = require('./auth-center-sdk');
 *
 *   const sdk = new AuthCenter({
 *     client_id: '你的client_id',
 *     client_secret: '你的client_secret',
 *     redirect_uri: 'https://yourapp.com/callback',
 *   });
 *
 *   // 1. 生成授权链接
 *   const url = sdk.getAuthorizeUrl('basic', state);
 *
 *   // 2. 回调里换令牌
 *   const tokens = await sdk.exchangeCode(code);
 *
 *   // 3. 获取用户信息
 *   const user = await sdk.getUserInfo(tokens.access_token);
 *
 *   // 4. 刷新令牌
 *   const tokens = await sdk.refreshToken(tokens.refresh_token);
 */

class AuthCenterError extends Error {
  constructor(code, message) {
    super(`Auth Center 错误 [${code}]: ${message}`);
    this.code = code;
  }
}

class AuthCenter {
  constructor({ client_id, client_secret, redirect_uri, base_url = 'https://<AUTH_SERVER>' }) {
    this.baseUrl = base_url.replace(/\/$/, '');
    this.clientId = client_id;
    this.clientSecret = client_secret;
    this.redirectUri = redirect_uri;
  }

  /** 生成授权链接（引导用户跳转） */
  getAuthorizeUrl(scope = 'basic', state = '') {
    const params = new URLSearchParams({
      response_type: 'code',
      client_id: this.clientId,
      redirect_uri: this.redirectUri,
      scope,
      state,
    });
    return `${this.baseUrl}/api/oauth/authorize?${params.toString()}`;
  }

  /** 授权码换令牌 */
  async exchangeCode(code) {
    return this._post('/api/oauth/token', {
      grant_type: 'authorization_code',
      code,
      client_id: this.clientId,
      client_secret: this.clientSecret,
      redirect_uri: this.redirectUri,
    });
  }

  /** 刷新令牌 */
  async refreshToken(refreshToken) {
    return this._post('/api/oauth/token', {
      grant_type: 'refresh_token',
      refresh_token: refreshToken,
      client_id: this.clientId,
      client_secret: this.clientSecret,
    });
  }

  /** 吊销令牌 */
  async revokeToken(token) {
    return this._post('/api/oauth/revoke', { token });
  }

  /** 获取用户信息 */
  async getUserInfo(accessToken) {
    return this._get('/api/info', accessToken);
  }

  async _get(path, token = '') {
    const headers = {};
    if (token) headers.Authorization = `Bearer ${token}`;
    const resp = await fetch(this.baseUrl + path, { headers });
    return this._parse(resp);
  }

  async _post(path, body) {
    const resp = await fetch(this.baseUrl + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    return this._parse(resp);
  }

  async _parse(resp) {
    let data;
    try {
      data = await resp.json();
    } catch {
      throw new Error(`响应解析失败 (HTTP ${resp.status})`);
    }
    // 标准格式：非 2xx 视为失败，取 error/message 报错；成功直接返回顶层数据
    if (!resp.ok) {
      throw new AuthCenterError(data.code ?? resp.status, data.error || data.message || '请求失败');
    }
    return data;
  }
}

module.exports = { AuthCenter, AuthCenterError };

/* ============ 使用示例（Express） ============ */
/*
const express = require('express');
const crypto = require('crypto');
const { AuthCenter } = require('./auth-center-sdk');

const sdk = new AuthCenter({
  client_id: '你的client_id',
  client_secret: '你的client_secret',
  redirect_uri: 'https://yourapp.com/callback',
});

const app = express();

// ① 登录入口
app.get('/login', (req, res) => {
  const state = crypto.randomBytes(16).toString('hex');
  req.session.oauth_state = state; // 存 session
  res.redirect(sdk.getAuthorizeUrl('basic', state));
});

// ② 回调
app.get('/callback', async (req, res) => {
  const { code, state } = req.query;
  if (state !== req.session.oauth_state) {
    return res.status(400).send('state 校验失败');
  }
  try {
    const tokens = await sdk.exchangeCode(code);
    const user = await sdk.getUserInfo(tokens.access_token);
    res.send(`欢迎，${user.nickname}！`);
    // 保存 tokens，过期用 refreshToken 续期
  } catch (e) {
    res.status(500).send('登录失败: ' + e.message);
  }
});

app.listen(3000);
*/
