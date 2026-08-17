package com.example.authcenter.example;

import com.example.authcenter.AuthCenter;

import javax.servlet.http.HttpServlet;
import javax.servlet.http.HttpServletRequest;
import javax.servlet.http.HttpServletResponse;
import javax.servlet.http.HttpSession;
import java.util.Map;
import java.util.UUID;

/**
 * Auth Center 登录完整示例（Java Servlet + JSP 思路）
 *
 * 环境：Servlet 3.0+（Tomcat 8+），JSON 解析用 Jackson（Jackson 依赖见 AuthCenter.java 注释）
 * 部署：将 AuthCenter.java 与下面两个 Servlet 放入你的 Web 项目
 *
 * 路由：
 *   GET /login     → 生成 state 存 session，跳转授权页
 *   GET /callback  → 校验 state → 换令牌 → 拿用户信息 → 创建登录会话
 */

/** ① 登录入口 */
public class LoginServlet extends HttpServlet {

    @Override
    protected void doGet(HttpServletRequest req, HttpServletResponse resp) throws java.io.IOException {
        // 配置（改成你的）
        String clientId = "你的client_id";
        String clientSecret = "***";
        String redirectUri = "https://yourapp.com/callback";

        AuthCenter sdk = new AuthCenter(clientId, clientSecret, redirectUri);

        // 生成 state 存 session，回调时校验（防 CSRF）
        HttpSession session = req.getSession();
        String state = UUID.randomUUID().toString();
        session.setAttribute("oauth_state", state);

        // 跳转授权页
        String scope = "basic";
        resp.sendRedirect(sdk.getAuthorizeUrl(scope, state));
    }
}

/** ② 回调处理 */
class CallbackServlet extends HttpServlet {

    @Override
    protected void doGet(HttpServletRequest req, HttpServletResponse resp) throws java.io.IOException {
        resp.setContentType("text/html;charset=UTF-8");

        // 配置（改成你的，和 LoginServlet 一致）
        String clientId = "你的client_id";
        String clientSecret = "***";
        String redirectUri = "https://yourapp.com/callback";

        AuthCenter sdk = new AuthCenter(clientId, clientSecret, redirectUri);
        HttpSession session = req.getSession();

        // ① 校验 state（防 CSRF）
        String state = req.getParameter("state");
        if (state == null || !state.equals(session.getAttribute("oauth_state"))) {
            resp.sendError(400, "state 校验失败，请重新发起登录");
            return;
        }

        // ② 用户拒绝授权
        String error = req.getParameter("error");
        if (error != null) {
            String desc = req.getParameter("error_description");
            resp.sendError(400, "授权失败：" + (desc != null ? desc : error));
            return;
        }

        // ③ 必须带 code
        String code = req.getParameter("code");
        if (code == null || code.isEmpty()) {
            resp.sendError(400, "缺少授权码");
            return;
        }

        try {
            // ④ 换令牌（access_token 2小时 + refresh_token 30天）
            Map<String, Object> tokens = sdk.exchangeCode(code);

            // ⑤ 拿用户信息
            Map<String, Object> user = sdk.getUserInfo((String) tokens.get("access_token"));

            // ⑥ ===== 在这里创建你自己的登录会话 =====
            // 用 user.get("id") 关联你的用户体系，tokens 存数据库
            session.setAttribute("logged_in", true);
            session.setAttribute("nickname", user.get("nickname"));

            // ⑦ 登录成功，跳转首页
            resp.sendRedirect("/index.jsp");

        } catch (Exception e) {
            resp.sendError(500, "登录失败：" + e.getMessage());
        }
    }
}
