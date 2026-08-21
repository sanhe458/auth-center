package com.sanhe.authcenter.data

import com.sanhe.authcenter.data.model.TokenSet
import com.sanhe.authcenter.data.model.UserInfo
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.FormBody
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.IOException
import java.net.URLEncoder
import java.util.concurrent.TimeUnit

/**
 * Auth Center API 客户端（标准 OAuth 2.0）
 *
 * 接口契约（auth.sanhe.com.mp 文档）：
 *   GET  {base}/api/oauth/authorize  授权页（登录态浏览器访问）
 *   POST {base}/api/oauth/token      授权码换令牌 / 刷新令牌（form 表单）
 *   POST {base}/api/oauth/revoke     吊销令牌
 *   GET  {base}/api/info             用户信息（Bearer）
 *
 * 成功：HTTP 200 + 顶层 JSON；失败：{error, code} + 非 2xx。
 */
class AuthCenterApi(private val baseUrl: String) {

    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .writeTimeout(20, TimeUnit.SECONDS)
        .build()

    private val jsonMedia = "application/json; charset=utf-8".toMediaType()

    /** 生成授权链接（OAuth 2.0 authorization code flow） */
    fun authorizeUrl(clientId: String, redirectUri: String, scope: String, state: String): String {
        val params = buildString {
            append("response_type=code")
            append("&client_id=").append(enc(clientId))
            append("&redirect_uri=").append(enc(redirectUri))
            append("&scope=").append(enc(scope))
            append("&state=").append(enc(state))
        }
        return "$baseUrl/api/oauth/authorize?$params"
    }

    /** 授权码换令牌（标准 OAuth 2.0 token 端点） */
    suspend fun exchangeCode(
        code: String,
        clientId: String,
        clientSecret: String,
        redirectUri: String
    ): TokenSet = withContext(Dispatchers.IO) {
        val form = FormBody.Builder()
            .add("grant_type", "authorization_code")
            .add("code", code)
            .add("client_id", clientId)
            .add("client_secret", clientSecret)
            .add("redirect_uri", redirectUri)
            .build()
        postToken(form)
    }

    /** 刷新令牌（refresh_token 轮换：旧 refresh 吊销，返回新令牌） */
    suspend fun refreshToken(
        refreshToken: String,
        clientId: String,
        clientSecret: String
    ): TokenSet = withContext(Dispatchers.IO) {
        val form = FormBody.Builder()
            .add("grant_type", "refresh_token")
            .add("refresh_token", refreshToken)
            .add("client_id", clientId)
            .add("client_secret", clientSecret)
            .build()
        postToken(form)
    }

    private fun postToken(form: FormBody): TokenSet {
        val req = Request.Builder()
            .url("$baseUrl/api/oauth/token")
            .post(form)
            .build()
        client.newCall(req).execute().use { resp ->
            val body = resp.body?.string().orEmpty()
            if (!resp.isSuccessful) throw ApiException(parseError(body, resp.code))
            val j = JSONObject(body)
            return TokenSet(
                accessToken = j.optString("access_token"),
                refreshToken = j.optString("refresh_token"),
                tokenType = j.optString("token_type", "Bearer"),
                expiresIn = j.optLong("expires_in", 0),
                scope = j.optString("scope"),
                obtainedAt = System.currentTimeMillis()
            )
        }
    }

    /** 吊销令牌 */
    suspend fun revokeToken(token: String): Unit = withContext(Dispatchers.IO) {
        val form = FormBody.Builder().add("token", token).build()
        val req = Request.Builder()
            .url("$baseUrl/api/oauth/revoke")
            .post(form)
            .build()
        client.newCall(req).execute().use { resp ->
            if (!resp.isSuccessful) throw ApiException(parseError(resp.body?.string().orEmpty(), resp.code))
        }
    }

    /** 用户信息（Bearer 资源接口） */
    suspend fun getUserInfo(accessToken: String): UserInfo = withContext(Dispatchers.IO) {
        val req = Request.Builder()
            .url("$baseUrl/api/info")
            .header("Authorization", "Bearer $accessToken")
            .get()
            .build()
        client.newCall(req).execute().use { resp ->
            val body = resp.body?.string().orEmpty()
            if (!resp.isSuccessful) throw ApiException(parseError(body, resp.code))
            val j = JSONObject(body)
            UserInfo(
                id = j.optString("id"),
                nickname = j.optString("nickname"),
                avatar = j.optString("avatar"),
                email = j.optString("email"),
                createdAt = j.optString("created_at")
            )
        }
    }

    /** 发送通知（应用需 notify 权限 + 目标用户已授权 notify） */
    suspend fun sendNotify(
        clientId: String,
        clientSecret: String,
        userId: String,
        title: String,
        body: String
    ): JSONObject = withContext(Dispatchers.IO) {
        val payload = JSONObject()
            .put("client_id", clientId)
            .put("client_secret", clientSecret)
            .put("user_id", userId)
            .put("title", title)
            .put("body", body)
        val req = Request.Builder()
            .url("$baseUrl/api/notify/send_to_user")
            .post(payload.toString().toRequestBody(jsonMedia))
            .build()
        client.newCall(req).execute().use { resp ->
            val body = resp.body?.string().orEmpty()
            if (!resp.isSuccessful) throw ApiException(parseError(body, resp.code))
            JSONObject(body)
        }
    }

    private fun parseError(body: String, httpCode: Int): String {
        return try {
            val j = JSONObject(body)
            j.optString("error").ifEmpty { j.optString("message") }
                .ifEmpty { "请求失败 (HTTP $httpCode)" }
        } catch (_: Exception) {
            "请求失败 (HTTP $httpCode)"
        }
    }

    private fun enc(s: String): String = URLEncoder.encode(s, "UTF-8")
}

class ApiException(message: String) : IOException(message)
