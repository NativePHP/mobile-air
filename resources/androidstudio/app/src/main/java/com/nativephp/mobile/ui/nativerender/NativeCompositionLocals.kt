package com.nativephp.mobile.ui.nativerender

import androidx.compose.animation.AnimatedVisibilityScope
import androidx.compose.animation.ExperimentalSharedTransitionApi
import androidx.compose.animation.SharedTransitionScope
import androidx.compose.runtime.compositionLocalOf

/**
 * CompositionLocals for safe area insets and viewport size.
 * Provided at the root NativeUIContent composable, consumed
 * by NodeModifiers for layout and safe area calculations.
 * Values are in dp (density-independent pixels).
 */
val LocalSafeAreaTop = compositionLocalOf { 0f }
val LocalSafeAreaBottom = compositionLocalOf { 0f }
val LocalAvailableWidth = compositionLocalOf { 390f }
val LocalAvailableHeight = compositionLocalOf { 844f }

/**
 * True when a root host renders a persistent background layer beneath the
 * content (e.g. mobile-ui's `background_layer` map). Chrome that normally
 * paints an opaque canvas — the tabs Scaffold — goes transparent so the
 * layer shows through.
 */
val LocalBackgroundLayerPresent = compositionLocalOf { false }

/**
 * Scopes needed to place a shared element (`ref`) into a morph.
 *
 * `Modifier.sharedBounds` is declared on `SharedTransitionScope` and needs the
 * `AnimatedVisibilityScope` of the pane it lives in. `NodeView` is a plain
 * recursive composable with no receiver, so both arrive through composition
 * locals — the same route the safe-area values already take.
 *
 * Null wherever no host provides them (previews, tests, any tree rendered
 * outside `NativeUIContent`), which makes the shared-element path an inert
 * pass-through rather than a crash.
 */
@OptIn(ExperimentalSharedTransitionApi::class)
val LocalSharedTransitionScope = compositionLocalOf<SharedTransitionScope?> { null }

val LocalAnimatedVisibilityScope = compositionLocalOf<AnimatedVisibilityScope?> { null }
