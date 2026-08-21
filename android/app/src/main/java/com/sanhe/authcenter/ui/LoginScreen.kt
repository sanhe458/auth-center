package com.sanhe.authcenter.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.sanhe.authcenter.ui.theme.Amber
import com.sanhe.authcenter.ui.theme.Coral
import com.sanhe.authcenter.ui.theme.DeepAmber
import com.sanhe.authcenter.vm.AuthUiState
import com.sanhe.authcenter.vm.MainViewModel

/**
 * 登录/配置页：
 *  - 未配置：显示服务器/应用表单
 *  - 已配置未登录：显示登录按钮
 *  - 授权中：显示 WebView
 */
@Composable
fun LoginScreen(vm: MainViewModel, state: AuthUiState) {
    val config by vm.config.collectAsState()
    val loading by vm.loading.collectAsState()
    val error by vm.error.collectAsState()

    var baseUrl by remember { mutableStateOf(vm.defaultBaseUrl) }
    var clientId by remember { mutableStateOf(vm.defaultClientId) }
    var clientSecret by remember { mutableStateOf(vm.defaultClientSecret) }
    var redirectUri by remember { mutableStateOf(vm.defaultRedirectUri) }

    // 配置从 DataStore 恢复后同步到输入框
    LaunchedEffect(config) {
        baseUrl = config.baseUrl
        clientId = config.clientId
        clientSecret = config.clientSecret
        redirectUri = config.redirectUri
    }

    when (state) {
        is AuthUiState.Authorizing -> {
            OAuthWebView(
                url = state.url,
                redirectUri = config.redirectUri.ifEmpty { vm.defaultRedirectUri },
                onCallback = vm::onAuthCallback
            )
        }
        else -> {
            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .verticalScroll(rememberScrollState())
                    .padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                Spacer(Modifier.height(32.dp))
                BrandLogo()
                Spacer(Modifier.height(16.dp))
                Text(
                    "AuthCenter",
                    fontSize = 26.sp,
                    fontWeight = FontWeight.Bold,
                    color = MaterialTheme.colorScheme.onBackground
                )
                Text(
                    "统一身份认证 · 安卓客户端",
                    fontSize = 14.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
                Spacer(Modifier.height(28.dp))

                Card(
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(20.dp),
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.surface
                    )
                ) {
                    Column(Modifier.padding(20.dp)) {
                        Text("服务器与应用配置", fontWeight = FontWeight.SemiBold)
                        Spacer(Modifier.height(12.dp))
                        OutlinedTextField(
                            value = baseUrl,
                            onValueChange = { baseUrl = it },
                            label = { Text("服务器地址") },
                            placeholder = { Text(vm.defaultBaseUrl) },
                            singleLine = true,
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                            modifier = Modifier.fillMaxWidth()
                        )
                        Spacer(Modifier.height(10.dp))
                        OutlinedTextField(
                            value = clientId,
                            onValueChange = { clientId = it },
                            label = { Text("Client ID") },
                            placeholder = { Text(vm.defaultClientId) },
                            singleLine = true,
                            modifier = Modifier.fillMaxWidth()
                        )
                        Spacer(Modifier.height(10.dp))
                        OutlinedTextField(
                            value = clientSecret,
                            onValueChange = { clientSecret = it },
                            label = { Text("Client Secret") },
                            placeholder = { Text("已内置，留空用官方应用") },
                            singleLine = true,
                            visualTransformation = PasswordVisualTransformation(),
                            modifier = Modifier.fillMaxWidth()
                        )
                        Spacer(Modifier.height(10.dp))
                        OutlinedTextField(
                            value = redirectUri,
                            onValueChange = { redirectUri = it },
                            label = { Text("回调地址") },
                            placeholder = { Text(vm.defaultRedirectUri) },
                            singleLine = true,
                            modifier = Modifier.fillMaxWidth()
                        )
                        Spacer(Modifier.height(16.dp))
                        Button(
                            onClick = {
                                vm.saveConfig(
                                    baseUrl.ifBlank { vm.defaultBaseUrl },
                                    clientId.ifBlank { vm.defaultClientId },
                                    clientSecret.ifBlank { vm.defaultClientSecret },
                                    redirectUri.ifBlank { vm.defaultRedirectUri }
                                )
                            },
                            enabled = !loading,
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            Text("保存配置")
                        }
                    }
                }

                if (state is AuthUiState.LoggedOut) {
                    Spacer(Modifier.height(20.dp))
                    Button(
                        onClick = vm::startLogin,
                        enabled = !loading,
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(52.dp)
                    ) {
                        Text("🔐 使用 AuthCenter 登录", fontSize = 16.sp)
                    }
                }

                if (loading) {
                    Spacer(Modifier.height(16.dp))
                    CircularProgressIndicator(modifier = Modifier.size(28.dp))
                }

                error?.let {
                    Spacer(Modifier.height(16.dp))
                    Text(
                        it,
                        color = MaterialTheme.colorScheme.error,
                        fontSize = 13.sp,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth()
                    )
                }
                Spacer(Modifier.height(24.dp))
            }
        }
    }
}

@Composable
fun BrandLogo() {
    Box(
        modifier = Modifier
            .size(64.dp)
            .clip(RoundedCornerShape(18.dp))
            .background(Brush.linearGradient(listOf(Amber, Coral))),
        contentAlignment = Alignment.Center
    ) {
        Text("✓", color = DeepAmber, fontSize = 32.sp, fontWeight = FontWeight.Bold)
    }
}

