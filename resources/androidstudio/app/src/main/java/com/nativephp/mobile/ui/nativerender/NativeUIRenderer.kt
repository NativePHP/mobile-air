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
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.wrapContentHeight
import androidx.compose.foundation.layout.wrapContentWidth
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ElevatedCard
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FilterChipDefaults
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.ListItem
import androidx.compose.material3.ExposedDropdownMenuAnchorType
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedCard
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Slider
import androidx.compose.material3.SliderDefaults
import androidx.compose.material3.Switch
import androidx.compose.material3.PrimaryTabRow
import androidx.compose.material3.Tab
import androidx.compose.material3.Text
import androidx.compose.material3.TextField
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableFloatStateOf
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
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.systemBars
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.layout.ime
import androidx.compose.foundation.layout.union
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.size
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.sp

/**
 * Root composable that renders a NativeUITree.
 * Call this from your Compose hierarchy when NativeUIBridge.currentTree is non-null.
 *
 * Wraps content in AnimatedContent keyed on screenKey so navigation
 * between NativeComponents plays a slide/fade transition.
 * Tree snapshots are kept per screen key so the exit animation
 * shows the OLD screen while the enter animation shows the new one.
 */
@Composable
fun NativeUIContent() {
    val tree by NativeUIBridge.currentTree
    val screenKey by NativeUIBridge.screenKey
    val focusManager = androidx.compose.ui.platform.LocalFocusManager.current
    val keyboardController = androidx.compose.ui.platform.LocalSoftwareKeyboardController.current

    // Snapshot the tree for each screen key so exit animations render the old screen
    val treeSnapshots = remember { mutableMapOf<Int, NativeUITree>() }
    if (tree != null) {
        treeSnapshots[screenKey] = tree!!
    }
    // Keep only current and previous snapshot
    treeSnapshots.keys.filter { it < screenKey - 1 }.toList().forEach { treeSnapshots.remove(it) }

    // Performance tracking: detect when the frame with the new tree actually draws
    LaunchedEffect(tree) {
        if (tree != null && PerformanceTracker.enabled) {
            withFrameNanos { _ ->
                PerformanceTracker.onFrameDrawn()
            }
        }
    }

    android.util.Log.d("NativeUIContent", "recompose: screenKey=$screenKey tree=${if (tree != null) "root=${tree!!.root.type} children=${tree!!.root.children.size}" else "null"} snapshots=${treeSnapshots.keys}")

    if (treeSnapshots.isEmpty()) return

    // Resolve transition
    val transition = NativeUIBridge.pendingTransition.value ?: "slide_from_right"

    // Shared content block — renders a tree snapshot with dismiss-keyboard-on-tap
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
        // Instant swap — skip AnimatedContent entirely to avoid any intermediate frames
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
 * Map a transition type string to Compose enter/exit animations.
 *
 * Supported types (modeled after React Native's stack navigator):
 *   slide_from_right  — iOS-style push (default for navigate)
 *   slide_from_left   — iOS-style pop  (default for back)
 *   slide_from_bottom — modal presentation
 *   fade              — simple crossfade
 *   fade_from_bottom  — Android Oreo-style
 *   scale_from_center — Android 10+ style
 *   none              — instant swap
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

        // Legacy aliases
        "slide_forward" -> resolveTransition("slide_from_right")
        "slide_back" -> resolveTransition("slide_from_left")
        "crossfade" -> resolveTransition("fade")

        else -> fadeIn(tween(duration)) togetherWith fadeOut(tween(duration))
    }
}

/**
 * Recursively render a NativeUINode as Compose UI via the renderer registry.
 */
@Composable
fun RenderNode(node: NativeUINode, prebuiltModifier: Modifier? = null) {
    val modifier = prebuiltModifier ?: buildModifier(node)

    val renderer = NativeRendererRegistry.get(node.type)
    if (renderer != null) {
        renderer.Render(node, modifier)
    } else {
        // Unknown type — render children in a column as fallback
        Column(modifier = modifier) {
            node.children.forEach { key(it.id) { RenderNode(it) } }
        }
    }
}

/* ── Container Nodes ──────────────────────────────────── */

