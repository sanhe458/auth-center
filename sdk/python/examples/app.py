# -*- coding: utf-8 -*-
"""
Auth Center 登录完整示例（Flask）

安装：pip install flask requests
运行：python app.py
访问：http://127.0.0.1:5000/login 发起登录
"""
import secrets

from flask import Flask, redirect, request, session

from auth_center import AuthCenter, AuthCenterError

app = Flask(__name__)
app.secret_key = secrets.token_hex(32)  # 生产环境换成固定随机值

# ====== 配置（改成你的） ======
sdk = AuthCenter(
    client_id='你的client_id',
    client_secret='***',
    redirect_uri='http://127.0.0.1:5000/callback',
    # base_url='https://<AUTH_SERVER>',  # 默认就是这个
)
SCOPE = 'basic'
# ==============================


@app.route('/login')
def login():
    """① 登录入口：生成 state 存 session，跳转授权页"""
    state = secrets.token_urlsafe(16)
    session['oauth_state'] = state
    return redirect(sdk.get_authorize_url(SCOPE, state))


@app.route('/callback')
def callback():
    """② 回调：校验 state → 换令牌 → 拿用户信息 → 创建登录会话"""
    # 校验 state（防 CSRF）
    if request.args.get('state') != session.get('oauth_state'):
        return 'state 校验失败，请重新发起登录', 400

    # 用户拒绝授权
    if 'error' in request.args:
        return f"授权失败：{request.args.get('error_description', request.args['error'])}", 400

    code = request.args.get('code', '')
    if not code:
        return '缺少授权码', 400

    try:
        # 换令牌
        tokens = sdk.exchange_code(code)
        # 拿用户信息
        user = sdk.get_user_info(tokens['access_token'])

        # ===== 在这里创建你自己的登录会话 =====
        # 用 user['id'] 关联你的用户体系，tokens 存数据库
        session['logged_in'] = True
        session['user'] = user
        session['tokens'] = tokens

        return f"欢迎，{user['nickname']}！<a href='/'>进入首页</a>"
    except AuthCenterError as e:
        return f'登录失败：{e}', 500


@app.route('/')
def home():
    """③ 已登录页面（可选，演示用）"""
    user = session.get('user')
    if not user:
        return '<a href="/login">使用 Auth Center 登录</a>'
    return f"你好，{user['nickname']}！<a href='/logout'>退出</a>"


@app.route('/logout')
def logout():
    session.clear()
    return redirect('/')


if __name__ == '__main__':
    app.run(debug=True)
