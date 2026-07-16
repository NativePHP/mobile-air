package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/**
 * Always-on dev overlay showing live FPS / p99 frame time / jank count.
 * Pinned to the top-right corner. Driven by `FrameTracker`'s
 * published `MutableState<Float>` values — recomposes ~4Hz.
 *
 * Color thresholds match the iOS overlay AND React Native's perf
 * monitor convention:
 *   ≥55 fps green (smooth)
 *   ≥30 fps yellow (degraded but interactive)
 *    <30 fps red (broken)
 *
 * Toggle the master switch via `FrameTracker.enabled = false` for
 * production / screenshots.
 */
@Composable
fun PerfOverlay() {
    if (!FrameTracker.enabled) return

    val fps by FrameTracker.fps
    val p99 by FrameTracker.p99Ms
    val jank by FrameTracker.jankCount

    Box(modifier = Modifier.fillMaxSize().padding(top = 36.dp, end = 8.dp)) {
        Column(
            modifier = Modifier
                .align(Alignment.TopEnd)
                .clip(RoundedCornerShape(6.dp))
                .background(Color.Black.copy(alpha = 0.72f))
                .padding(horizontal = 8.dp, vertical = 4.dp),
            horizontalAlignment = Alignment.End,
        ) {
            Text(
                text = "${fps.toInt()} fps",
                color = fpsColor(fps),
                fontFamily = FontFamily.Monospace,
                fontWeight = FontWeight.Bold,
                fontSize = 13.sp,
            )
            Text(
                text = "p99 %.1fms · jank %d".format(p99, jank),
                color = Color.White.copy(alpha = 0.7f),
                fontFamily = FontFamily.Monospace,
                fontSize = 9.sp,
            )
        }
    }
}

private fun fpsColor(fps: Float): Color = when {
    fps >= 55f -> Color(0xFF22C55E)  // green
    fps >= 30f -> Color(0xFFEAB308)  // yellow
    else       -> Color(0xFFEF4444)  // red
}
