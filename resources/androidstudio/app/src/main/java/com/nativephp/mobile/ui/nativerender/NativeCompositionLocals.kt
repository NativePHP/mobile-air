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
 * Node ids TalkBack must skip while an `a11y-modal` container is on screen
 * (see [NodeAccessibility.hiddenByModal]). Provided per screen by
 * `NativeUIContent`; empty when no overlay is shown.
 */
val LocalA11yHiddenByModal = compositionLocalOf { emptySet<Int>() }

/**
 * The ids of the `a11y-modal` container on screen and of its ancestors
 * (see [LocalA11yHiddenByModal]); null when none is shown.
 */
val LocalA11yModalPath = compositionLocalOf<Set<Int>?> { null }

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

/**
 * Shared-element state for refs inside native chrome (`native_root_stack`,
 * `native_root_tabs`), provided by `ChromeMorphPane` for one pane of the
 * chrome renderer's `AnimatedContent` while that pane takes part in a morph,
 * and null the rest of the time.
 *
 * It sits beside the root pair rather than replacing it. The root pair is
 * what a full-screen morph into or out of chrome uses, and a settled pane
 * keeps it exactly as `NativeUIContent` provides it. This one drives a push
 * or pop inside chrome. See `Modifier.heroMorph`.
 */
@OptIn(ExperimentalSharedTransitionApi::class)
class ChromeMorphScopes(
    val sharedScope: SharedTransitionScope,
    val paneScope: AnimatedVisibilityScope,
)

val LocalChromeMorphScopes = compositionLocalOf<ChromeMorphScopes?> { null }

/**
 * The same scopes, provided under every pane of a native-chrome renderer
 * whether or not it takes part in a morph, for the inert registration that
 * `Modifier.heroMorph` keeps at the front of each ref'd node in chrome.
 */
val LocalInertChromeScopes = compositionLocalOf<ChromeMorphScopes?> { null }
