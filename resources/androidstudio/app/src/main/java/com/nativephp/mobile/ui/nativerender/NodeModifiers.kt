package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.unit.dp

// MARK: - ARGB Color Conversion

/**
 * Convert a 32-bit ARGB integer to a Compose Color.
 * Transparent (0x00000000) maps to Color.Transparent.
 */
fun argbToComposeColor(argb: Int): Color {
    val v = argb.toUInt()
    val a = ((v shr 24) and 0xFFu).toFloat() / 255f
    if (a <= 0f) return Color.Transparent
    val r = ((v shr 16) and 0xFFu).toFloat() / 255f
    val g = ((v shr 8) and 0xFFu).toFloat() / 255f
    val b = (v and 0xFFu).toFloat() / 255f
    return Color(r, g, b, a)
}

// MARK: - Style Modifier

/**
 * Applies visual style properties from a NativeUINode.
 * Handles background color, corner radius, border, shadow, opacity,
 * and dark mode overrides from dark_* props.
 */
fun Modifier.nodeStyle(style: NodeStyle?, props: GenericProps, isDarkMode: Boolean): Modifier {
    if (style == null) return this

    var mod = this
    val radius = style.borderRadius

    // Opacity (with dark mode override).
    //
    // Must be applied FIRST: a Compose modifier only affects what is drawn
    // after it in the chain, so alpha has to precede shadow/background/border
    // to fade the whole box — matching SwiftUI's .opacity(), which wraps the
    // entire view. Applied last, it would only fade inner content.
    //
    // Defer to `NodeView` when:
    //   - `animate-duration > 0` (state transitions),
    //   - `animate-loop` (yoyo),
    //   - opacity is bound to a SharedValue (`opacity_sv` set).
    // Otherwise applying here would double-multiply.
    val animateDuration = props.getFloat("animate-duration", 0f)
    val animateLoop = props.getBool("animate-loop")
    val opacitySharedBound = props.getString("opacity_sv", "").isNotEmpty()
    if (animateDuration <= 0f && !animateLoop && !opacitySharedBound) {
        val darkOpacity = if (isDarkMode) props.getFloat("dark_opacity", 0f) else 0f
        val opacity = if (darkOpacity > 0f) darkOpacity else style.opacity
        if (opacity < 1f && opacity >= 0f) {
            mod = mod.alpha(opacity)
        }
    }

    // Background color (with dark mode override)
    val darkBg = if (isDarkMode) props.getColor("dark_bg_color", 0) else 0
    val bgArgb = if (darkBg != 0) darkBg else style.bgColor
    val shape = if (radius > 0f) RoundedCornerShape(radius.dp) else RoundedCornerShape(0.dp)

    // Shadow — must come before background. Compose shadow requires a shape
    // to cast from; the background provides the visual fill.
    if (style.elevation > 0f) {
        mod = mod.shadow(elevation = style.elevation.dp, shape = shape)
    }

    // Background
    if (bgArgb != 0) {
        val bgColor = argbToComposeColor(bgArgb)
        if (bgColor != Color.Transparent) {
            mod = mod.background(bgColor, shape)
        }
    } else if (style.elevation > 0f) {
        // Shadow needs a background to be visible — add white if none specified
        mod = mod.background(Color.White, shape)
    }

    // Note: clip is NOT applied automatically for border-radius.
    // background(shape) and border(shape) already render rounded visuals.
    // Clipping would cut off children near rounded corners (e.g. icons in cards).
    // Only apply clip when overflow == hidden (scroll containers handle their own).

    // Border
    if (style.borderWidth > 0f) {
        val darkBorder = if (isDarkMode) props.getColor("dark_border_color", 0) else 0
        val borderArgb = if (darkBorder != 0) darkBorder else style.borderColor
        if (borderArgb != 0) {
            val borderColor = argbToComposeColor(borderArgb)
            if (borderColor != Color.Transparent) {
                val shape = if (radius > 0f) RoundedCornerShape(radius.dp) else RoundedCornerShape(0.dp)
                mod = mod.border(style.borderWidth.dp, borderColor, shape)
            }
        }
    }

    return mod
}

// MARK: - Layout Modifier

/**
 * Applies layout properties from a NativeUINode.
 *
 * Size constraints (width/height modes, fill/fixed) are NOT applied here —
 * they are handled by FlexLayout which reads childNodes[i].layout directly.
 * In Compose, modifier constraints override what the parent Layout proposes,
 * so sizing must live in the Layout, not in modifiers.
 *
 * This modifier handles: padding, safe area, aspect ratio, display:none.
 */
