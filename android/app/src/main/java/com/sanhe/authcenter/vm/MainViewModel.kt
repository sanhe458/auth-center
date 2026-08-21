package com.sanhe.authcenter.vm

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.sanhe.authcenter.BuildConfig
import com.sanhe.authcenter.data.AuthRepository
import com.sanhe.authcenter.data.AuthStore
import com.sanhe.authcenter.data.model.AppConfig
import com.sanhe.authcenter.data.model.TokenSet
import com.sanhe.authcenter.data.model.UserInfo
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import java.net.URLDecoder
import java.security.SecureRandom

/** 登录/会话 UI 状态 */
sealed interface AuthUiState {
    /** 未配置 */
    data object NotConfigured : AuthUiState
    /** 已配置、未登录 */
    data object LoggedOut : AuthUiState
    /** 正在 WebView 授权（携带授权页 URL 与 state 供回调校验） */
    data class Authorizing(val url: String, val state: String) : AuthUiState
    /** 已登录 */
    data class LoggedIn(
        val user: UserInfo,
        val token: TokenSet,
        val baseUrl: String
    ) : AuthUiState
}

class MainViewModel(app: Application) : AndroidViewModel(app) {

    private val repo = AuthRepository(AuthStore(app.applicationContext))

    private val _authState = MutableStateFlow<AuthUiState>(AuthUiState.NotConfigured)
    val authState: StateFlow<AuthUiState> = _authState.asStateFlow()

    private val _config = MutableStateFlow(AppConfig())
    val config: StateFlow<AppConfig> = _config.asStateFlow()

    private val _loading = MutableStateFlow(false)
    val loading: StateFlow<Boolean> = _loading.asStateFlow()

    private val _error = MutableStateFlow<String?>(null)
    val error: StateFlow<String?> = _error.asStateFlow()

    private val _notifyResult = MutableStateFlow<String?>(null)
    val notifyResult: StateFlow<String?> = _notifyResult.asStateFlow()

    /** 应用默认值（与 buildConfig 一致，供首次配置预填） */
    val defaultBaseUrl: String = BuildConfig.DEFAULT_BASE_URL
    val defaultRedirectUri: String = BuildConfig.DEFAULT_REDIRECT_URI

    init {
        viewModelScope.launch {
            repo.config.collect { cfg ->
                _config.value = cfg
                if (cfg.baseUrl.isBlank() || cfg.clientId.isBlank()) {
                    _authState.value = AuthUiState.NotConfigured
                    return@collect
                }
                // 有配置：检查本地令牌
                val t = repo.currentToken()
                if (t.accessToken.isNotEmpty()) {
                    _authState.value = if (t.isExpired()) {
                        AuthUiState.LoggedOut
                    } else {
                        AuthUiState.LoggedIn(repo.user.first(), t, cfg.baseUrl)
                    }
                } else {
                    _authState.value = AuthUiState.LoggedOut
                }
            }
        }
    }

    /** 保存配置并清空旧登录态（换服务器/换应用） */
    fun saveConfig(baseUrl: String, clientId: String, clientSecret: String, redirectUri: String) {
        viewModelScope.launch {
            _loading.value = true
            _error.value = null
            try {
                repo.saveConfig(
                    AppConfig(
                        baseUrl = baseUrl,
                        clientId = clientId,
                        clientSecret = clientSecret,
                        redirectUri = redirectUri
                    )
                )
                repo.clearAuth()
                _authState.value = AuthUiState.LoggedOut
            } catch (e: Exception) {
                _error.value = e.message ?: "保存配置失败"
            } finally {
                _loading.value = false
            }
        }
    }

    /** 发起 OAuth 授权（生成 state + 授权 URL，进入 WebView 流程） */
    fun startLogin() {
        val cfg = _config.value
        if (cfg.baseUrl.isBlank() || cfg.clientId.isBlank() || cfg.redirectUri.isBlank()) {
            _error.value = "请先完成服务器与应用配置"
            return
        }
        val state = generateState()
        val api = repo.apiFor(cfg.baseUrl)
        val url = api.authorizeUrl(cfg.clientId, cfg.redirectUri, "basic notify", state)
        _authState.value = AuthUiState.Authorizing(url, state)
        _error.value = null
    }

