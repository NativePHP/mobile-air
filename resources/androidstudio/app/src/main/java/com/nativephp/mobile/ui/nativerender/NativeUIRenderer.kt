package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.withFrameNanos
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalView
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat

/**
 * Pure Compose entry point for native element rendering.
 * Captures safe area insets and viewport size, provides them
 * via CompositionLocals, and renders the tree via NodeView.
 */
@Composable
fun NativeUIContent() {
    val tree by NativeUIBridge.currentTree

    // Performance tracking — measure frame draw latency
    LaunchedEffect(tree) {
        if (tree != null && PerformanceTracker.enabled) {
            withFrameNanos { _ ->
                PerformanceTracker.onFrameDrawn()
            }
        }
    }

    BoxWithConstraints(modifier = Modifier.fillMaxSize()) {
        // Read safe area insets
        val rootView = LocalView.current
        val density = LocalDensity.current
        val insets = ViewCompat.getRootWindowInsets(rootView)
            ?.getInsets(WindowInsetsCompat.Type.systemBars())

        val safeAreaTopDp = if (insets != null) insets.top / density.density else 0f
        val safeAreaBottomDp = if (insets != null) insets.bottom / density.density else 0f

        CompositionLocalProvider(
            LocalSafeAreaTop provides safeAreaTopDp,
            LocalSafeAreaBottom provides safeAreaBottomDp,
            LocalAvailableWidth provides maxWidth.value,
            LocalAvailableHeight provides maxHeight.value
        ) {
            tree?.let { t ->
                NodeView(node = t.root)
            }
        }
    }
}

/* ── Color Helpers (kept for NativeNavRenderers backward compat) ── */

internal fun argbToColor(argb: Int): androidx.compose.ui.graphics.Color {
    return argbToComposeColor(argb)
}