@Composable
internal fun RenderColumn(node: NativeUINode, modifier: Modifier) {
    val layout = node.layout
    val vArrangement = resolveVerticalArrangement(layout?.justifyContent ?: 0, layout?.gap ?: 0f)
    val hAlignment = resolveHorizontalAlignment(layout?.alignItems ?: 0)

    Column(
        modifier = modifier.then(applyClickModifier(node)),
        verticalArrangement = vArrangement,
        horizontalAlignment = hAlignment
    ) {
        node.children.forEach { child ->
            key(child.id) {
                val childMod = buildModifier(child).maybeWeight(child, this)
                RenderNode(child, childMod)
            }
        }
    }
}

@Composable
internal fun RenderRow(node: NativeUINode, modifier: Modifier) {
    val layout = node.layout
    val hArrangement = resolveHorizontalArrangement(layout?.justifyContent ?: 0, layout?.gap ?: 0f)
    val vAlignment = resolveVerticalAlignment(layout?.alignItems ?: 0)

    Row(
        modifier = modifier.then(applyClickModifier(node)),
        horizontalArrangement = hArrangement,
        verticalAlignment = vAlignment
    ) {
        node.children.forEach { child ->
            key(child.id) {
                val childMod = buildModifier(child).maybeWeight(child, this)
                RenderNode(child, childMod)
            }
        }
    }
}

@Composable
internal fun RenderStack(node: NativeUINode, modifier: Modifier) {
    Box(modifier = modifier.then(applyClickModifier(node))) {
        node.children.forEach { key(it.id) { RenderNode(it) } }
    }
}

@Composable
internal fun RenderScrollView(node: NativeUINode, modifier: Modifier) {
    val horizontal = node.props.getBool("horizontal")
    val autoScrollTo = node.props.getInt("auto_scroll_to", -1)

    // Flatten single-wrapper-column pattern: ScrollView > Column > items
    // Without this, LazyColumn has 1 item (the wrapper) and composes everything eagerly
    val sc = remember(node) { flattenScrollContent(node, horizontal) }

    // Apply safe area insets from flattened wrapper (must be in @Composable context)
    val safeAreaMod = if (sc.hasSafeArea) {
        Modifier.windowInsetsPadding(WindowInsets.systemBars.union(WindowInsets.ime))
    } else {
        Modifier
    }

    val listState = rememberLazyListState()

    // Auto-scroll to target item index
    if (autoScrollTo > 0) {
        LaunchedEffect(autoScrollTo) {
            kotlinx.coroutines.delay(300)
            listState.animateScrollToItem(autoScrollTo)
        }
    }

    if (horizontal) {
        LazyRow(
            state = listState,
            modifier = modifier.then(safeAreaMod).then(sc.wrapperModifier),
            horizontalArrangement = if (sc.gap > 0f) Arrangement.spacedBy(sc.gap.dp) else Arrangement.Start,
            contentPadding = sc.contentPadding
        ) {
            items(sc.children, key = { it.id }) { child ->
                RenderNode(child)
            }
        }
    } else {
        LazyColumn(
            state = listState,
            modifier = modifier.then(safeAreaMod).then(sc.wrapperModifier),
            verticalArrangement = if (sc.gap > 0f) Arrangement.spacedBy(sc.gap.dp) else Arrangement.Top,
            horizontalAlignment = sc.horizontalAlignment,
            contentPadding = sc.contentPadding
        ) {
            items(sc.children, key = { it.id }) { child ->
                RenderNode(child)
            }
        }
    }
}

/* ── Leaf Nodes ───────────────────────────────────────── */

