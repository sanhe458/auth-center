package com.example.authcenter;

import java.io.IOException;
import java.net.URI;
import java.net.URLEncoder;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.util.Map;
import java.util.stream.Collectors;

/**
 * Auth Center Java SDK（JDK 11+，使用 java.net.http，零第三方依赖）
 *
 * 用法：
 *   AuthCenter sdk = new AuthCenter(
 *       "你的client_id",
 *       "你的client_secret",
 *       "https://yourapp.com/callback"
 *   );
 *
 *   // 1. 生成授权链接
 *   String url = sdk.getAuthorizeUrl("basic", state);
 *
 *   // 2. 回调里换令牌
 *   Map<String, Object> tokens = sdk.exchangeCode(code);
 *
 *   // 3. 获取用户信息
 *   Map<String, Object> user = sdk.getUserInfo((String) tokens.get("access_token"));
 *
 *   // 4. 刷新令牌
 *   Map<String, Object> tokens = sdk.refreshToken((String) tokens.get("refresh_token"));
 */
public class AuthCenter {

    private static final String BASE_URL = "https://auth.sanhe.com.mp";

    private final String baseUrl;
    private final String clientId;
    private final String clientSecret;
    private final String redirectUri;
    private final HttpClient http;

    public AuthCenter(String clientId, String clientSecret, String redirectUri) {
        this(BASE_URL, clientId, clientSecret, redirectUri);
    }

    public AuthCenter(String baseUrl, String clientId, String clientSecret, String redirectUri) {
        this.baseUrl = baseUrl.replaceAll("/$", "");
        this.clientId = clientId;
        this.clientSecret = clientSecret;
        this.redirectUri = redirectUri;
        this.http = HttpClient.newHttpClient();
    }

    /** 生成授权链接（引导用户跳转） */
    public String getAuthorizeUrl(String scope, String state) {
        return baseUrl + "/api/oauth/authorize?" + encodeParams(Map.of(
            "response_type", "code",
            "client_id", clientId,
            "redirect_uri", redirectUri,
            "scope", scope,
            "state", state
        ));
    }

    /** 授权码换令牌 */
    public Map<String, Object> exchangeCode(String code) throws Exception {
        return post("/api/oauth/token", Map.of(
            "grant_type", "authorization_code",
            "code", code,
            "client_id", clientId,
            "client_secret", clientSecret,
            "redirect_uri", redirectUri
        ));
    }

    /** 刷新令牌 */
    public Map<String, Object> refreshToken(String refreshToken) throws Exception {
        return post("/api/oauth/token", Map.of(
            "grant_type", "refresh_token",
            "refresh_token", refreshToken,
            "client_id", clientId,
            "client_secret", clientSecret
        ));
    }

    /** 吊销令牌 */
    public Map<String, Object> revokeToken(String token) throws Exception {
        return post("/api/oauth/revoke", Map.of("token", token));
    }

    /** 获取用户信息 */
    public Map<String, Object> getUserInfo(String accessToken) throws Exception {
        HttpRequest req = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/api/info"))
                .header("Authorization", "Bearer " + accessToken)
                .GET()
                .build();
        return parse(http.send(req, HttpResponse.BodyHandlers.ofString()).body());
    }

    private Map<String, Object> post(String path, Map<String, String> body) throws Exception {
        String json = body.entrySet().stream()
                .map(e -> "\"" + e.getKey() + "\":\"" + escape(e.getValue()) + "\"")
                .collect(Collectors.joining(",", "{", "}"));
        HttpRequest req = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + path))
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(json))
                .build();
        return parse(http.send(req, HttpResponse.BodyHandlers.ofString()).body());
    }

    @SuppressWarnings("unchecked")
    private Map<String, Object> parse(String body) throws Exception {
        // 简化 JSON 解析（生产环境建议用 Jackson/Gson）
        var mapper = new com.fasterxml.jackson.databind.ObjectMapper();
        var root = mapper.readValue(body, Map.class);
        if (((Number) root.get("code")).intValue() != 0) {
            throw new RuntimeException("Auth Center 错误 [" + root.get("code") + "]: " + root.get("message"));
        }
        return (Map<String, Object>) root.get("data");
    }

    private static String encodeParams(Map<String, String> params) {
        return params.entrySet().stream()
                .map(e -> URLEncoder.encode(e.getKey(), StandardCharsets.UTF_8)
                        + "=" + URLEncoder.encode(e.getValue(), StandardCharsets.UTF_8))
                .collect(Collectors.joining("&"));
    }

    private static String escape(String s) {
        return s.replace("\\", "\\\\").replace("\"", "\\\"");
    }

    /* ============ 使用示例（Servlet/Spring 思路） ============ */
    /*
    // ① 登录入口：生成链接重定向
    String state = UUID.randomUUID().toString();
    session.setAttribute("oauth_state", state);
    response.sendRedirect(sdk.getAuthorizeUrl("basic", state));

    // ② 回调：换令牌 + 拿用户信息
    if (!state.equals(session.getAttribute("oauth_state"))) {
        throw new RuntimeException("state 校验失败");
    }
    var tokens = sdk.exchangeCode(request.getParameter("code"));
    var user = sdk.getUserInfo((String) tokens.get("access_token"));
    // 保存 tokens 到数据库
    */
}
