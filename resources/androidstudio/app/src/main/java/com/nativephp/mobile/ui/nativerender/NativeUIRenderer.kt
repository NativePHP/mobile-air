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
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
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
 */
@Composable
fun NativeUIContent() {
    val tree by NativeUIBridge.currentTree
    val focusManager = androidx.compose.ui.platform.LocalFocusManager.current
    val keyboardController = androidx.compose.ui.platform.LocalSoftwareKeyboardController.current

    tree?.let {
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
            RenderNode(it.root)
        }
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
            node.children.forEach { RenderNode(it) }
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
        modifier = modifier,
        verticalArrangement = vArrangement,
        horizontalAlignment = hAlignment
    ) {
        node.children.forEach { child ->
            val childMod = buildModifier(child).maybeWeight(child, this)
            RenderNode(child, childMod)
        }
    }
}

@Composable
internal fun RenderRow(node: NativeUINode, modifier: Modifier) {
    val layout = node.layout
    val hArrangement = resolveHorizontalArrangement(layout?.justifyContent ?: 0, layout?.gap ?: 0f)
    val vAlignment = resolveVerticalAlignment(layout?.alignItems ?: 0)

    Row(
        modifier = modifier,
        horizontalArrangement = hArrangement,
        verticalAlignment = vAlignment
    ) {
        node.children.forEach { child ->
            val childMod = buildModifier(child).maybeWeight(child, this)
            RenderNode(child, childMod)
        }
    }
}

@Composable
internal fun RenderStack(node: NativeUINode, modifier: Modifier) {
    Box(modifier = modifier) {
        node.children.forEach { RenderNode(it) }
    }
}

@Composable
internal fun RenderScrollView(node: NativeUINode, modifier: Modifier) {
    val horizontal = node.props.getBool("horizontal")
    val scrollState = rememberScrollState()

    if (horizontal) {
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
internal fun RenderText(node: NativeUINode, modifier: Modifier) {
    val p = node.props
    val text = p.getString("text")
    if (text.isEmpty()) return

    Text(
        text = text,
        modifier = modifier.then(applyClickModifier(node)),
        color = argbToColor(p.getColor("color", 0xFF000000.toInt())),
        fontSize = p.getFloat("font_size", 16f).sp,
        fontWeight = resolveFontWeight(p.getInt("font_weight")),
        textAlign = resolveTextAlign(p.getInt("text_align")),
        maxLines = p.getInt("max_lines").let { if (it > 0) it else Int.MAX_VALUE },
        overflow = TextOverflow.Ellipsis
    )
}

@Composable
internal fun RenderImage(node: NativeUINode, modifier: Modifier) {
    val src = node.props.getString("src")

    Box(
        modifier = modifier
            .then(applyClickModifier(node))
            .background(Color(0xFFE0E0E0)),
        contentAlignment = Alignment.Center
    ) {
        Text(
            text = src.takeLast(20),
            fontSize = 10.sp,
            color = Color.Gray
        )
    }
}

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

@Composable
internal fun RenderSpacer(node: NativeUINode, modifier: Modifier) {
    val layout = node.layout
    if (layout != null && layout.widthMode == SizeMode.FIXED && layout.width > 0) {
        Spacer(modifier = modifier.width(layout.width.dp))
    } else if (layout != null && layout.heightMode == SizeMode.FIXED && layout.height > 0) {
        Spacer(modifier = modifier.height(layout.height.dp))
    } else {
        Spacer(modifier = modifier.fillMaxWidth().height(0.dp))
    }
}

@Composable
internal fun RenderDivider(node: NativeUINode, modifier: Modifier) {
    HorizontalDivider(modifier = modifier)
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
        steps = steps.coerceAtLeast(0)
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
            node.children.forEach { RenderNode(it) }
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
            node.children.forEach { RenderNode(it) }
        }
    }
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
