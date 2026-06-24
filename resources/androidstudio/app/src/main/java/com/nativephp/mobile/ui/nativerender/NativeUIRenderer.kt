package com.nativephp.mobile.ui.nativerender

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.imePadding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.withFrameNanos
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalFocusManager
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
    val screenKey by NativeUIBridge.screenKey
    val pendingTransition by NativeUIBridge.pendingTransition

    // Performance tracking — measure frame draw latency
    LaunchedEffect(tree) {
        if (tree != null && PerformanceTracker.enabled) {
            withFrameNanos { _ ->
                PerformanceTracker.onFrameDrawn()
            }
        }
    }

    val focusManager = LocalFocusManager.current

    BoxWithConstraints(
        modifier = Modifier
            .fillMaxSize()
            .imePadding()
            .clickable(
                indication = null,
                interactionSource = remember { MutableInteractionSource() }
            ) {
                // Tap outside any input dismisses keyboard
                focusManager.clearFocus()
            }
    ) {
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
            // AnimatedContent keyed off screenKey transitions when PHP signals
            // a navigation. The transition spec maps Edge\Transition string
            // values (slide_from_right, fade, etc.) to Compose's enter/exit
            // pairs, mirroring the iOS nativeScreenTransition(for:) mapper.
            AnimatedContent(
                targetState = screenKey,
                transitionSpec = { transitionFor(pendingTransition) },
                label = "screen-transition"
            ) { _ ->
                tree?.let { t ->
                    // Fold any plugin-registered root hosts (side drawers,
                    // global overlays, …) around the rendered tree. A host
                    // pulls its own sentinel child out of `t.root` and renders
                    // nothing when absent. A no-op pass-through when none are
                    // registered, so trees using no plugin chrome pay nothing.
                    NativeRootHostRegistry.Wrap(root = t.root) {
                        NodeView(node = t.root)
                    }
                }
            }
        }
    }
}

/**
 * Map a PHP-side Edge\Transition value to a Compose AnimatedContent
 * ContentTransform. Mirrors core's iOS nativeScreenTransition(for:)
 * (ScreenTransitions.swift).
 *
 * `internal` so other renderers (NativeRootTabsRenderer's within-tab
 * push animation, future stack renderers) can share the same mapper
 * instead of duplicating the spec table.
 */
internal fun transitionFor(type: String?): ContentTransform {
    val spec = tween<Float>(durationMillis = 250)
    val intSpec = tween<androidx.compose.ui.unit.IntOffset>(durationMillis = 250)
    return when (type) {
        "slide_from_right" -> slideInHorizontally(intSpec) { it } togetherWith
            slideOutHorizontally(intSpec) { -it }
        "slide_from_left" -> slideInHorizontally(intSpec) { -it } togetherWith
            slideOutHorizontally(intSpec) { it }
        "slide_from_bottom" -> slideInVertically(intSpec) { it } togetherWith
            slideOutVertically(intSpec) { -it }
        "fade" -> fadeIn(spec) togetherWith fadeOut(spec)
        "fade_from_bottom" -> (slideInVertically(intSpec) { it } + fadeIn(spec)) togetherWith fadeOut(spec)
        "scale_from_center" -> (scaleIn(spec) + fadeIn(spec)) togetherWith (scaleOut(spec) + fadeOut(spec))
        "none" -> fadeIn(tween(0)) togetherWith fadeOut(tween(0))
        else -> fadeIn(spec) togetherWith fadeOut(spec)
    }
}

/* ── Color Helpers (kept for NativeNavRenderers backward compat) ── */

internal fun argbToColor(argb: Int): androidx.compose.ui.graphics.Color {
    return argbToComposeColor(argb)
}