@Composable
internal fun RenderButton(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val label = p.getString("label")
    val pressCbId = p.getCallbackId("on_press").let { if (it != 0) it else node.onPress }
    val longPressCbId = node.onLongPress
    val disabled = p.getBool("disabled")
    val color = p.getColor("color", 0xFF6200EE.toInt())
    val labelColor = p.getColor("label_color", 0xFFFFFFFF.toInt())
    val fontSize = p.getFloat("font_size", 0f)

    if (longPressCbId != 0) {
        Box(
            modifier = modifier
                .defaultMinSize(minWidth = 58.dp, minHeight = 40.dp)
                .clip(RoundedCornerShape(20.dp))
                .background(argbToColor(color))
                .pointerInput(pressCbId, longPressCbId) {
                    detectTapGestures(
                        onTap = {
                            if (pressCbId != 0) {
                                NativeUIBridge.sendPressEvent(pressCbId, node.id)
                            }
                        },
                        onLongPress = {
                            NativeUIBridge.sendLongPressEvent(longPressCbId, node.id)
                        }
                    )
                }
                .padding(horizontal = 24.dp),
            contentAlignment = Alignment.Center
        ) {
            Text(
                text = label,
                color = argbToColor(labelColor),
                fontWeight = FontWeight.Medium,
                fontSize = if (fontSize > 0f) fontSize.sp else 14.sp,
                letterSpacing = 0.1.sp
            )
        }
    } else {
        Button(
            onClick = {
                if (pressCbId != 0) {
                    NativeUIBridge.sendPressEvent(pressCbId, node.id)
                }
            },
            modifier = modifier,
            enabled = !disabled,
            colors = ButtonDefaults.buttonColors(
                containerColor = argbToColor(color),
                contentColor = argbToColor(labelColor)
            )
        ) {
            Text(
                text = label,
                fontSize = if (fontSize > 0f) fontSize.sp else TextUnit.Unspecified
            )
        }
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

    TextField(
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

    var checked by remember(node.id, initialValue) { mutableStateOf(initialValue) }

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

/* ── New Element Nodes ───────────────────────────────── */

@Composable
internal fun RenderCheckbox(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val initialValue = p.getBool("value")
    val label = p.getString("label")
    val labelColor = p.getColor("label_color", 0xFF000000.toInt())
    val onChangeCb = p.getCallbackId("on_change")
    val disabled = p.getBool("disabled")

    var checked by remember(node.id, initialValue) { mutableStateOf(initialValue) }

    Row(
        modifier = modifier,
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp)
    ) {
        Checkbox(
            checked = checked,
            onCheckedChange = { newValue ->
                checked = newValue
                if (onChangeCb != 0) {
                    NativeUIBridge.sendCheckboxChangeEvent(onChangeCb, node.id, newValue)
                }
            },
            enabled = !disabled
        )
        if (label.isNotEmpty()) {
            Text(
                text = label,
                color = argbToColor(labelColor)
            )
        }
    }
}

@Composable
internal fun RenderSlider(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val initialValue = p.getFloat("value")
    val min = p.getFloat("min", 0f)
    val max = p.getFloat("max", 1f)
    val step = p.getFloat("step")
    val onChangeCb = p.getCallbackId("on_change")
    val disabled = p.getBool("disabled")

    var sliderValue by remember(node.id, initialValue) { mutableFloatStateOf(initialValue) }

    val steps = if (step > 0 && max > min) {
        ((max - min) / step).toInt() - 1
    } else {
        0
    }

    val thumbColor = p.getColor("color", 0)
    val trackColor = p.getColor("track_color", 0)

    val colors = if (thumbColor != 0 || trackColor != 0) {
        val activeColor = if (thumbColor != 0) argbToColor(thumbColor) else SliderDefaults.colors().thumbColor
        val activeTrack = if (trackColor != 0) argbToColor(trackColor) else SliderDefaults.colors().activeTrackColor
        SliderDefaults.colors(
            thumbColor = activeColor,
            activeTrackColor = activeTrack,
            inactiveTrackColor = activeTrack.copy(alpha = 0.3f)
        )
    } else {
        SliderDefaults.colors()
    }

    Slider(
        value = sliderValue,
        onValueChange = { newValue ->
            sliderValue = newValue
        },
        onValueChangeFinished = {
            if (onChangeCb != 0) {
                NativeUIBridge.sendSliderChangeEvent(onChangeCb, node.id, sliderValue)
            }
        },
        modifier = modifier,
        enabled = !disabled,
        valueRange = min..max,
        steps = steps.coerceAtLeast(0),
        colors = colors
    )
}

@Composable
internal fun RenderProgressBar(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    LinearProgressIndicator(
        progress = { p.getFloat("value").coerceIn(0f, 1f) },
        modifier = modifier,
        color = argbToColor(p.getColor("color", 0xFF6200EE.toInt())),
        trackColor = argbToColor(p.getColor("track_color", 0xFFE0E0E0.toInt()))
    )
}

@Composable
internal fun RenderActivityIndicator(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val sizeDp = when (p.getInt("size")) {
        1 -> 48.dp  // large
        2 -> 20.dp  // small
        else -> 32.dp // medium
    }

    CircularProgressIndicator(
        modifier = modifier.size(sizeDp),
        color = argbToColor(p.getColor("color", 0xFF6200EE.toInt()))
    )
}

@Composable
internal fun RenderRadioGroup(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val initialValue = p.getString("value")
    val onChangeCb = p.getCallbackId("on_change")

    var selectedValue by remember(node.id, initialValue) { mutableStateOf(initialValue) }

    Column(modifier = modifier) {
        node.children.forEach { child ->
            key(child.id) {
                if (child.type == "radio") {
                    RenderRadio(
                        node = child,
                        modifier = buildModifier(child),
                        selectedValue = selectedValue,
                        onSelect = { value ->
                            selectedValue = value
                            if (onChangeCb != 0) {
                                NativeUIBridge.sendRadioChangeEvent(onChangeCb, node.id, value)
                            }
                        }
                    )
                } else {
                    RenderNode(child)
                }
            }
        }
    }
}

@Composable
internal fun RenderRadio(
    node: NativeUINode,
    modifier: Modifier,
    selectedValue: String?,
    onSelect: ((String) -> Unit)?
) {
    val p = node.props
    val value = p.getString("value")
    val label = p.getString("label")
    val labelColor = p.getColor("label_color", 0xFF000000.toInt())
    val disabled = p.getBool("disabled")
    val isSelected = selectedValue == value

    Row(
        modifier = modifier
            .fillMaxWidth()
            .clickable(enabled = !disabled) {
                onSelect?.invoke(value)
            },
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp)
    ) {
        RadioButton(
            selected = isSelected,
            onClick = {
                onSelect?.invoke(value)
            },
            enabled = !disabled
        )
        if (label.isNotEmpty()) {
            Text(
                text = label,
                color = argbToColor(labelColor)
            )
        }
    }
}

@Composable
internal fun RenderIcon(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val name = p.getString("name")

    com.nativephp.mobile.ui.MaterialIcon(
        name = name,
        contentDescription = name,
        modifier = modifier.then(applyClickModifier(node)),
        size = p.getFloat("size", 24f).dp,
        tint = argbToColor(p.getColor("color", 0xFF000000.toInt()))
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun RenderSelect(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val initialValue = p.getString("value")
    val placeholder = p.getString("placeholder")
    val options = p.getStringList("options")
    val onChangeCb = p.getCallbackId("on_change")
    val disabled = p.getBool("disabled")

    var expanded by remember { mutableStateOf(false) }
    var selectedValue by remember(node.id, initialValue) { mutableStateOf(initialValue) }

    ExposedDropdownMenuBox(
        expanded = expanded,
        onExpandedChange = { if (!disabled) expanded = it },
        modifier = modifier
    ) {
        TextField(
            value = selectedValue.ifEmpty { placeholder },
            onValueChange = {},
            readOnly = true,
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
            modifier = Modifier.menuAnchor(ExposedDropdownMenuAnchorType.PrimaryNotEditable),
            colors = ExposedDropdownMenuDefaults.textFieldColors()
        )
        ExposedDropdownMenu(
            expanded = expanded,
            onDismissRequest = { expanded = false }
        ) {
            options.forEach { option ->
                DropdownMenuItem(
                    text = { Text(option) },
                    onClick = {
                        selectedValue = option
                        expanded = false
                        if (onChangeCb != 0) {
                            NativeUIBridge.sendSelectChangeEvent(onChangeCb, node.id, option)
                        }
                    }
                )
            }
        }
    }
}

@Composable
internal fun RenderBadge(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val count = p.getInt("count")
    val color = p.getColor("color", 0xFFFF0000.toInt())
    val textColor = p.getColor("text_color", 0xFFFFFFFF.toInt())

    Box(
        modifier = modifier
            .defaultMinSize(minWidth = 20.dp, minHeight = 20.dp)
            .clip(RoundedCornerShape(10.dp))
            .background(argbToColor(color))
            .padding(horizontal = 6.dp, vertical = 2.dp),
        contentAlignment = Alignment.Center
    ) {
        Text(
            text = if (count > 99) "99+" else count.toString(),
            color = argbToColor(textColor),
            fontSize = 12.sp,
            fontWeight = FontWeight.Bold
        )
    }
}

/* ── Chip ────────────────────────────────────────────── */

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun RenderChip(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val label = p.getString("label")
    val initialSelected = p.getBool("selected")
    val onChangeCb = p.getCallbackId("on_change")
    val iconName = p.getString("icon")

    var isSelected by remember(node.id, initialSelected) { mutableStateOf(initialSelected) }

    FilterChip(
        selected = isSelected,
        onClick = {
            isSelected = !isSelected
            if (onChangeCb != 0) {
                NativeUIBridge.sendToggleChangeEvent(onChangeCb, node.id, isSelected)
            }
        },
        label = { Text(label) },
        modifier = modifier,
        leadingIcon = if (iconName.isNotEmpty()) {
            {
                com.nativephp.mobile.ui.MaterialIcon(
                    name = iconName,
                    contentDescription = iconName,
                    size = 18.dp,
                    tint = Color.Unspecified
                )
            }
        } else null
    )
}

/* ── Card, ListItem, Tabs, BottomSheet ────────────────── */

@Composable
internal fun RenderCard(node: NativeUINode, modifier: Modifier) {
    val variant = node.props.getInt("variant")

    val content: @Composable () -> Unit = {
        Column {
            node.children.forEach { key(it.id) { RenderNode(it) } }
        }
    }

    when (variant) {
        1 -> OutlinedCard(
            modifier = modifier.then(applyClickModifier(node)),
            content = { content() }
        )
        2 -> ElevatedCard(
            modifier = modifier.then(applyClickModifier(node)),
            content = { content() }
        )
        else -> Card(
            modifier = modifier.then(applyClickModifier(node)),
            content = { content() }
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun RenderListItem(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val headline = p.getString("headline")
    val supporting = p.getString("supporting")
    val overline = p.getString("overline")
    val leadingIcon = p.getString("leading_icon")
    val trailingIcon = p.getString("trailing_icon")
    val headlineColor = p.getColor("headline_color", 0xFF000000.toInt())
    val supportingColor = p.getColor("supporting_color", 0xFF888888.toInt())

    ListItem(
        headlineContent = {
            Text(
                text = headline,
                color = if (headlineColor != 0) argbToColor(headlineColor) else Color.Unspecified
            )
        },
        modifier = modifier.then(applyClickModifier(node)),
        overlineContent = if (overline.isNotEmpty()) {
            { Text(text = overline) }
        } else null,
        supportingContent = if (supporting.isNotEmpty()) {
            {
                Text(
                    text = supporting,
                    color = if (supportingColor != 0) argbToColor(supportingColor) else Color.Unspecified
                )
            }
        } else null,
        leadingContent = if (leadingIcon.isNotEmpty()) {
            {
                com.nativephp.mobile.ui.MaterialIcon(
                    name = leadingIcon,
                    contentDescription = leadingIcon,
                    size = 24.dp,
                    tint = Color.Unspecified
                )
            }
        } else null,
        trailingContent = if (trailingIcon.isNotEmpty()) {
            {
                com.nativephp.mobile.ui.MaterialIcon(
                    name = trailingIcon,
                    contentDescription = trailingIcon,
                    size = 24.dp,
                    tint = Color.Unspecified
                )
            }
        } else null
    )
}

@Composable
internal fun RenderTabRow(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val initialIndex = p.getInt("selected_index")
    val onChangeCb = p.getCallbackId("on_change")

    var selectedIndex by remember(node.id, initialIndex) { mutableStateOf(initialIndex) }

    val tabs = node.children.filter { it.type == "tab" }
    if (tabs.isEmpty()) return

    Column(modifier = modifier) {
        PrimaryTabRow(
            selectedTabIndex = selectedIndex.coerceIn(0, tabs.size - 1)
        ) {
            tabs.forEachIndexed { index, tabNode ->
                val tabLabel = tabNode.props.getString("label")
                val tabIcon = tabNode.props.getString("icon")
                Tab(
                    selected = index == selectedIndex,
                    onClick = {
                        selectedIndex = index
                        if (onChangeCb != 0) {
                            NativeUIBridge.sendTabChangeEvent(onChangeCb, node.id, index)
                        }
                    },
                    text = if (tabLabel.isNotEmpty()) {
                        { Text(text = tabLabel) }
                    } else null,
                    icon = if (tabIcon.isNotEmpty()) {
                        {
                            com.nativephp.mobile.ui.MaterialIcon(
                                name = tabIcon,
                                contentDescription = tabIcon,
                                size = 24.dp,
                                tint = Color.Unspecified
                            )
                        }
                    } else null
                )
            }
        }
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
        Column(modifier = modifier) {
            node.children.forEach { key(it.id) { RenderNode(it) } }
        }
    }
}

/* ── ScrollView Flattening ────────────────────────────── */

/**
 * Container for resolved scroll content after optional flattening.
 */
private class ScrollContent(
    val children: List<NativeUINode>,
    val gap: Float,
    val contentPadding: PaddingValues,
    val wrapperModifier: Modifier,
    val horizontalAlignment: Alignment.Horizontal,
    val hasSafeArea: Boolean = false
)

/**
 * Common Blade pattern: `<scroll-view><column p-4 gap-4>...items...</column></scroll-view>`
 *
 * Without flattening, LazyColumn sees ONE child (the wrapper column) and composes
 * all ~400 nodes eagerly — defeating lazy virtualization entirely.
 *
 * This extracts the wrapper's children, padding, gap, background, and alignment
 * so LazyColumn can virtualize the actual items.
 */
private fun flattenScrollContent(node: NativeUINode, horizontal: Boolean): ScrollContent {
    val baseGap = node.layout?.gap ?: 0f

    if (node.children.size == 1) {
        val wrapper = node.children[0]
        val isMatchingContainer = if (horizontal) wrapper.type == "row" else wrapper.type == "column"

        if (isMatchingContainer && wrapper.children.isNotEmpty()) {
            val wl = wrapper.layout
            val ws = wrapper.style

            // Transfer wrapper's padding → LazyColumn contentPadding
            val padding = if (wl != null && hasEdges(wl.paddingTop, wl.paddingRight, wl.paddingBottom, wl.paddingLeft)) {
                PaddingValues(
                    start = wl.paddingLeft.dp,
                    top = wl.paddingTop.dp,
                    end = wl.paddingRight.dp,
                    bottom = wl.paddingBottom.dp
                )
            } else {
                PaddingValues(0.dp)
            }

            // Transfer wrapper's gap → LazyColumn item spacing
            val gap = wl?.gap ?: baseGap

            // Transfer wrapper's background → LazyColumn modifier
            var wrapperMod: Modifier = Modifier
            if (ws != null) {
                val bg = argbToColor(ws.bgColor)
                if (bg != Color.Transparent) {
                    wrapperMod = wrapperMod.background(bg)
                }
            }

            // Transfer wrapper's alignment → LazyColumn horizontalAlignment
            val hAlign = resolveHorizontalAlignment(wl?.alignItems ?: 0)

            android.util.Log.d("NativeUIPerf",
                "ScrollView flattened: ${wrapper.children.size} items from ${wrapper.type} wrapper (gap=$gap)")

            val hasSafeArea = wl != null && wl.safeArea != 0

            return ScrollContent(wrapper.children, gap, padding, wrapperMod, hAlign, hasSafeArea)
        }
    }

    return ScrollContent(node.children, baseGap, PaddingValues(0.dp), Modifier, Alignment.Start)
}

/* ── Modifier Building ────────────────────────────────── */

@Composable
internal fun buildModifier(node: NativeUINode): Modifier {
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

    // Safe area insets
    if (layout != null && layout.safeArea != 0) {
        mod = mod.windowInsetsPadding(WindowInsets.systemBars.union(WindowInsets.ime))
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

private fun Modifier.maybeWeight(node: NativeUINode, scope: Any): Modifier {
    val grow = node.layout?.flexGrow ?: 0f
    if (grow <= 0f) return this
    return when (scope) {
        is androidx.compose.foundation.layout.RowScope -> with(scope) { this@maybeWeight.weight(grow) }
        is androidx.compose.foundation.layout.ColumnScope -> with(scope) { this@maybeWeight.weight(grow) }
        else -> this
    }
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

/* ── Alignment / Arrangement Helpers ──────────────────── */

/**
 * justify_content values: 0=start, 1=center, 2=end, 3=space_between, 4=space_around, 5=space_evenly
 */
private fun resolveVerticalArrangement(justifyContent: Int, gap: Float): Arrangement.Vertical {
    if (gap > 0) {
        val alignment = when (justifyContent) {
            1 -> Alignment.CenterVertically
            2 -> Alignment.Bottom
            else -> Alignment.Top
        }
        return Arrangement.spacedBy(gap.dp, alignment)
    }
    return when (justifyContent) {
        0 -> Arrangement.Top
        1 -> Arrangement.Center
        2 -> Arrangement.Bottom
        3 -> Arrangement.SpaceBetween
        4 -> Arrangement.SpaceAround
        5 -> Arrangement.SpaceEvenly
        else -> Arrangement.Top
    }
}

private fun resolveHorizontalArrangement(justifyContent: Int, gap: Float): Arrangement.Horizontal {
    if (gap > 0) {
        val alignment = when (justifyContent) {
            1 -> Alignment.CenterHorizontally
            2 -> Alignment.End
            else -> Alignment.Start
        }
        return Arrangement.spacedBy(gap.dp, alignment)
    }
    return when (justifyContent) {
        0 -> Arrangement.Start
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
