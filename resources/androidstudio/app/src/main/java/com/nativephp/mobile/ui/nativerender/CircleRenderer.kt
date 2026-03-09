package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.offset
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

@Composable
fun RenderCircle(node: NativeUINode, modifier: Modifier) {
    val left = node.props.getFloat("left", 0f)
    val top = node.props.getFloat("top", 0f)

    // offset must be outermost so it moves the entire styled box
    // borderRadius=9999 is forced from PHP, so buildModifier already
    // clipped with RoundedCornerShape(9999.dp) — effectively a circle.
    val finalMod = if (left != 0f || top != 0f) {
        Modifier.offset(x = left.dp, y = top.dp).then(modifier)
    } else {
        modifier
    }

    Box(modifier = finalMod)
}
