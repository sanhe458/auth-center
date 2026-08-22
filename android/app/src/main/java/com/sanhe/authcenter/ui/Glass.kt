package com.sanhe.authcenter.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CornerBasedShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import com.kyant.backdrop.Backdrop
import com.kyant.backdrop.backdrops.layerBackdrop
import com.kyant.backdrop.backdrops.rememberLayerBackdrop
import com.kyant.backdrop.drawBackdrop
import com.kyant.backdrop.effects.blur
import com.kyant.backdrop.effects.lens
import com.kyant.backdrop.effects.vibrancy
import com.sanhe.authcenter.ui.theme.Amber
import com.sanhe.authcenter.ui.theme.Coral

/**
 * 液态玻璃背景：品牌渐变 + 柔和光斑。
 * 用 [rememberLayerBackdrop] + [layerBackdrop] 捕获为 backdrop，
 * 玻璃卡片/按钮通过 [com.kyant.backdrop.drawBackdrop] 引用它，显示模糊 + 折射。
 */
@Composable
fun AppGlassBackground(modifier: Modifier = Modifier) {
    val dark = isSystemInDarkTheme()
    val gradient = if (dark) {
        Brush.verticalGradient(listOf(Color(0xFF2A201C), Color(0xFF14100E)))
    } else {
        Brush.verticalGradient(listOf(Color(0xFFFFF3E8), Color(0xFFFFD9C2)))
    }
    Box(
        modifier
            .fillMaxSize()
            .background(gradient)
    ) {
        // 琥珀光斑（左上）
        Box(
            Modifier
                .size(340.dp)
                .offset(x = (-90).dp, y = (-70).dp)
                .background(
                    Brush.radialGradient(
                        listOf(Amber.copy(alpha = if (dark) 0.20f else 0.55f), Color.Transparent)
                    )
                )
        )
        // 珊瑚光斑（右下）
        Box(
            Modifier
                .size(300.dp)
                .offset(x = 210.dp, y = 430.dp)
                .background(
                    Brush.radialGradient(
                        listOf(Coral.copy(alpha = if (dark) 0.16f else 0.5f), Color.Transparent)
                    )
                )
        )
    }
}

/**
 * 液态玻璃卡片：显示背后背景的模糊 + 折射，叠加主题表面色保证可读性。
 * 效果依赖 RenderEffect（Android 12+ blur / 13+ lens），低版本自动降级为普通半透明卡片，不会崩。
 */
@Composable
fun GlassCard(
    backdrop: Backdrop,
    modifier: Modifier = Modifier,
    shape: CornerBasedShape = RoundedCornerShape(20.dp),
    surfaceColor: Color = MaterialTheme.colorScheme.surface,
    content: @Composable ColumnScope.() -> Unit
) {
    Column(
        modifier.drawBackdrop(
            backdrop = backdrop,
            shape = { shape },
            effects = {
                vibrancy()
                blur(10f.dp.toPx())
                lens(14f.dp.toPx(), 30f.dp.toPx())
            },
            onDrawSurface = {
                drawRect(surfaceColor.copy(alpha = 0.5f))
            }
        )
    ) { content() }
}

/**
 * 液态玻璃按钮：玻璃底 + 品牌色表面 + 高光阴影。
 */
@Composable
fun GlassButton(
    backdrop: Backdrop,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    tint: Color = MaterialTheme.colorScheme.primary,
    content: @Composable () -> Unit
) {
    val shape = RoundedCornerShape(26.dp)
    Box(
        modifier
            .drawBackdrop(
                backdrop = backdrop,
                shape = { shape },
                effects = {
                    vibrancy()
                    blur(8f.dp.toPx())
                    lens(12f.dp.toPx(), 24f.dp.toPx())
                },
                onDrawSurface = {
                    drawRect(tint.copy(alpha = if (enabled) 0.9f else 0.4f))
                }
            )
            .clip(shape)
            .clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center
    ) { content() }
}
