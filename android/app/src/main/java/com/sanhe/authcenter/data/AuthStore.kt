package com.sanhe.authcenter.data

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.longPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import com.sanhe.authcenter.data.model.AppConfig
import com.sanhe.authcenter.data.model.TokenSet
import com.sanhe.authcenter.data.model.UserInfo
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "authcenter")

/**
 * 本地安全存储：应用配置 / 令牌 / 用户信息
 * 基于 DataStore（Preferences），仅存本应用私有目录。
 */
class AuthStore(private val context: Context) {

    private object Keys {
        val BASE_URL = stringPreferencesKey("base_url")
        val CLIENT_ID = stringPreferencesKey("client_id")
        val CLIENT_SECRET = stringPreferencesKey("client_secret")
        val REDIRECT_URI = stringPreferencesKey("redirect_uri")

        val ACCESS_TOKEN = stringPreferencesKey("access_token")
        val REFRESH_TOKEN = stringPreferencesKey("refresh_token")
        val TOKEN_TYPE = stringPreferencesKey("token_type")
        val EXPIRES_IN = longPreferencesKey("expires_in")
        val SCOPE = stringPreferencesKey("scope")
        val OBTAINED_AT = longPreferencesKey("obtained_at")

        val USER_ID = stringPreferencesKey("user_id")
        val USER_NICKNAME = stringPreferencesKey("user_nickname")
        val USER_AVATAR = stringPreferencesKey("user_avatar")
        val USER_EMAIL = stringPreferencesKey("user_email")
        val USER_CREATED_AT = stringPreferencesKey("user_created_at")
    }

    // ---------- 应用配置 ----------

    val config: Flow<AppConfig> = context.dataStore.data.map { p ->
        AppConfig(
            baseUrl = p[Keys.BASE_URL] ?: "",
            clientId = p[Keys.CLIENT_ID] ?: "",
            clientSecret = p[Keys.CLIENT_SECRET] ?: "",
            redirectUri = p[Keys.REDIRECT_URI] ?: ""
        )
    }

    suspend fun saveConfig(cfg: AppConfig) {
        context.dataStore.edit { p ->
            p[Keys.BASE_URL] = cfg.baseUrl.trim().trimEnd('/')
            p[Keys.CLIENT_ID] = cfg.clientId.trim()
            p[Keys.CLIENT_SECRET] = cfg.clientSecret.trim()
            p[Keys.REDIRECT_URI] = cfg.redirectUri.trim()
        }
    }

    // ---------- 令牌 ----------

    val token: Flow<TokenSet> = context.dataStore.data.map { p ->
        TokenSet(
            accessToken = p[Keys.ACCESS_TOKEN] ?: "",
            refreshToken = p[Keys.REFRESH_TOKEN] ?: "",
            tokenType = p[Keys.TOKEN_TYPE] ?: "Bearer",
            expiresIn = p[Keys.EXPIRES_IN] ?: 0,
            scope = p[Keys.SCOPE] ?: "",
            obtainedAt = p[Keys.OBTAINED_AT] ?: 0
        )
    }

    suspend fun saveToken(t: TokenSet) {
        context.dataStore.edit { p ->
            p[Keys.ACCESS_TOKEN] = t.accessToken
            p[Keys.REFRESH_TOKEN] = t.refreshToken
            p[Keys.TOKEN_TYPE] = t.tokenType
            p[Keys.EXPIRES_IN] = t.expiresIn
            p[Keys.SCOPE] = t.scope
            p[Keys.OBTAINED_AT] = System.currentTimeMillis()
        }
    }

    // ---------- 用户信息 ----------

    val user: Flow<UserInfo> = context.dataStore.data.map { p ->
        UserInfo(
            id = p[Keys.USER_ID] ?: "",
            nickname = p[Keys.USER_NICKNAME] ?: "",
            avatar = p[Keys.USER_AVATAR] ?: "",
            email = p[Keys.USER_EMAIL] ?: "",
            createdAt = p[Keys.USER_CREATED_AT] ?: ""
        )
    }

    suspend fun saveUser(u: UserInfo) {
        context.dataStore.edit { p ->
            p[Keys.USER_ID] = u.id
            p[Keys.USER_NICKNAME] = u.nickname
            p[Keys.USER_AVATAR] = u.avatar
            p[Keys.USER_EMAIL] = u.email
            p[Keys.USER_CREATED_AT] = u.createdAt
        }
    }

    /** 退出登录：清空令牌与用户信息（保留应用配置） */
    suspend fun clearAuth() {
        context.dataStore.edit { p ->
            p.remove(Keys.ACCESS_TOKEN)
            p.remove(Keys.REFRESH_TOKEN)
            p.remove(Keys.TOKEN_TYPE)
            p.remove(Keys.EXPIRES_IN)
            p.remove(Keys.SCOPE)
            p.remove(Keys.OBTAINED_AT)
            p.remove(Keys.USER_ID)
            p.remove(Keys.USER_NICKNAME)
            p.remove(Keys.USER_AVATAR)
            p.remove(Keys.USER_EMAIL)
            p.remove(Keys.USER_CREATED_AT)
        }
    }
}
