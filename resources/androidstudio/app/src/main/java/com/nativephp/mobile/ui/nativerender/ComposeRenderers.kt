package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp

/**
 * Composable renderer interface for native UI components.
 * All component renderers (core and plugin) implement this interface
 * and are registered in NativeRendererRegistry.
 */
fun interface NodeRenderer {
    @Composable
    fun Render(node: NativeUINode, modifier: Modifier)
}

/**
 * Recursively renders a NativeUINode using registered composable renderers.
 * Plugin renderers (Card, Carousel, …) use this to draw child nodes.
 *
 * The optional [modifier] is forwarded as `NodeView`'s `overrideModifier`,
 * which represents *parent-supplied* layout extras (clip, weight, margin, …)
 * that wrap the child. Pass `null` (default) for "no extras" — `NodeView`
 * will then derive sizing from `node.layout` directly. When a non-null
 * modifier is provided, `NodeView` skips deriving sizing from `node.layout`,
 * since the parent is assumed to be controlling size (e.g. a flex cell or
 * a carousel item slot).
 *
 * Do **not** include the child's own style/layout in this modifier —
 * `NodeView` chains `nodeStyle`/`nodeLayout`/`nodeGestures` after the
 * override, so passing duplicates double-applies padding, background, and
 * border.
 */
@Composable
fun RenderNode(node: NativeUINode, modifier: Modifier? = null) {
    NodeView(node, overrideModifier = modifier)
}

/**
 * Builds a Modifier from a node's style and layout properties.
 * Compatibility wrapper for existing plugin renderers.
 */
fun buildModifier(node: NativeUINode): Modifier {
    var mod: Modifier = Modifier

    node.layout?.let { layout ->
        when (layout.widthMode) {
            SizeMode.FIXED -> if (layout.width > 0f) mod = mod.width(layout.width.dp)
            SizeMode.FILL -> mod = mod.fillMaxWidth()
        }
        when (layout.heightMode) {
            SizeMode.FIXED -> if (layout.height > 0f) mod = mod.height(layout.height.dp)
            SizeMode.FILL -> mod = mod.fillMaxHeight()
        }
        if (layout.paddingTop > 0f || layout.paddingRight > 0f || layout.paddingBottom > 0f || layout.paddingLeft > 0f) {
            mod = mod.padding(
                start = layout.paddingLeft.dp,
                top = layout.paddingTop.dp,
                end = layout.paddingRight.dp,
                bottom = layout.paddingBottom.dp
            )
        }
    }

    node.style?.let { style ->
        if (style.opacity < 1f && style.opacity >= 0f) {
            mod = mod.alpha(style.opacity)
        }
        val shape = if (style.borderRadius > 0f) RoundedCornerShape(style.borderRadius.dp) else RoundedCornerShape(0.dp)
        if (style.bgColor != 0) {
            mod = mod.background(Color(style.bgColor), shape)
        }
        if (style.borderWidth > 0f && style.borderColor != 0) {
            mod = mod.border(style.borderWidth.dp, Color(style.borderColor), shape)
        }
    }

    return mod
}
