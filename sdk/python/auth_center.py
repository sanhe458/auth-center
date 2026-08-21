# -*- coding: utf-8 -*-
"""
Auth Center Python SDK（依赖 requests）

安装：pip install requests

用法：
    from auth_center import AuthCenter

    sdk = AuthCenter(
        client_id='你的client_id',
        client_secret='你的client_secret',
        redirect_uri='https://yourapp.com/callback',
    )

    # 1. 生成授权链接
    url = sdk.get_authorize_url('basic', state='随机串')

    # 2. 回调里换令牌
    tokens = sdk.exchange_code(code)

    # 3. 获取用户信息
    user = sdk.get_user_info(tokens['access_token'])

    # 4. 刷新令牌
    tokens = sdk.refresh_token(tokens['refresh_token'])
"""

import requests


class AuthCenterError(Exception):
    """Auth Center API 错误"""


class AuthCenter:
    BASE_URL = 'https://<AUTH_SERVER>'

    def __init__(self, client_id, client_secret, redirect_uri,
                 base_url=None, timeout=15):
        self.base_url = (base_url or self.BASE_URL).rstrip('/')
        self.client_id = client_id
        self.client_secret = client_secret
        self.redirect_uri = redirect_uri
        self.timeout = timeout

    # ---------- 授权 ----------

    def get_authorize_url(self, scope='basic', state=''):
        """生成授权链接（引导用户跳转）"""
        params = {
            'response_type': 'code',
            'client_id': self.client_id,
            'redirect_uri': self.redirect_uri,
            'scope': scope,
            'state': state,
        }
        return f'{self.base_url}/api/oauth/authorize?' + \
            requests.compat.urlencode(params)

    def exchange_code(self, code):
        """授权码换令牌"""
        return self._post('/api/oauth/token', {
            'grant_type': 'authorization_code',
            'code': code,
            'client_id': self.client_id,
            'client_secret': self.client_secret,
            'redirect_uri': self.redirect_uri,
        })

    def refresh_token(self, refresh_token):
        """刷新令牌"""
        return self._post('/api/oauth/token', {
            'grant_type': 'refresh_token',
            'refresh_token': refresh_token,
            'client_id': self.client_id,
            'client_secret': self.client_secret,
        })

    def revoke_token(self, token):
        """吊销令牌"""
        return self._post('/api/oauth/revoke', {'token': token})

    # ---------- 资源 ----------

    def get_user_info(self, access_token):
        """获取用户信息"""
        return self._get('/api/info', access_token)

    # ---------- 内部 ----------

    def _get(self, path, token=''):
        headers = {'Authorization': f'Bearer {token}'} if token else {}
        resp = requests.get(self.base_url + path, headers=headers,
                            timeout=self.timeout)
        return self._parse(resp)

    def _post(self, path, body):
        resp = requests.post(self.base_url + path, json=body,
                             timeout=self.timeout)
        return self._parse(resp)

    @staticmethod
    def _parse(resp):
        try:
            data = resp.json()
        except ValueError:
            raise AuthCenterError(f'响应解析失败 (HTTP {resp.status_code})')
        # 标准格式：非 2xx 视为失败，取 error/message 报错；成功直接返回顶层数据
        if not resp.ok:
            raise AuthCenterError(data.get('error') or data.get('message') or f'请求失败 (HTTP {resp.status_code})')
        return data


if __name__ == '__main__':
    # ============ 使用示例 ============
    import secrets

    sdk = AuthCenter(
        client_id='你的client_id',
        client_secret='你的client_secret',
        redirect_uri='https://yourapp.com/callback',
    )

    # ① 生成授权链接（Flask/FastAPI 里重定向到这里）
    state = secrets.token_urlsafe(16)
    auth_url = sdk.get_authorize_url('basic', state)
    print('授权链接:', auth_url)
    # 把 state 存 session，回调时校验

    # ② 回调处理（收到 code 后）
    code = '回调收到的code'
    try:
        tokens = sdk.exchange_code(code)
        user = sdk.get_user_info(tokens['access_token'])
        print(f"欢迎，{user['nickname']}！")
        # 保存 tokens，过期用 refresh_token 续期
    except AuthCenterError as e:
        print('登录失败:', e)
