package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.sp

@Composable
fun RenderText(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val text = p.getString("text")
    if (text.isEmpty()) return

    Text(
        text = text,
        modifier = modifier.then(applyTextClickModifier(node)),
        color = Color(p.getColor("color", 0xFF000000.toInt())),
        fontSize = p.getFloat("font_size", 16f).sp,
        fontWeight = resolveTextFontWeight(p.getInt("font_weight")),
        textAlign = resolveTextAlignment(p.getInt("text_align")),
        maxLines = p.getInt("max_lines").let { if (it > 0) it else Int.MAX_VALUE },
        overflow = TextOverflow.Ellipsis
    )
}

private fun applyTextClickModifier(node: NativeUINode): Modifier {
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

private fun resolveTextFontWeight(weight: Int): FontWeight {
    return when (weight) {
        1 -> FontWeight.Thin
        2 -> FontWeight.Light
        3 -> FontWeight.Normal
        4 -> FontWeight.Medium
        5 -> FontWeight.SemiBold
        6 -> FontWeight.Bold
        7 -> FontWeight.ExtraBold
        else -> FontWeight.Normal
    }
}

private fun resolveTextAlignment(align: Int): TextAlign {
    return when (align) {
        0 -> TextAlign.Start
        1 -> TextAlign.Center
        2 -> TextAlign.End
        else -> TextAlign.Start
    }
}
