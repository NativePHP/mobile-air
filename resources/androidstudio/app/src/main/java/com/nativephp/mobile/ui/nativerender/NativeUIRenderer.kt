package com.nativephp.mobile.ui.nativerender

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.EnterTransition
import androidx.compose.animation.ExitTransition
import androidx.compose.animation.SizeTransform
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.systemBars
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.layout.ime
import androidx.compose.foundation.layout.union
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.horizontalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.withFrameNanos
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
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.size
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/**
 * Root composable that renders a NativeUITree.
 * Wraps content in AnimatedContent keyed on screenKey for navigation transitions.
 */
@Composable
fun NativeUIContent() {
    val tree by NativeUIBridge.currentTree
    val screenKey by NativeUIBridge.screenKey
    val focusManager = androidx.compose.ui.platform.LocalFocusManager.current
    val keyboardController = androidx.compose.ui.platform.LocalSoftwareKeyboardController.current

    val treeSnapshots = remember { mutableMapOf<Int, NativeUITree>() }
    if (tree != null) {
        treeSnapshots[screenKey] = tree!!
    }
    treeSnapshots.keys.filter { it < screenKey - 1 }.toList().forEach { treeSnapshots.remove(it) }

    LaunchedEffect(tree) {
        if (tree != null && PerformanceTracker.enabled) {
            withFrameNanos { _ ->
                PerformanceTracker.onFrameDrawn()
            }
        }
    }

    android.util.Log.d("NativeUIContent", "recompose: screenKey=$screenKey tree=${if (tree != null) "root=${tree!!.root.type} children=${tree!!.root.children.size}" else "null"} snapshots=${treeSnapshots.keys}")

    if (treeSnapshots.isEmpty()) return

    val transition = NativeUIBridge.pendingTransition.value ?: "slide_from_right"

    val renderSnapshot: @Composable (NativeUITree) -> Unit = { snap ->
        Box(
            modifier = Modifier
                .fillMaxSize()
                .clickable(
                    interactionSource = remember { androidx.compose.foundation.interaction.MutableInteractionSource() },
                    indication = null
                ) {
                    focusManager.clearFocus()
                    keyboardController?.hide()
                }
        ) {
            RenderNode(snap.root)
        }
    }

    if (transition == "none") {
        val snap = treeSnapshots[screenKey]
        if (snap != null) {
            renderSnapshot(snap)
        }
    } else {
        AnimatedContent(
            targetState = screenKey,
            transitionSpec = {
                val ct = resolveTransition(transition)
                ct.using(SizeTransform(clip = false))
            },
            label = "screen-nav"
        ) { targetKey ->
            val snap = treeSnapshots[targetKey]
            if (snap != null) {
                SideEffect {
                    android.util.Log.d("NativeUIPerf", "AnimatedContent composed screenKey=$targetKey root=${snap.root.type} children=${snap.root.children.size}")
                }
                renderSnapshot(snap)
            }
        }
    }
}

/**
 * Map transition type to Compose animations.
 */
private fun resolveTransition(
    type: String
): androidx.compose.animation.ContentTransform {
    val duration = 300

    return when (type) {
        "slide_from_right" -> {
            slideInHorizontally(tween(duration)) { it } +
                fadeIn(tween(duration / 2)) togetherWith
                slideOutHorizontally(tween(duration)) { -it / 3 } +
                fadeOut(tween(duration / 2))
        }
        "slide_from_left" -> {
            slideInHorizontally(tween(duration)) { -it } +
                fadeIn(tween(duration / 2)) togetherWith
                slideOutHorizontally(tween(duration)) { it / 3 } +
                fadeOut(tween(duration / 2))
        }
        "slide_from_bottom" -> {
            slideInVertically(tween(duration)) { it } +
                fadeIn(tween(duration / 2)) togetherWith
                fadeOut(tween(duration / 2))
        }
        "fade" -> {
            fadeIn(tween(duration)) togetherWith fadeOut(tween(duration))
        }
        "fade_from_bottom" -> {
            slideInVertically(tween(duration)) { it / 4 } +
                fadeIn(tween(duration)) togetherWith
                fadeOut(tween(duration / 2))
        }
        "scale_from_center" -> {
            scaleIn(tween(duration), initialScale = 0.9f) +
                fadeIn(tween(duration)) togetherWith
                scaleOut(tween(duration), targetScale = 1.1f) +
                fadeOut(tween(duration / 2))
        }
        "none" -> {
            EnterTransition.None togetherWith ExitTransition.None
        }
        "slide_forward" -> resolveTransition("slide_from_right")
        "slide_back" -> resolveTransition("slide_from_left")
        "crossfade" -> resolveTransition("fade")
        else -> fadeIn(tween(duration)) togetherWith fadeOut(tween(duration))
    }
}

/* ── Node Renderer (Yoga absolute positioning) ─────────── */

