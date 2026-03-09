package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Box
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.pointer.pointerInput

@Composable
fun RenderPressable(node: NativeUINode, modifier: Modifier) {
    val pressCb = node.onPress
    val longPressCb = node.onLongPress

    val clickMod = if (pressCb == 0 && longPressCb == 0) {
        Modifier
    } else if (longPressCb != 0) {
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

    Box(modifier = modifier.then(clickMod)) {
        for (child in node.children) {
            RenderNode(child)
        }
    }
}
