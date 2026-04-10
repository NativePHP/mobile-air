package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
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

    // Opacity (with dark mode override)
    val darkOpacity = if (isDarkMode) props.getFloat("dark_opacity", 0f) else 0f
    val opacity = if (darkOpacity > 0f) darkOpacity else style.opacity
    if (opacity < 1f && opacity >= 0f) {
        mod = mod.alpha(opacity)
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

    // Safe area padding
    if (layout.safeArea != 0) {
        mod = mod.padding(
            top = safeAreaTop.dp,
            bottom = safeAreaBottom.dp
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
 */
@OptIn(ExperimentalFoundationApi::class)
fun Modifier.nodeGestures(node: NativeUINode): Modifier {
    if (node.onPress == 0 && node.onLongPress == 0) return this

    val callbackId = node.onPress
    val longPressId = node.onLongPress
    val nodeId = node.id

    return if (longPressId != 0) {
        this.combinedClickable(
            onClick = {
                if (callbackId != 0) {
                    NativeElementBridge.sendPressEvent(callbackId, nodeId)
                }
            },
            onLongClick = {
                NativeElementBridge.sendLongPressEvent(longPressId, nodeId)
            }
        )
    } else {
        this.clickable {
            NativeElementBridge.sendPressEvent(callbackId, nodeId)
        }
    }
}

