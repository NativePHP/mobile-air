package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.Canvas
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color

@Composable
fun RenderLine(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val fromX = p.getFloat("from_x", 0f)
    val fromY = p.getFloat("from_y", 0f)
    val toX = p.getFloat("to_x", 0f)
    val toY = p.getFloat("to_y", 0f)

    val style = node.style
    val strokeColor = if (style != null && style.borderColor != 0) {
        Color(style.borderColor)
    } else {
        Color.Black
    }
    val strokeWidth = if (style != null && style.borderWidth > 0) {
        style.borderWidth
    } else {
        1f
    }

    Canvas(modifier = modifier) {
        drawLine(
            color = strokeColor,
            start = Offset(fromX * density, fromY * density),
            end = Offset(toX * density, toY * density),
            strokeWidth = strokeWidth * density
        )
    }
}
