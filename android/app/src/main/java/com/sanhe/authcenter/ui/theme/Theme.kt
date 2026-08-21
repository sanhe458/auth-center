package com.sanhe.authcenter.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

// 品牌色：琥珀金 → 珊瑚橙（与 AuthCenter 站点一致）
val Amber = Color(0xFFFFB74D)
val Coral = Color(0xFFFF7043)
val DeepAmber = Color(0xFF3A1D00)

private val LightColors = lightColorScheme(
    primary = Coral,
    onPrimary = DeepAmber,
    secondary = Amber,
    onSecondary = DeepAmber,
    primaryContainer = Color(0xFFFFE0B2),
    onPrimaryContainer = DeepAmber,
    background = Color(0xFFF7F4F0),
    surface = Color(0xFFFFFFFF),
    onSurface = Color(0xFF1C1B20),
    surfaceVariant = Color(0xFFF1EBF5),
    onSurfaceVariant = Color(0xFF6F6A78)
)

private val DarkColors = darkColorScheme(
    primary = Amber,
    onPrimary = DeepAmber,
    secondary = Coral,
    onSecondary = Color(0xFFFFF),
    primaryContainer = Color(0xFF4A2E10),
    onPrimaryContainer = Color(0xFFFFE0B2),
    background = Color(0xFF000000),
    surface = Color(0xFF15151A),
    onSurface = Color(0xFFE8E4EE),
    surfaceVariant = Color(0xFF1D1D24),
    onSurfaceVariant = Color(0xFF8A8595)
)

@Composable
fun AuthCenterTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit
) {
    MaterialTheme(
        colorScheme = if (darkTheme) DarkColors else LightColors,
        content = content
    )
}
