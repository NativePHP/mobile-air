package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.height
import androidx.compose.runtime.Composable
import androidx.compose.runtime.key
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

/**
 * Recursive composable that renders a NativeUINode and its children.
 * Dispatches to registered renderers via NativeRendererRegistry.
 * Falls back to a flex container for unknown types.
 *
 * @param overrideModifier Optional modifier from parent FlexContainer that includes
 *   flex-specific properties (weight, margin). When provided, it's prepended to
 *   the node's own layout/style/gesture modifiers.
 */
@Composable
fun NodeView(node: NativeUINode, overrideModifier: Modifier? = null) {
    key(node.id) {
        val renderer = NativeRendererRegistry.get(node.type)
        val isDarkMode = isSystemInDarkTheme()
        val safeAreaTop = LocalSafeAreaTop.current
        val safeAreaBottom = LocalSafeAreaBottom.current
        val availableWidth = LocalAvailableWidth.current
        val availableHeight = LocalAvailableHeight.current

        // Start with flex modifier from parent (weight, margin), then add own modifiers.
        // Order: margin/weight → style (background/border) → layout (padding) → gestures
        // Background must come BEFORE padding so it covers the padded area (CSS box model).
        val base = overrideModifier ?: run {
            // No parent FlexContainer — apply size from node layout directly
            var mod: Modifier = Modifier
            val layout = node.layout
            if (layout != null) {
                when (layout.widthMode) {
                    SizeMode.FILL -> mod = mod.fillMaxWidth()
                    SizeMode.FIXED -> if (layout.width > 0f) mod = mod.width(layout.width.dp)
                    SizeMode.PERCENT -> if (layout.width > 0f) {
                        mod = mod.fillMaxWidth((layout.width / 100f).coerceIn(0f, 1f))
                    }
                }
                when (layout.heightMode) {
                    SizeMode.FILL -> mod = mod.fillMaxHeight()
                    SizeMode.FIXED -> if (layout.height > 0f) mod = mod.height(layout.height.dp)
                    SizeMode.PERCENT -> if (layout.height > 0f) {
                        mod = mod.fillMaxHeight((layout.height / 100f).coerceIn(0f, 1f))
                    }
                }
            }
            mod
        }
        val modifier = base
            .nodeStyle(node.style, node.props, isDarkMode)
            .nodeLayout(node.layout, safeAreaTop, safeAreaBottom, availableWidth, availableHeight)
            .nodeGestures(node)

        if (renderer != null) {
            renderer.Render(node, modifier)
        } else {
            DefaultContainerNode(node, modifier)
        }
    }
}

/**
 * Default container renderer — renders children using Compose Column/Row.
 * Used as fallback for unknown types and by container renderers.
 */
@Composable
fun DefaultContainerNode(node: NativeUINode, modifier: Modifier = Modifier) {
    if (node.children.isEmpty()) {
        Box(modifier = modifier)
    } else {
        val direction = when (node.type) {
            "row" -> FlexDirection.ROW
            else -> node.layout?.flexDirection ?: FlexDirection.COLUMN
        }

        FlexContainer(
            direction = direction,
            justify = node.layout?.justifyContent ?: JustifyContent.START,
            align = node.layout?.alignItems ?: AlignItems.STRETCH,
            gap = node.layout?.gap ?: 0f,
            childNodes = node.children,
            modifier = modifier
        ) {
            // Content is rendered by FlexContainer itself via NodeView calls
        }
    }
}
