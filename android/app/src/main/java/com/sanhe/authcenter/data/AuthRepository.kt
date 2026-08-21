package com.sanhe.authcenter.data

import com.sanhe.authcenter.data.model.AppConfig
import com.sanhe.authcenter.data.model.TokenSet
import com.sanhe.authcenter.data.model.UserInfo
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

/**
 * 仓储：串联 AuthStore（本地持久化）+ AuthCenterApi（远程），
 * 对外暴露状态流与登录/刷新/登出动作。
 */
class AuthRepository(private val store: AuthStore) {

    val config: Flow<AppConfig> = store.config
    val token: Flow<TokenSet> = store.token
    val user: Flow<UserInfo> = store.user

    /** 是否已登录（本地有有效 access_token 且未过期） */
    val isLoggedIn: Flow<Boolean> = store.token.map { it.accessToken.isNotEmpty() }

    fun apiFor(baseUrl: String): AuthCenterApi = AuthCenterApi(baseUrl)

    suspend fun currentConfig(): AppConfig = store.config.first()

    suspend fun saveConfig(cfg: AppConfig) = store.saveConfig(cfg)

    suspend fun saveToken(t: TokenSet) = store.saveToken(t)

    suspend fun saveUser(u: UserInfo) = store.saveUser(u)

    suspend fun currentToken(): TokenSet = store.token.first()

    suspend fun clearAuth() = store.clearAuth()
}
