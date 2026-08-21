package com.sanhe.authcenter.data.model

/** OAuth 令牌组 */
data class TokenSet(
    val accessToken: String = "",
    val refreshToken: String = "",
    val tokenType: String = "Bearer",
    val expiresIn: Long = 0,          // 秒
    val scope: String = "",
    val obtainedAt: Long = 0          // epoch 毫秒
) {
    fun isExpired(): Boolean = obtainedAt > 0 &&
        System.currentTimeMillis() >= obtainedAt + (expiresIn - 30) * 1000
}

/** /api/info 返回的用户信息（标准 UserInfo 顶层格式） */
data class UserInfo(
    val id: String = "",              // 公开 uid，如 u_xxxx
    val nickname: String = "",
    val avatar: String = "",
    val email: String = "",
    val createdAt: String = ""
)

/** 应用配置 */
data class AppConfig(
    val baseUrl: String = "",
    val clientId: String = "",
    val clientSecret: String = "",
    val redirectUri: String = ""
)