fun Modifier.nodeLayout(
    layout: NodeLayout?,
    safeAreaTop: Float,
    safeAreaBottom: Float,
    availableWidth: Float,
    availableHeight: Float
): Modifier {
    if (layout == null) return this

    var mod = this

    // Padding
    if (layout.paddingTop > 0f || layout.paddingRight > 0f ||
        layout.paddingBottom > 0f || layout.paddingLeft > 0f) {
        mod = mod.padding(
            start = layout.paddingLeft.dp,
            top = layout.paddingTop.dp,
            end = layout.paddingRight.dp,
            bottom = layout.paddingBottom.dp
        )
    }

    // Safe area padding. `safe_area` encodes which edges to inset:
    //   0 = none, 1 = both (legacy), 2 = top only, 3 = bottom only.
    // Top-only / bottom-only let a layout's wrapper free one edge so a
    // chrome bar (NavBar / TabBar) can extend its bg through the system
    // inset zone, while the wrapper still handles the other edge.
    if (layout.safeArea != 0) {
        val applyTop = layout.safeArea == 1 || layout.safeArea == 2
        val applyBottom = layout.safeArea == 1 || layout.safeArea == 3
        mod = mod.padding(
            top = if (applyTop) safeAreaTop.dp else 0.dp,
            bottom = if (applyBottom) safeAreaBottom.dp else 0.dp
        )
    }

    // Aspect ratio
    if (layout.aspectRatio > 0f && layout.aspectRatio.isFinite()) {
        mod = mod.aspectRatio(layout.aspectRatio)
    }

    // Display none
    if (layout.display == Display.NONE) {
        mod = mod.size(0.dp).alpha(0f)
    }

    return mod
}

// MARK: - Gesture Modifier

/**
 * Wires onPress / onLongPress callbacks to Compose gestures.
 *
 * When `interactionSource` is non-null, it's passed to the underlying
 * clickable so callers can collect press state (via
 * `interactionSource.collectIsPressedAsState()`) and drive visual
 * press feedback on the UI thread. In that case the ripple is
 * suppressed (`indication = null`) because the caller is providing
 * the press feedback via `press-*` props. Pass null to keep the
 * default ripple behavior.
 */
@OptIn(ExperimentalFoundationApi::class)
fun Modifier.nodeGestures(
    node: NativeUINode,
    interactionSource: androidx.compose.foundation.interaction.MutableInteractionSource? = null,
): Modifier {
    val callbackId = node.onPress
    val longPressId = node.onLongPress
    // Double-tap is carried in props (`on_double_tap`), not a dedicated node
    // field like onPress/onLongPress, so it needs no binary wire-format change.
    val doubleTapId = node.props.getInt("on_double_tap")
    // Press-down/up ride the props dict the same way. Both reuse the PRESS
    // wire event — the callback id alone routes to the handler.
    val pressDownId = node.props.getInt("on_press_down")
    val pressUpId = node.props.getInt("on_press_up")

    if (callbackId == 0 && longPressId == 0 && doubleTapId == 0 &&
        pressDownId == 0 && pressUpId == 0
    ) {
        return this
    }

    val nodeId = node.id

    var mod: Modifier = this

    // Touch-contact tracking for held-button semantics (gamepad d-pads,
    // push-to-talk): down fires on first contact, up when every pointer
    // lifts. The `finally` also runs on gesture-coroutine cancellation
    // (scroll steals the stream, node leaves composition) so a held button
    // is never left stuck. Observes without consuming, so it composes with
    // the clickable below when @press is also present.
    if (pressDownId != 0 || pressUpId != 0) {
        mod = mod.pointerInput(pressDownId, pressUpId, nodeId) {
            awaitEachGesture {
                awaitFirstDown(requireUnconsumed = false)
                if (pressDownId != 0) {
                    NativeElementBridge.sendPressEvent(pressDownId, nodeId)
                }
                try {
                    do {
                        val event = awaitPointerEvent()
                    } while (event.changes.any { it.pressed })
                } finally {
                    if (pressUpId != 0) {
                        NativeElementBridge.sendPressEvent(pressUpId, nodeId)
                    }
                }
            }
        }
    }

    if (callbackId == 0 && longPressId == 0 && doubleTapId == 0) return mod

    val onClickAction: () -> Unit = {
        if (callbackId != 0) {
            NativeElementBridge.sendPressEvent(callbackId, nodeId)
        }
    }
    val onLongClickAction: (() -> Unit)? = if (longPressId != 0) {
        { NativeElementBridge.sendLongPressEvent(longPressId, nodeId) }
    } else {
        null
    }
    // Double-tap reuses the press event type — the callback id alone routes
    // to the @doubleTap handler on the PHP side.
    val onDoubleClickAction: (() -> Unit)? = if (doubleTapId != 0) {
        { NativeElementBridge.sendPressEvent(doubleTapId, nodeId) }
    } else {
        null
    }

    // `clickable` only handles single tap; long-press / double-tap require
    // `combinedClickable`.
    val needCombined = onLongClickAction != null || onDoubleClickAction != null

    return if (interactionSource != null) {
        if (needCombined) {
            mod.combinedClickable(
                interactionSource = interactionSource,
                indication = null,
                onLongClick = onLongClickAction,
                onDoubleClick = onDoubleClickAction,
                onClick = onClickAction,
            )
        } else {
            mod.clickable(
                interactionSource = interactionSource,
                indication = null,
                onClick = onClickAction,
            )
        }
    } else {
        if (needCombined) {
            mod.combinedClickable(
                onLongClick = onLongClickAction,
                onDoubleClick = onDoubleClickAction,
                onClick = onClickAction,
            )
        } else {
            mod.clickable(onClick = onClickAction)
        }
    }
}

