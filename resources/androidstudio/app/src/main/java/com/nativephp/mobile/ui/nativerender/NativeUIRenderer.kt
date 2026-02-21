package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.wrapContentHeight
import androidx.compose.foundation.layout.wrapContentWidth
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextField
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/**
 * Root composable that renders a NativeUITree.
 * Call this from your Compose hierarchy when NativeUIBridge.currentTree is non-null.
 */
@Composable
fun NativeUIContent() {
    val tree by NativeUIBridge.currentTree
    tree?.let { RenderNode(it.root) }
}

/**
 * Recursively render a NativeUINode as Compose UI.
 */
@Composable
fun RenderNode(node: NativeUINode) {
    val modifier = buildModifier(node)

    when (node.type) {
        NodeType.COLUMN -> RenderColumn(node, modifier)
        NodeType.ROW -> RenderRow(node, modifier)
        NodeType.STACK -> RenderStack(node, modifier)
        NodeType.SCROLLVIEW -> RenderScrollView(node, modifier)
        NodeType.TEXT -> RenderText(node, modifier)
        NodeType.IMAGE -> RenderImage(node, modifier)
        NodeType.BUTTON -> RenderButton(node, modifier)
        NodeType.TEXTINPUT -> RenderTextInput(node, modifier)
        NodeType.TOGGLE -> RenderToggle(node, modifier)
        NodeType.SPACER -> RenderSpacer(node, modifier)
        NodeType.DIVIDER -> HorizontalDivider(modifier = modifier)
        else -> {
            // Unknown type — render children in a column as fallback
            Column(modifier = modifier) {
                node.children.forEach { RenderNode(it) }
            }
        }
    }
}

/* ── Container Nodes ──────────────────────────────────── */

@Composable
private fun RenderColumn(node: NativeUINode, modifier: Modifier) {
    val layout = node.layout
    val vArrangement = resolveVerticalArrangement(layout?.justifyContent ?: 0, layout?.gap ?: 0f)
    val hAlignment = resolveHorizontalAlignment(layout?.alignItems ?: 0)

    Column(
        modifier = modifier,
        verticalArrangement = vArrangement,
        horizontalAlignment = hAlignment
    ) {
        node.children.forEach { RenderNode(it) }
    }
}

@Composable
private fun RenderRow(node: NativeUINode, modifier: Modifier) {
    val layout = node.layout
    val hArrangement = resolveHorizontalArrangement(layout?.justifyContent ?: 0, layout?.gap ?: 0f)
    val vAlignment = resolveVerticalAlignment(layout?.alignItems ?: 0)

    Row(
        modifier = modifier,
        horizontalArrangement = hArrangement,
        verticalAlignment = vAlignment
    ) {
        node.children.forEach { RenderNode(it) }
    }
}

@Composable
private fun RenderStack(node: NativeUINode, modifier: Modifier) {
    Box(modifier = modifier) {
        node.children.forEach { RenderNode(it) }
    }
}

@Composable
private fun RenderScrollView(node: NativeUINode, modifier: Modifier) {
    val props = node.props as? NodeProps.ScrollView
    val scrollState = rememberScrollState()

    if (props?.horizontal == true) {
        Row(modifier = modifier.horizontalScroll(scrollState)) {
            node.children.forEach { RenderNode(it) }
        }
    } else {
        Column(modifier = modifier.verticalScroll(scrollState)) {
            node.children.forEach { RenderNode(it) }
        }
    }
}

/* ── Leaf Nodes ───────────────────────────────────────── */

@Composable
private fun RenderText(node: NativeUINode, modifier: Modifier) {
    val props = node.props as? NodeProps.Text ?: return

    Text(
        text = props.text,
        modifier = modifier.then(applyClickModifier(node)),
        color = argbToColor(props.color),
        fontSize = props.fontSize.sp,
        fontWeight = resolveFontWeight(props.fontWeight),
        textAlign = resolveTextAlign(props.textAlign),
        maxLines = if (props.maxLines > 0) props.maxLines else Int.MAX_VALUE,
        overflow = TextOverflow.Ellipsis
    )
}

@Composable
private fun RenderImage(node: NativeUINode, modifier: Modifier) {
    // Image loading from URLs/assets requires an image library (Coil, Glide).
    // For the prototype, render a placeholder box with the source text.
    val props = node.props as? NodeProps.Image ?: return

    Box(
        modifier = modifier
            .then(applyClickModifier(node))
            .background(Color(0xFFE0E0E0)),
        contentAlignment = Alignment.Center
    ) {
        Text(
            text = props.src.takeLast(20),
            fontSize = 10.sp,
            color = Color.Gray
        )
    }
}

