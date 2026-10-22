package com.nativephp.mobile.ui.nativerender

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.ExitTransition
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
import androidx.compose.runtime.DisposableEffect
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

    // Gesture modifiers dispatch @press outside composable scope, so the
    // policy carries a clear-focus hook for them; interactive taps route
    // through it and honor `keep-focus-on-submit` (mobile-air #335).
    DisposableEffect(focusManager) {
        KeyboardFocusPolicy.clearFocus = { focusManager.clearFocus() }
        onDispose {
            KeyboardFocusPolicy.clearFocus = null
        }
    }

    BoxWithConstraints(
        modifier = Modifier
            .fillMaxSize()
            .imePadding()
            .clickable(
                indication = null,
                interactionSource = remember { MutableInteractionSource() }
            ) {
                // Tap on a plain area dismisses the keyboard. Fires only for
                // taps no child consumed; interactive elements dismiss via
                // KeyboardFocusPolicy in their own handlers instead.
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
            //
            // Each pane renders the tree pinned to ITS key, never the live
            // `tree` state: AnimatedContent recomposes the exiting pane when
            // `tree` changes, so reading the live tree there snapped the old
            // screen to the incoming page — the destination showed for a
            // frame BEFORE the enter/exit animation ran. The current pane
            // re-pins on every pass (state updates still flow through); an
            // exiting pane keeps the last tree it showed and releases it
            // when its exit animation completes.
            val treesByKey = remember { HashMap<Int, NativeUITree>() }
            AnimatedContent(
                targetState = screenKey,
                transitionSpec = { transitionFor(pendingTransition) },
                label = "screen-transition"
            ) { key ->
                DisposableEffect(key) {
                    onDispose { treesByKey.remove(key) }
                }
                val paneTree = if (key == screenKey) {
                    tree?.also { treesByKey[key] = it }
                } else {
                    treesByKey[key]
                }
                paneTree?.let { t ->
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
        // Short upward drift (1/8 screen height) + fade over the HELD
        // outgoing screen — the conventional "fade from bottom" (React
        // Navigation's fadeFromBottom, classic Android activity open).
        // Previously a full-height slide + fade, which was visually
        // indistinguishable from slide_from_bottom (the opaque incoming
        // screen covers everything mid-slide anyway). Matches iOS's
        // fixed-drift + `.identity`-removal mapping in ScreenTransitions.swift.
        // Holding the outgoing screen used ExitTransition
        // .KeepUntilTransitionsFinished, which newer Compose (BOM
        // 2025.12+) makes internal. A fade to 0.99 alpha over the same
        // duration is the public-API equivalent: the outgoing screen
        // stays visually opaque beneath the incoming one for the whole
        // transition, then is removed.
        "fade_from_bottom" -> (slideInVertically(intSpec) { it / 8 } + fadeIn(spec)) togetherWith
            fadeOut(spec, targetAlpha = 0.99f)
        // Scale the incoming screen in from 50% while it stays fully opaque
        // (no fadeIn) so the whole zoom is visible; the outgoing screen fades
        // out beneath it. Combining scaleIn with fadeIn previously hid the
        // small-scale half of the zoom, making it read as a faint pop.
        "scale_from_center" -> scaleIn(spec, initialScale = 0.1f) togetherWith fadeOut(spec)
        // iOS-style parallax push: incoming slides fully from the right while
        // the outgoing screen drifts only ~1/3 of its width to the left,
        // staying visible beneath the incoming screen for a layered depth cue.
        "parallax_push" -> (slideInHorizontally(intSpec) { it }) togetherWith
            slideOutHorizontally(intSpec) { -it / 3 }
        "none" -> fadeIn(tween(0)) togetherWith fadeOut(tween(0))
        else -> fadeIn(spec) togetherWith fadeOut(spec)
    }
}

/* ── Color Helpers (kept for NativeNavRenderers backward compat) ── */

internal fun argbToColor(argb: Int): androidx.compose.ui.graphics.Color {
    return argbToComposeColor(argb)
}
