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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
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
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.kyant.backdrop.backdrops.layerBackdrop
import com.kyant.backdrop.backdrops.rememberLayerBackdrop
import coil.compose.AsyncImage
import com.sanhe.authcenter.ui.theme.Amber
import com.sanhe.authcenter.ui.theme.Coral
import com.sanhe.authcenter.ui.theme.DeepAmber
import com.sanhe.authcenter.vm.AuthUiState
import com.sanhe.authcenter.vm.MainViewModel

/**
 * 主页：用户信息 + 令牌状态 + 通知发送 + 退出登录
 */
@Composable
fun HomeScreen(vm: MainViewModel, state: AuthUiState.LoggedIn) {
    val loading by vm.loading.collectAsState()
    val notifyResult by vm.notifyResult.collectAsState()

    var title by remember { mutableStateOf("") }
    var body by remember { mutableStateOf("") }

    // 自动刷新过期令牌
    LaunchedEffect(state.token) {
        if (state.token.isExpired() && state.token.refreshToken.isNotEmpty()) {
            vm.refreshToken()
        }
    }

    Box(Modifier.fillMaxSize()) {
        val backdrop = rememberLayerBackdrop()
        AppGlassBackground(Modifier.layerBackdrop(backdrop))

        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(20.dp)
        ) {
            // ---- 用户卡片 ----
            GlassCard(
                backdrop = backdrop,
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(20.dp)
            ) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(20.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                UserAvatar(state.user.nickname, state.user.avatar)
                Spacer(Modifier.width(16.dp))
                Column(Modifier.weight(1f)) {
                    Text(
                        state.user.nickname.ifEmpty { "未命名用户" },
                        fontSize = 20.sp,
                        fontWeight = FontWeight.Bold,
                        color = MaterialTheme.colorScheme.onSurface
                    )
                    Text(
                        "ID: ${state.user.id}",
                        fontSize = 13.sp,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                    if (state.user.email.isNotBlank()) {
                        Text(
                            state.user.email,
                            fontSize = 13.sp,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                    if (state.user.createdAt.isNotBlank()) {
                        Text(
                            "注册于 ${state.user.createdAt.take(10)}",
                            fontSize = 12.sp,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                }
            }
        }

        Spacer(Modifier.height(16.dp))

        // ---- 令牌状态 ----
        GlassCard(
            backdrop = backdrop,
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(16.dp)
        ) {
            Column(Modifier.padding(16.dp)) {
                Text("会话状态", fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                Spacer(Modifier.height(8.dp))
                InfoRow("服务器", state.baseUrl)
                InfoRow("权限范围", state.token.scope.ifEmpty { "basic" })
                InfoRow("令牌类型", state.token.tokenType)
                InfoRow(
                    "过期时间",
                    if (state.token.expiresIn > 0)
                        "${state.token.expiresIn}s（${if (state.token.isExpired()) "已过期，将自动刷新" else "有效"}）"
                    else "未知"
                )
            }
        }

        Spacer(Modifier.height(16.dp))

        // ---- 通知发送 ----
        GlassCard(
            backdrop = backdrop,
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(20.dp)
        ) {
            Column(Modifier.padding(20.dp)) {
                Text("发送通知（邮件）", fontWeight = FontWeight.SemiBold)
                Text(
                    "使用应用 notify 权限给当前用户发邮件，需要应用已申请 notify 且用户已授权。",
                    fontSize = 12.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
                Spacer(Modifier.height(12.dp))
                OutlinedTextField(
                    value = title,
                    onValueChange = { title = it },
                    label = { Text("邮件主题") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth()
                )
                Spacer(Modifier.height(10.dp))
                OutlinedTextField(
                    value = body,
                    onValueChange = { body = it },
                    label = { Text("邮件正文") },
                    minLines = 3,
                    modifier = Modifier.fillMaxWidth()
                )
                Spacer(Modifier.height(12.dp))
                GlassButton(
                    backdrop = backdrop,
                    onClick = { vm.sendNotify(title, body) },
                    enabled = !loading && title.isNotBlank() && body.isNotBlank(),
                    modifier = Modifier.fillMaxWidth()
                ) {
                    if (loading) {
                        CircularProgressIndicator(
                            modifier = Modifier.size(18.dp),
                            strokeWidth = 2.dp,
                            color = MaterialTheme.colorScheme.onPrimary
                        )
                    } else {
                        Text(
                            "发送",
                            color = MaterialTheme.colorScheme.onPrimary,
                            fontWeight = FontWeight.SemiBold
                        )
                    }
                }
                notifyResult?.let {
                    Spacer(Modifier.height(10.dp))
                    Text(
                        it,
                        fontSize = 13.sp,
                        color = if (it.startsWith("✅")) MaterialTheme.colorScheme.primary
                                else MaterialTheme.colorScheme.error
                    )
                }
            }
        }

        Spacer(Modifier.height(24.dp))

        // ---- 操作按钮 ----
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            GlassButton(
                backdrop = backdrop,
                onClick = vm::logout,
                modifier = Modifier
                    .weight(1f)
                    .height(48.dp),
                tint = MaterialTheme.colorScheme.surfaceVariant
            ) {
                Text(
                    "退出登录",
                    color = MaterialTheme.colorScheme.onSurface,
                    fontWeight = FontWeight.SemiBold
                )
            }
            GlassButton(
                backdrop = backdrop,
                onClick = vm::refreshToken,
                enabled = !loading,
                modifier = Modifier
                    .weight(1f)
                    .height(48.dp)
            ) {
                Text(
                    "刷新令牌",
                    color = MaterialTheme.colorScheme.onPrimary,
                    fontWeight = FontWeight.SemiBold
                )
            }
        }

        Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun UserAvatar(nickname: String, avatar: String) {
    val ch = nickname.firstOrNull()?.uppercase() ?: "?"
    val gradient = Brush.linearGradient(listOf(Amber, Coral))
    if (avatar.isNotBlank()) {
        AsyncImage(
            model = avatar,
            contentDescription = "头像",
            modifier = Modifier
                .size(56.dp)
                .clip(CircleShape)
                .background(gradient),
            placeholder = null,
            fallback = null,
            error = null
        )
    } else {
        Box(
            modifier = Modifier
                .size(56.dp)
                .clip(CircleShape)
                .background(gradient),
            contentAlignment = Alignment.Center
        ) {
            Text(
                ch,
                color = DeepAmber,
                fontSize = 24.sp,
                fontWeight = FontWeight.Bold
            )
        }
    }
}

@Composable
private fun InfoRow(label: String, value: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
        Text(
            label,
            fontSize = 13.sp,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.width(80.dp)
        )
        Text(
            value,
            fontSize = 13.sp,
            color = MaterialTheme.colorScheme.onSurface,
            modifier = Modifier.weight(1f)
        )
    }
}