@Composable
private fun RenderButton(node: NativeUINode, modifier: Modifier) {
    val props = node.props as? NodeProps.Button ?: return

    Button(
        onClick = {
            val cbId = if (props.onPress != 0) props.onPress else node.onPress
            if (cbId != 0) {
                NativeUIBridge.sendPressEvent(cbId, node.id)
            }
        },
        modifier = modifier,
        enabled = !props.disabled,
        colors = ButtonDefaults.buttonColors(
            containerColor = argbToColor(props.color),
            contentColor = argbToColor(props.labelColor)
        )
    ) {
        Text(text = props.label)
    }
}

@Composable
private fun RenderTextInput(node: NativeUINode, modifier: Modifier) {
    val props = node.props as? NodeProps.TextInput ?: return
    var text by remember(node.id, props.value) { mutableStateOf(props.value) }

    TextField(
        value = text,
        onValueChange = { newValue ->
            text = newValue
            if (props.onChange != 0) {
                NativeUIBridge.sendTextChangeEvent(props.onChange, node.id, newValue)
            }
        },
        modifier = modifier,
        placeholder = { Text(props.placeholder) },
        singleLine = !props.multiline,
        visualTransformation = if (props.secure) PasswordVisualTransformation() else VisualTransformation.None,
        keyboardOptions = KeyboardOptions(
            imeAction = if (props.onSubmit != 0) ImeAction.Done else ImeAction.Default
        ),
        keyboardActions = KeyboardActions(
            onDone = {
                if (props.onSubmit != 0) {
                    NativeUIBridge.sendSubmitEvent(props.onSubmit, node.id, text)
                }
            }
        )
    )
}

@Composable
private fun RenderToggle(node: NativeUINode, modifier: Modifier) {
    val props = node.props as? NodeProps.Toggle ?: return
    var checked by remember(node.id, props.value) { mutableStateOf(props.value) }

    Switch(
        checked = checked,
        onCheckedChange = { newValue ->
            checked = newValue
            if (props.onChange != 0) {
                NativeUIBridge.sendToggleChangeEvent(props.onChange, node.id, newValue)
            }
        },
        modifier = modifier,
        enabled = !props.disabled
    )
}

@Composable
private fun RenderSpacer(node: NativeUINode, modifier: Modifier) {
    val layout = node.layout
    if (layout != null && layout.widthMode == SizeMode.FIXED && layout.width > 0) {
        Spacer(modifier = modifier.width(layout.width.dp))
    } else if (layout != null && layout.heightMode == SizeMode.FIXED && layout.height > 0) {
        Spacer(modifier = modifier.height(layout.height.dp))
    } else {
        // Default spacer with minimum size (weight requires RowScope/ColumnScope)
        Spacer(modifier = modifier.fillMaxWidth().height(0.dp))
    }
}

/* ── Modifier Building ────────────────────────────────── */