/**
 * Every node is a Box sized by Yoga's computed layout.
 * Children are positioned absolutely using .offset(x, y).
 * No Column/Row — Yoga handles all layout (padding, margin, gap, alignment).
 */
@Composable
fun RenderNode(node: NativeUINode) {
    val modifier = buildStyleModifier(node)

    // Check for special container types that need custom rendering
    val renderer = NativeRendererRegistry.get(node.type)
    if (renderer != null && (node.type == "scroll_view" || node.type == "bottom_sheet")) {
        // Special containers handle their own children
        renderer.Render(node, modifier)
    } else {
        // Universal: styled Box with leaf content + absolutely positioned children
        Box(modifier = modifier, contentAlignment = Alignment.TopStart) {
            // Leaf content from registry — fillMaxSize so it fills the Yoga-sized parent
            if (node.children.isEmpty() && renderer != null) {
                renderer.Render(node, Modifier.fillMaxSize())
            }

            // Children at Yoga-computed positions
            node.children.forEach { child ->
                key(child.id) {
                    val cc = child.computed
                    if (cc != null) {
                        Box(modifier = Modifier.offset(x = cc.x.dp, y = cc.y.dp)) {
                            RenderNode(child)
                        }
                    }
                }
            }
        }
    }
}

/* ── Style Modifier (size + style — NO padding/margin) ── */

/**
 * Applies Yoga-computed size and visual style.
 * No padding/margin — Yoga positions children accounting for those.
 */
@Composable
internal fun buildStyleModifier(node: NativeUINode): Modifier {
    var mod: Modifier = Modifier

    val style = node.style
    val computed = node.computed

    // 1. Yoga-computed size
    if (computed != null) {
        val w = computed.width
        val h = computed.height
        if (w > 0f && h > 0f) {
            mod = mod.width(w.dp).height(h.dp)
        } else if (w > 0f) {
            mod = mod.width(w.dp)
        } else if (h > 0f) {
            mod = mod.height(h.dp)
        }
    }

    // 2. Safe area insets
    if (node.layout != null && node.layout.safeArea != 0) {
        mod = mod.windowInsetsPadding(WindowInsets.systemBars.union(WindowInsets.ime))
    }

    // 3. Style
    if (style != null) {
        if (style.elevation > 0) {
            val shape = if (style.borderRadius > 0) RoundedCornerShape(style.borderRadius.dp) else RoundedCornerShape(0.dp)
            mod = mod.shadow(style.elevation.dp, shape)
        }

        if (style.borderRadius > 0) {
            mod = mod.clip(RoundedCornerShape(style.borderRadius.dp))
        }

        val bgColor = argbToColor(style.bgColor)
        if (bgColor != Color.Transparent) {
            mod = mod.background(bgColor)
        }

        if (style.borderWidth > 0 && node.type != "line") {
            val shape = if (style.borderRadius > 0) RoundedCornerShape(style.borderRadius.dp) else RoundedCornerShape(0.dp)
            mod = mod.border(style.borderWidth.dp, argbToColor(style.borderColor), shape)
        }

        if (style.opacity < 1f) {
            mod = mod.alpha(style.opacity)
        }
    }

    // 4. Click handlers
    mod = mod.then(applyClickModifier(node))

    return mod
}

private fun applyClickModifier(node: NativeUINode): Modifier {
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
        Modifier.clickable {
            NativeUIBridge.sendPressEvent(pressCb, node.id)
        }
    }
}

/* ── Scroll View (absolute positioning) ────────────────── */

@Composable
internal fun RenderScrollView(node: NativeUINode, modifier: Modifier) {
    val horizontal = node.props.getBool("horizontal")

    // Compute content size from children's extents
    var contentW = 0f
    var contentH = 0f
    for (child in node.children) {
        child.computed?.let { c ->
            contentW = maxOf(contentW, c.x + c.width)
            contentH = maxOf(contentH, c.y + c.height)
        }
    }

    if (horizontal) {
        val scrollState = rememberScrollState()
        Box(modifier = modifier.horizontalScroll(scrollState)) {
            Box(modifier = Modifier.width(contentW.dp).height(contentH.dp)) {
                node.children.forEach { child ->
                    key(child.id) {
                        val cc = child.computed
                        if (cc != null) {
                            Box(modifier = Modifier.offset(x = cc.x.dp, y = cc.y.dp)) {
                                RenderNode(child)
                            }
                        }
                    }
                }
            }
        }
    } else {
        val scrollState = rememberScrollState()
        Box(modifier = modifier.verticalScroll(scrollState)) {
            Box(modifier = Modifier.width(contentW.dp).height(contentH.dp)) {
                node.children.forEach { child ->
                    key(child.id) {
                        val cc = child.computed
                        if (cc != null) {
                            Box(modifier = Modifier.offset(x = cc.x.dp, y = cc.y.dp)) {
                                RenderNode(child)
                            }
                        }
                    }
                }
            }
        }
    }
}

