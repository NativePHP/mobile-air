package com.nativephp.mobile.ui.nativerender

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
