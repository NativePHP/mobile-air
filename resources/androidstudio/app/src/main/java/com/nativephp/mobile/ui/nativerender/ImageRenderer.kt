package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Box
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ColorFilter
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil3.compose.SubcomposeAsyncImage

@Composable
fun RenderImage(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val src = p.getString("src")
    val fit = p.getInt("fit")
    val tintColor = p.getColor("tint_color", 0)

    val contentScale = when (fit) {
        0 -> ContentScale.None
        1 -> ContentScale.Fit
        2 -> ContentScale.Crop
        3 -> ContentScale.FillBounds
        4 -> ContentScale.Inside
        else -> ContentScale.Fit
    }

    val colorFilter = if (tintColor != 0) ColorFilter.tint(Color(tintColor)) else null

    SubcomposeAsyncImage(
        model = src,
        contentDescription = null,
        modifier = modifier.then(applyImageClickModifier(node)),
        contentScale = contentScale,
        colorFilter = colorFilter,
        loading = {
            Box(
                modifier = Modifier.background(Color(0xFFE0E0E0)),
                contentAlignment = Alignment.Center
            ) {
                CircularProgressIndicator(
                    modifier = Modifier,
                    strokeWidth = 2.dp,
                    color = Color.Gray
                )
            }
        },
        error = {
            Box(
                modifier = Modifier.background(Color(0xFFE0E0E0)),
                contentAlignment = Alignment.Center
            ) {
                Text(text = src.takeLast(20), fontSize = 10.sp, color = Color.Gray)
            }
        }
    )
}

private fun applyImageClickModifier(node: NativeUINode): Modifier {
    val pressCb = node.onPress
    val longPressCb = node.onLongPress

    if (pressCb == 0 && longPressCb == 0) return Modifier

    return if (longPressCb != 0) {
        Modifier.pointerInput(pressCb, longPressCb) {
            detectTapGestures(
                onTap = {
                    if (pressCb != 0) {
                        NativeUIBridge.sendPressEvent(pressCb, node.id)
                    }
                },
                onLongPress = {
                    NativeUIBridge.sendLongPressEvent(longPressCb, node.id)
                }
            )
        }
    } else {
        Modifier.clickable { NativeUIBridge.sendPressEvent(pressCb, node.id) }
    }
}
