package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.withFrameNanos
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat

/**
 * Thin Composable wrapper that hosts NativeUIViewRenderer via AndroidView.
 * All rendering logic lives in NativeUIViewRenderer (imperative android.view.View tree).
 */
@Composable
fun NativeUIContent() {
    val tree by NativeUIBridge.currentTree

    // Cache safe area insets for the Yoga bridge (runs on shadow thread)
    val rootView = LocalView.current
    LaunchedEffect(Unit) {
        val insets = ViewCompat.getRootWindowInsets(rootView)
            ?.getInsets(WindowInsetsCompat.Type.systemBars())
        if (insets != null) {
            YogaBridge.safeAreaTopPx = insets.top
            YogaBridge.safeAreaBottomPx = insets.bottom
        }
    }

    LaunchedEffect(tree) {
        if (tree != null && PerformanceTracker.enabled) {
            withFrameNanos { _ ->
                PerformanceTracker.onFrameDrawn()
            }
        }
    }

    AndroidView(
        factory = { context ->
            NativeUIViewRenderer(context).also { view ->
                // Update safe area insets when they change (e.g., rotation)
                ViewCompat.setOnApplyWindowInsetsListener(view) { _, windowInsets ->
                    val bars = windowInsets.getInsets(WindowInsetsCompat.Type.systemBars())
                    YogaBridge.safeAreaTopPx = bars.top
                    YogaBridge.safeAreaBottomPx = bars.bottom
                    windowInsets
                }
            }
        },
        update = { renderer ->
            val t = tree
            if (t != null) {
                renderer.applyTree(t)
            } else {
                renderer.clear()
            }
        },
        modifier = Modifier.fillMaxSize()
    )
}

/* ── Color Helpers (kept for NativeNavRenderers backward compat) ── */

internal fun argbToColor(argb: Int): androidx.compose.ui.graphics.Color {
    return androidx.compose.ui.graphics.Color(argb)
}
