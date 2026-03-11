package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.layout.Box
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

@Composable
fun RenderPressable(node: NativeUINode, modifier: Modifier) {
    // Click handling is done by buildModifier() (after all styling/sizing)
    Box(modifier = modifier) {
        for (child in node.children) {
            RenderNode(child)
        }
    }
}