    /** WebView 拦截到回调 URL（如 authcenter://callback?code=...&state=...） */
    fun onAuthCallback(url: String) {
        val cur = _authState.value as? AuthUiState.Authorizing ?: return
        viewModelScope.launch {
            try {
                val parsed = parseCallback(url)
                if (parsed["error"] != null) {
                    _authState.value = AuthUiState.LoggedOut
                    _error.value = "授权失败: ${parsed["error"]} ${parsed["error_description"] ?: ""}".trim()
                    return@launch
                }
                val code = parsed["code"] ?: run {
                    _authState.value = AuthUiState.LoggedOut
                    _error.value = "回调缺少授权码"
                    return@launch
                }
                val state = parsed["state"]
                if (state != cur.state) {
                    _authState.value = AuthUiState.LoggedOut
                    _error.value = "state 校验失败（疑似 CSRF）"
                    return@launch
                }
                _loading.value = true
                val cfg = _config.value
                val api = repo.apiFor(cfg.baseUrl)
                val token = api.exchangeCode(code, cfg.clientId, cfg.clientSecret, cfg.redirectUri)
                val user = api.getUserInfo(token.accessToken)
                repo.saveToken(token)
                repo.saveUser(user)
                _authState.value = AuthUiState.LoggedIn(user, token, cfg.baseUrl)
            } catch (e: Exception) {
                _authState.value = AuthUiState.LoggedOut
                _error.value = "登录失败: ${e.message}"
            } finally {
                _loading.value = false
            }
        }
    }

    /** 刷新令牌（access_token 过期时调用） */
    fun refreshToken() {
        val cfg = _config.value
        if (cfg.clientId.isBlank()) return
        viewModelScope.launch {
            try {
                val t = repo.currentToken()
                if (t.refreshToken.isEmpty()) {
                    logout()
                    return@launch
                }
                val api = repo.apiFor(cfg.baseUrl)
                val newToken = api.refreshToken(t.refreshToken, cfg.clientId, cfg.clientSecret)
                repo.saveToken(newToken)
                val user = api.getUserInfo(newToken.accessToken)
                repo.saveUser(user)
                _authState.value = AuthUiState.LoggedIn(user, newToken, cfg.baseUrl)
            } catch (e: Exception) {
                // 刷新失败：清登录态回登录页
                logout()
            }
        }
    }

    /** 退出登录：吊销令牌 + 清本地 */
    fun logout() {
        viewModelScope.launch {
            try {
                val t = repo.currentToken()
                if (t.accessToken.isNotEmpty()) {
                    val cfg = _config.value
                    if (cfg.baseUrl.isNotBlank()) {
                        repo.apiFor(cfg.baseUrl).revokeToken(t.accessToken)
                    }
                }
            } catch (_: Exception) {
                // 吊销失败不阻塞本地登出
            } finally {
                repo.clearAuth()
                _authState.value = AuthUiState.LoggedOut
            }
        }
    }

    /** 发送通知给自己（需要应用 notify 权限 + 用户已授权 notify） */
    fun sendNotify(title: String, body: String) {
        val cfg = _config.value
        val cur = _authState.value as? AuthUiState.LoggedIn ?: return
        viewModelScope.launch {
            _loading.value = true
            _notifyResult.value = null
            try {
                val api = repo.apiFor(cfg.baseUrl)
                val resp = api.sendNotify(cfg.clientId, cfg.clientSecret, cur.user.id, title, body)
                val nickname = resp.optJSONObject("user")?.optString("nickname") ?: cur.user.nickname
                val email = resp.optString("email")
                _notifyResult.value = "✅ 已发送给 $nickname ($email)"
            } catch (e: Exception) {
                _notifyResult.value = "❌ ${e.message}"
            } finally {
                _loading.value = false
            }
        }
    }

    fun clearError() { _error.value = null }
    fun clearNotifyResult() { _notifyResult.value = null }

    private fun parseCallback(url: String): Map<String, String> {
        val q = url.substringAfter('?', "")
        return q.split('&').mapNotNull {
            val kv = it.split('=', limit = 2)
            if (kv.size == 2) kv[0] to URLDecoder.decode(kv[1], "UTF-8") else null
        }.toMap()
    }

    private fun generateState(): String {
        val bytes = ByteArray(16)
        SecureRandom().nextBytes(bytes)
        return bytes.joinToString("") { "%02x".format(it) }
    }
}