@Composable
private fun buildModifier(node: NativeUINode): Modifier {
    var mod: Modifier = Modifier

    val layout = node.layout
    val style = node.style

    // Size
    if (layout != null) {
        mod = when (layout.widthMode) {
            SizeMode.FILL -> mod.fillMaxWidth()
            SizeMode.FIXED -> if (layout.width > 0) mod.width(layout.width.dp) else mod.wrapContentWidth()
            SizeMode.PERCENT -> mod.fillMaxWidth(layout.width.coerceIn(0f, 1f))
            else -> mod.wrapContentWidth()
        }
        mod = when (layout.heightMode) {
            SizeMode.FILL -> mod.fillMaxHeight()
            SizeMode.FIXED -> if (layout.height > 0) mod.height(layout.height.dp) else mod.wrapContentHeight()
            SizeMode.PERCENT -> mod.fillMaxHeight(layout.height.coerceIn(0f, 1f))
            else -> mod.wrapContentHeight()
        }
    }

    // Margin (applied as padding on outer modifier)
    if (layout != null && hasEdges(layout.marginTop, layout.marginRight, layout.marginBottom, layout.marginLeft)) {
        mod = mod.padding(
            start = layout.marginLeft.dp,
            top = layout.marginTop.dp,
            end = layout.marginRight.dp,
            bottom = layout.marginBottom.dp
        )
    }

    // Style
    if (style != null) {
        // Elevation / shadow
        if (style.elevation > 0) {
            val shape = if (style.borderRadius > 0) RoundedCornerShape(style.borderRadius.dp) else RoundedCornerShape(0.dp)
            mod = mod.shadow(style.elevation.dp, shape)
        }

        // Border radius + clipping
        if (style.borderRadius > 0) {
            mod = mod.clip(RoundedCornerShape(style.borderRadius.dp))
        }

        // Background
        val bgColor = argbToColor(style.bgColor)
        if (bgColor != Color.Transparent) {
            mod = mod.background(bgColor)
        }

        // Border
        if (style.borderWidth > 0) {
            val shape = if (style.borderRadius > 0) RoundedCornerShape(style.borderRadius.dp) else RoundedCornerShape(0.dp)
            mod = mod.border(style.borderWidth.dp, argbToColor(style.borderColor), shape)
        }

        // Opacity
        if (style.opacity < 1f) {
            mod = mod.alpha(style.opacity)
        }
    }

    // Padding (inner)
    if (layout != null && hasEdges(layout.paddingTop, layout.paddingRight, layout.paddingBottom, layout.paddingLeft)) {
        mod = mod.padding(
            start = layout.paddingLeft.dp,
            top = layout.paddingTop.dp,
            end = layout.paddingRight.dp,
            bottom = layout.paddingBottom.dp
        )
    }

    return mod
}

private fun applyClickModifier(node: NativeUINode): Modifier {
    return if (node.onPress != 0) {
        Modifier.clickable {
            NativeUIBridge.sendPressEvent(node.onPress, node.id)
        }
    } else {
        Modifier
    }
}

/* ── Alignment / Arrangement Helpers ──────────────────── */

/**
 * justify_content values: 0=start, 1=center, 2=end, 3=space_between, 4=space_around, 5=space_evenly
 */
private fun resolveVerticalArrangement(justifyContent: Int, gap: Float): Arrangement.Vertical {
    if (gap > 0 && justifyContent == 0) return Arrangement.spacedBy(gap.dp)
    return when (justifyContent) {
        0 -> if (gap > 0) Arrangement.spacedBy(gap.dp) else Arrangement.Top
        1 -> Arrangement.Center
        2 -> Arrangement.Bottom
        3 -> Arrangement.SpaceBetween
        4 -> Arrangement.SpaceAround
        5 -> Arrangement.SpaceEvenly
        else -> Arrangement.Top
    }
}

private fun resolveHorizontalArrangement(justifyContent: Int, gap: Float): Arrangement.Horizontal {
    if (gap > 0 && justifyContent == 0) return Arrangement.spacedBy(gap.dp)
    return when (justifyContent) {
        0 -> if (gap > 0) Arrangement.spacedBy(gap.dp) else Arrangement.Start
        1 -> Arrangement.Center
        2 -> Arrangement.End
        3 -> Arrangement.SpaceBetween
        4 -> Arrangement.SpaceAround
        5 -> Arrangement.SpaceEvenly
        else -> Arrangement.Start
    }
}

/**
 * align_items values: 0=start, 1=center, 2=end, 3=stretch
 */
private fun resolveHorizontalAlignment(alignItems: Int): Alignment.Horizontal {
    return when (alignItems) {
        0 -> Alignment.Start
        1 -> Alignment.CenterHorizontally
        2 -> Alignment.End
        else -> Alignment.Start
    }
}

private fun resolveVerticalAlignment(alignItems: Int): Alignment.Vertical {
    return when (alignItems) {
        0 -> Alignment.Top
        1 -> Alignment.CenterVertically
        2 -> Alignment.Bottom
        else -> Alignment.Top
    }
}

private fun resolveFontWeight(weight: Int): FontWeight {
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

private fun resolveTextAlign(align: Int): TextAlign {
    return when (align) {
        0 -> TextAlign.Start
        1 -> TextAlign.Center
        2 -> TextAlign.End
        else -> TextAlign.Start
    }
}

/* ── Color Helpers ────────────────────────────────────── */

/** Convert ARGB int (0xAARRGGBB) to Compose Color */
private fun argbToColor(argb: Int): Color {
    return Color(argb)
}

private fun hasEdges(top: Float, right: Float, bottom: Float, left: Float): Boolean {
    return top > 0 || right > 0 || bottom > 0 || left > 0
}