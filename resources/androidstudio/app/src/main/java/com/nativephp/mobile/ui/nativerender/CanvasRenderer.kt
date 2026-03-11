package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.offset
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.unit.dp

@Composable
fun RenderCanvas(node: NativeUINode, modifier: Modifier) {
    // Canvas is a bounded drawing surface — always clips children to its bounds.
    // Use Stack instead if you need layered positioning without clipping.
    Box(modifier = modifier.clipToBounds()) {
        for (child in node.children) {
            val left = child.props.getFloat("left", 0f)
            val top = child.props.getFloat("top", 0f)

            val childMod = buildStyleModifier(child)

            // offset must be outermost so it moves the entire styled child
            val offsetMod = if (left != 0f || top != 0f) {
                Modifier.offset(x = left.dp, y = top.dp).then(childMod)
            } else {
                childMod
            }

            val renderer = NativeRendererRegistry.get(child.type)
            if (renderer != null) {
                renderer.Render(child, offsetMod)
            }
        }
    }
}
