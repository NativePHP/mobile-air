package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

@Composable
fun RenderSpacer(node: NativeUINode, modifier: Modifier) {
    val layout = node.layout
    if (layout != null && layout.widthMode == SizeMode.FIXED && layout.width > 0) {
        Spacer(modifier = modifier.width(layout.width.dp))
    } else if (layout != null && layout.heightMode == SizeMode.FIXED && layout.height > 0) {
        Spacer(modifier = modifier.height(layout.height.dp))
    } else {
        Spacer(modifier = modifier.fillMaxWidth().height(0.dp))
    }
}