/* ── Leaf Renderers ──────────────────────────────────────── */

@Composable
internal fun RenderButton(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val label = p.getString("label")
    val labelColor = p.getColor("label_color", 0)
    val fontSize = p.getFloat("font_size", 0f)
    val disabled = p.getBool("disabled")

    Box(
        modifier = modifier.then(if (disabled) Modifier.alpha(0.5f) else Modifier),
        contentAlignment = Alignment.Center
    ) {
        Text(
            text = label,
            color = if (labelColor != 0) argbToColor(labelColor) else Color.Unspecified,
            fontWeight = FontWeight.Medium,
            fontSize = if (fontSize > 0f) fontSize.sp else 16.sp
        )
    }
}

@Composable
internal fun RenderTextInput(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val initialValue = p.getString("value")
    val placeholder = p.getString("placeholder")
    val onChangeCb = p.getCallbackId("on_change")
    val onSubmitCb = p.getCallbackId("on_submit")
    val secure = p.getBool("secure")
    val multiline = p.getBool("multiline")

    var text by remember(node.id, initialValue) { mutableStateOf(initialValue) }

    OutlinedTextField(
        value = text,
        onValueChange = { newValue ->
            text = newValue
            if (onChangeCb != 0) {
                NativeUIBridge.sendTextChangeEvent(onChangeCb, node.id, newValue)
            }
        },
        modifier = modifier,
        placeholder = { Text(placeholder) },
        singleLine = !multiline,
        visualTransformation = if (secure) PasswordVisualTransformation() else VisualTransformation.None,
        keyboardOptions = KeyboardOptions(
            imeAction = if (onSubmitCb != 0) ImeAction.Done else ImeAction.Default
        ),
        keyboardActions = KeyboardActions(
            onDone = {
                if (onSubmitCb != 0) {
                    NativeUIBridge.sendSubmitEvent(onSubmitCb, node.id, text)
                }
            }
        )
    )
}

@Composable
internal fun RenderToggle(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val initialValue = p.getBool("value")
    val onChangeCb = p.getCallbackId("on_change")
    val disabled = p.getBool("disabled")
    val label = p.getString("label")

    var checked by remember(node.id, initialValue) { mutableStateOf(initialValue) }

    if (label.isNotEmpty()) {
        Row(
            modifier = modifier,
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween
        ) {
            Text(text = label, modifier = Modifier.weight(1f))
            Switch(
                checked = checked,
                onCheckedChange = { newValue ->
                    checked = newValue
                    if (onChangeCb != 0) {
                        NativeUIBridge.sendToggleChangeEvent(onChangeCb, node.id, newValue)
                    }
                },
                enabled = !disabled
            )
        }
    } else {
        Switch(
            checked = checked,
            onCheckedChange = { newValue ->
                checked = newValue
                if (onChangeCb != 0) {
                    NativeUIBridge.sendToggleChangeEvent(onChangeCb, node.id, newValue)
                }
            },
            modifier = modifier,
            enabled = !disabled
        )
    }
}

@Composable
internal fun RenderIcon(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val name = p.getString("name")
    val color = p.getColor("color", 0)

    com.nativephp.mobile.ui.MaterialIcon(
        name = name,
        contentDescription = name,
        modifier = modifier,
        size = p.getFloat("size", 24f).dp,
        tint = if (color != 0) argbToColor(color) else Color.Unspecified
    )
}

@Composable
internal fun RenderActivityIndicator(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val sizeDp = when (p.getInt("size")) {
        1 -> 48.dp  // large
        2 -> 20.dp  // small
        else -> 32.dp
    }
    val color = p.getColor("color", 0)

    if (color != 0) {
        CircularProgressIndicator(
            modifier = modifier.size(sizeDp),
            color = argbToColor(color)
        )
    } else {
        CircularProgressIndicator(
            modifier = modifier.size(sizeDp)
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun RenderBottomSheet(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val visible = p.getBool("visible")
    val onDismissCb = p.getCallbackId("on_dismiss")

    if (!visible) return

    val sheetState = rememberModalBottomSheetState()

    ModalBottomSheet(
        onDismissRequest = {
            if (onDismissCb != 0) {
                NativeUIBridge.sendSheetDismissEvent(onDismissCb, node.id)
            }
        },
        sheetState = sheetState
    ) {
        Box(modifier = modifier) {
            node.children.forEach { child ->
                key(child.id) {
                    val cc = child.computed
                    if (cc != null) {
                        Box(modifier = Modifier.offset(x = cc.x.dp, y = cc.y.dp)) {
                            RenderNode(child)
                        }
                    }
                }
            }
        }
    }
}

/* ── Color Helpers ────────────────────────────────────────── */

private fun argbToColor(argb: Int): Color {
    return Color(argb)
}

private fun hasEdges(top: Float, right: Float, bottom: Float, left: Float): Boolean {
    return top > 0 || right > 0 || bottom > 0 || left > 0
}
