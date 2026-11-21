package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.blur
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.dropShadow
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.shadow.Shadow
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.semantics.CustomAccessibilityAction
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.SemanticsPropertyKey
import androidx.compose.ui.semantics.ProgressBarRangeInfo
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.SemanticsPropertyReceiver
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.collapse
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.customActions
import androidx.compose.ui.semantics.disabled
import androidx.compose.ui.semantics.dismiss
import androidx.compose.ui.semantics.expand
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.isTraversalGroup
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.paneTitle
import androidx.compose.ui.semantics.progressBarRangeInfo
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.selectableGroup
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.text
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.semantics.setProgress
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.semantics.toggleableState
import androidx.compose.ui.state.ToggleableState
import androidx.compose.ui.unit.DpOffset
import androidx.compose.ui.unit.dp

// MARK: - Linear Gradient

/**
 * Build a linear-gradient Brush from `bg-gradient-to-* from-* via-* to-*`,
 * or null when the node declares no gradient.
 *
 * Gradient props ride the props bag rather than NodeStyle: the style block is a
 * fixed-layout region of the packed binary node with no room for a
 * variable-length stop list. `dark_bg_color` takes the same route.
 *
 * `gradient_stops` is a comma-joined list of two or three `#AARRGGBB` values.
 * `gradient_dx`/`gradient_dy` are the unit vector the gradient travels toward,
 * in view space where y grows downward — the same convention as iOS.
 * `dark_gradient_stops`, set when a stop is a theme token, replaces the list
 * in dark mode, as `dark_bg_color` replaces the flat colour.
 *
 * Compose wants pixel endpoints, and the brush is built before layout size is
 * known, so `Float.POSITIVE_INFINITY` is used as the "far edge" sentinel that
 * Compose resolves against the actual bounds at draw time.
 */
fun linearGradientBrush(props: GenericProps, isDarkMode: Boolean): Brush? {
    val darkRaw = if (isDarkMode) props.getString("dark_gradient_stops", "") else ""
    val raw = darkRaw.ifEmpty { props.getString("gradient_stops", "") }
    if (raw.isEmpty()) return null

    val colors = raw.split(",")
        .filter { it.isNotBlank() }
        .map { argbToComposeColor(ColorParser.parse(it.trim())) }

    if (colors.size < 2) return null

    val dx = props.getFloat("gradient_dx", 0f)
    val dy = props.getFloat("gradient_dy", 1f)

    val far = Float.POSITIVE_INFINITY

    // Map each axis to start/end offsets: a negative component means the
    // gradient travels toward that origin, so start at the far edge.
    val startX = if (dx > 0f) 0f else if (dx < 0f) far else 0f
    val endX = if (dx > 0f) far else if (dx < 0f) 0f else 0f
    val startY = if (dy > 0f) 0f else if (dy < 0f) far else 0f
    val endY = if (dy > 0f) far else if (dy < 0f) 0f else 0f

    return Brush.linearGradient(
        colors = colors,
        start = Offset(startX, startY),
        end = Offset(endX, endY),
    )
}

// MARK: - ARGB Color Conversion

/**
 * Convert a 32-bit ARGB integer to a Compose Color.
 * Transparent (0x00000000) maps to Color.Transparent.
 */
fun argbToComposeColor(argb: Int): Color {
    val v = argb.toUInt()
    val a = ((v shr 24) and 0xFFu).toFloat() / 255f
    if (a <= 0f) return Color.Transparent
    val r = ((v shr 16) and 0xFFu).toFloat() / 255f
    val g = ((v shr 8) and 0xFFu).toFloat() / 255f
    val b = (v and 0xFFu).toFloat() / 255f
    return Color(r, g, b, a)
}

// MARK: - Style Modifier

/**
 * Applies visual style properties from a NativeUINode.
 * Handles background color, corner radius, border, shadow, glow, blur, opacity,
 * and dark mode overrides from dark_* props.
 */
/**
 * The node's outline: per-corner when `rounded-<side>-*` was authored,
 * otherwise the uniform `border_radius`.
 *
 * Per-corner radii ride the props bag because the packed binary node carries
 * a single `border_radius` float with no room for four. PHP emits all four
 * whenever ANY corner is authored, each already resolved against the uniform
 * radius — so `radius_tl` being present is the whole switch, and there is no
 * per-corner defaulting to do here.
 *
 * Compose names corners start/end, which flip under RTL, while the Tailwind
 * spellings we accept are physical (`tl` = top-LEFT). Start is mapped to left
 * to match the rest of this renderer, which has no RTL handling either; the
 * parser rejects Tailwind's logical `rounded-s-*` spellings for that reason.
 */
fun nodeShape(radius: Float, props: GenericProps): RoundedCornerShape =
    if (props.has("radius_tl")) {
        RoundedCornerShape(
            topStart = props.getFloat("radius_tl", 0f).dp,
            topEnd = props.getFloat("radius_tr", 0f).dp,
            bottomEnd = props.getFloat("radius_br", 0f).dp,
            bottomStart = props.getFloat("radius_bl", 0f).dp
        )
    } else if (radius > 0f) {
        RoundedCornerShape(radius.dp)
    } else {
        RoundedCornerShape(0.dp)
    }

fun Modifier.nodeStyle(style: NodeStyle?, props: GenericProps, isDarkMode: Boolean): Modifier {
    if (style == null) return this

    var mod = this
    val radius = style.borderRadius

    // Opacity (with dark mode override).
    //
    // Must be applied FIRST: a Compose modifier only affects what is drawn
    // after it in the chain, so alpha has to precede shadow/background/border
    // to fade the whole box — matching SwiftUI's .opacity(), which wraps the
    // entire view. Applied last, it would only fade inner content.
    //
    // Defer to `NodeView` when:
    //   - `animate-duration > 0` (state transitions),
    //   - `animate-loop` (yoyo),
    //   - opacity is bound to a SharedValue (`opacity_sv` set).
    // Otherwise applying here would double-multiply.
    val animateDuration = props.getFloat("animate-duration", 0f)
    val animateLoop = props.getBool("animate-loop")
    val opacitySharedBound = props.getString("opacity_sv", "").isNotEmpty()
    if (animateDuration <= 0f && !animateLoop && !opacitySharedBound) {
        val darkOpacity = if (isDarkMode) props.getFloat("dark_opacity", 0f) else 0f
        val opacity = if (darkOpacity > 0f) darkOpacity else style.opacity
        if (opacity < 1f && opacity >= 0f) {
            mod = mod.alpha(opacity)
        }
    }

    // Background color (with dark mode override)
    val darkBg = if (isDarkMode) props.getColor("dark_bg_color", 0) else 0
    val bgArgb = if (darkBg != 0) darkBg else style.bgColor
    val shape = nodeShape(radius, props)

    // Elevation `shadow-*` — must come before background. Compose shadow
    // requires a shape to cast from; the background provides the visual fill.
    if (style.elevation > 0f) {
        mod = mod.shadow(elevation = style.elevation.dp, shape = shape)
    }

    // Colored `glow-*` halo (Slice 1). Prefer Compose `dropShadow` (BOM
    // 2025.12 / Compose 1.10) for a true zero-offset colored blur. If that
    // API is unavailable on an older toolchain, fall back to elevation
    // `shadow` with matching ambientColor/spotColor — documented below.
    val glowArgb = props.getColor("glow_color", 0)
    val glowRadius = props.getFloat("glow_radius", 0f)
    val glowOpacity = props.getFloat("glow_opacity", 0f)
    if (glowArgb != 0 && glowRadius > 0f && glowOpacity > 0f) {
        val base = argbToComposeColor(glowArgb)
        // Stack two dropShadows (outer softer / inner denser) to mirror
        // iOS's double zero-offset `.shadow` stack.
        val outer = base.copy(alpha = (base.alpha * glowOpacity * 0.55f).coerceIn(0f, 1f))
        val inner = base.copy(alpha = (base.alpha * glowOpacity).coerceIn(0f, 1f))
        mod = mod
            .dropShadow(
                shape = shape,
                shadow = Shadow(
                    radius = glowRadius.dp,
                    color = outer,
                    offset = DpOffset.Zero,
                ),
            )
            .dropShadow(
                shape = shape,
                shadow = Shadow(
                    radius = (glowRadius * 0.5f).coerceAtLeast(1f).dp,
                    color = inner,
                    offset = DpOffset.Zero,
                ),
            )
        // Fallback (documented): when `dropShadow` is not on the classpath,
        // replace the block above with elevation-colored shadows —
        //   mod = mod.shadow(
        //       elevation = glowRadius.dp,
        //       shape = shape,
        //       ambientColor = inner,
        //       spotColor = inner,
        //   )
        // That approximates a colored halo via Material elevation lighting
        // rather than a true zero-offset blur; prefer dropShadow on BOM 2025.12+.
    }

    // Background — a linear gradient wins over the flat color when declared,
    // matching CSS where background-image paints over background-color.
    val gradient = linearGradientBrush(props, isDarkMode)

    if (gradient != null) {
        mod = mod.background(gradient, shape)
    } else if (bgArgb != 0) {
        val bgColor = argbToComposeColor(bgArgb)
        if (bgColor != Color.Transparent) {
            mod = mod.background(bgColor, shape)
        }
    } else if (style.elevation > 0f || (glowArgb != 0 && glowRadius > 0f)) {
        // Elevation / glow needs a background to be visible — add white if none specified
        mod = mod.background(Color.White, shape)
    }

    // Note: clip is NOT applied automatically for border-radius.
    // background(shape) and border(shape) already render rounded visuals.
    // Clipping would cut off children near rounded corners (e.g. icons in cards).
    // Only apply clip when overflow == hidden (scroll containers handle their own).

    // Border
    if (style.borderWidth > 0f) {
        val darkBorder = if (isDarkMode) props.getColor("dark_border_color", 0) else 0
        val borderArgb = if (darkBorder != 0) darkBorder else style.borderColor
        if (borderArgb != 0) {
            val borderColor = argbToComposeColor(borderArgb)
            if (borderColor != Color.Transparent) {
                // Dashed, dotted and per-side borders (see NodeBorder).
                val border = NodeBorder.from(props, style.borderWidth, radius)
                mod = if (border != null) {
                    mod.nodeBorder(border, borderColor)
                } else {
                    mod.border(style.borderWidth.dp, borderColor, shape)
                }
            }
        }
    }

    // Tailwind `blur-*` — Gaussian softens the node's own pixels (page-bg
    // orbs). Compose `Modifier.blur` uses RenderEffect and is API 31+ only;
    // on older devices it is a documented no-op (Compose framework behavior).
    // Default NativePHP minSdk is 33, so production apps get real blur.
    val blurRadius = props.getFloat("blur", 0f)
    if (blurRadius > 0f) {
        mod = mod.blur(blurRadius.dp)
    }

    return mod
}

// MARK: - Layout Modifier

/**
 * Applies layout properties from a NativeUINode.
 *
 * Size constraints (width/height modes, fill/fixed) are NOT applied here —
 * they are handled by FlexLayout which reads childNodes[i].layout directly.
 * In Compose, modifier constraints override what the parent Layout proposes,
 * so sizing must live in the Layout, not in modifiers.
 *
 * This modifier handles: padding, safe area, aspect ratio, display:none.
 */
fun Modifier.nodeLayout(
    layout: NodeLayout?,
    safeAreaTop: Float,
    safeAreaBottom: Float,
    availableWidth: Float,
    availableHeight: Float
): Modifier {
    if (layout == null) return this

    var mod = this

    // Padding
    if (layout.paddingTop > 0f || layout.paddingRight > 0f ||
        layout.paddingBottom > 0f || layout.paddingLeft > 0f) {
        mod = mod.padding(
            start = layout.paddingLeft.dp,
            top = layout.paddingTop.dp,
            end = layout.paddingRight.dp,
            bottom = layout.paddingBottom.dp
        )
    }

    // Safe area padding. `safe_area` encodes which edges to inset:
    //   0 = none, 1 = both (legacy), 2 = top only, 3 = bottom only.
    // Top-only / bottom-only let a layout's wrapper free one edge so a
    // chrome bar (NavBar / TabBar) can extend its bg through the system
    // inset zone, while the wrapper still handles the other edge.
    if (layout.safeArea != 0) {
        val applyTop = layout.safeArea == 1 || layout.safeArea == 2
        val applyBottom = layout.safeArea == 1 || layout.safeArea == 3
        mod = mod.padding(
            top = if (applyTop) safeAreaTop.dp else 0.dp,
            bottom = if (applyBottom) safeAreaBottom.dp else 0.dp
        )
    }

    // Aspect ratio
    if (layout.aspectRatio > 0f && layout.aspectRatio.isFinite()) {
        mod = mod.aspectRatio(layout.aspectRatio)
    }

    // Display none
    if (layout.display == Display.NONE) {
        mod = mod.size(0.dp).alpha(0f)
    }

    return mod
}

// MARK: - Gesture Modifier

/**
 * Wires onPress / onLongPress callbacks to Compose gestures.
 *
 * When `interactionSource` is non-null, it's passed to the underlying
 * clickable so callers can collect press state (via
 * `interactionSource.collectIsPressedAsState()`) and drive visual
 * press feedback on the UI thread. In that case the ripple is
 * suppressed (`indication = null`) because the caller is providing
 * the press feedback via `press-*` props. Pass null to keep the
 * default ripple behavior.
 */
@OptIn(ExperimentalFoundationApi::class)
fun Modifier.nodeGestures(
    node: NativeUINode,
    interactionSource: androidx.compose.foundation.interaction.MutableInteractionSource? = null,
): Modifier {
    val callbackId = node.onPress
    val longPressId = node.onLongPress
    // Double-tap is carried in props (`on_double_tap`), not a dedicated node
    // field like onPress/onLongPress, so it needs no binary wire-format change.
    val doubleTapId = node.props.getInt("on_double_tap")
    // Press-down/up ride the props dict the same way. Both reuse the PRESS
    // wire event — the callback id alone routes to the handler.
    val pressDownId = node.props.getInt("on_press_down")
    val pressUpId = node.props.getInt("on_press_up")

    if (callbackId == 0 && longPressId == 0 && doubleTapId == 0 &&
        pressDownId == 0 && pressUpId == 0
    ) {
        return this
    }

    // `disabled` stops every handler; the clickable stays in place, with
    // enabled = false, so TalkBack still finds the element and says it is
    // unavailable.
    val enabled = !node.props.getBool("disabled")

    val nodeId = node.id

    var mod: Modifier = this

    // Touch-contact tracking for held-button semantics (gamepad d-pads,
    // push-to-talk): down fires on first contact, up when every pointer
    // lifts. The `finally` also runs on gesture-coroutine cancellation
    // (scroll steals the stream, node leaves composition) so a held button
    // is never left stuck. Observes without consuming, so it composes with
    // the clickable below when @tap is also present.
    if (enabled && (pressDownId != 0 || pressUpId != 0)) {
        mod = mod.pointerInput(pressDownId, pressUpId, nodeId) {
            awaitEachGesture {
                awaitFirstDown(requireUnconsumed = false)
                if (pressDownId != 0) {
                    NativeElementBridge.sendPressEvent(pressDownId, nodeId)
                }
                try {
                    do {
                        val event = awaitPointerEvent()
                    } while (event.changes.any { it.pressed })
                } finally {
                    if (pressUpId != 0) {
                        NativeElementBridge.sendPressEvent(pressUpId, nodeId)
                    }
                }
            }
        }
    }

    if (callbackId == 0 && longPressId == 0 && doubleTapId == 0) return mod

    val onClickAction: () -> Unit = {
        KeyboardFocusPolicy.dismissForInteractiveTap()
        if (callbackId != 0) {
            NativeElementBridge.sendPressEvent(callbackId, nodeId)
        }
    }
    val onLongClickAction: (() -> Unit)? = if (longPressId != 0) {
        {
            KeyboardFocusPolicy.dismissForInteractiveTap()
            NativeElementBridge.sendLongPressEvent(longPressId, nodeId)
        }
    } else {
        null
    }
    // Double-tap reuses the press event type — the callback id alone routes
    // to the @doubleTap handler on the PHP side.
    val onDoubleClickAction: (() -> Unit)? = if (doubleTapId != 0) {
        {
            KeyboardFocusPolicy.dismissForInteractiveTap()
            NativeElementBridge.sendPressEvent(doubleTapId, nodeId)
        }
    } else {
        null
    }

    // `clickable` only handles single tap; long-press / double-tap require
    // `combinedClickable`.
    val needCombined = onLongClickAction != null || onDoubleClickAction != null

    // A tappable `pressable` is a button to TalkBack, as SwiftUI's Button
    // is to VoiceOver, unless `a11y-role` says otherwise. The clickable
    // already merges its children's text into one focusable element.
    val role = composeRole(
        NodeAccessibility.role(
            node.type,
            node.props.getString("a11y_role"),
            hasPress = callbackId != 0,
            hasMenu = node.props.getBool("has_menu"),
        ),
    )

    return if (interactionSource != null) {
        if (needCombined) {
            mod.combinedClickable(
                interactionSource = interactionSource,
                indication = null,
                enabled = enabled,
                role = role,
                onLongClick = onLongClickAction,
                onDoubleClick = onDoubleClickAction,
                onClick = onClickAction,
            )
        } else {
            mod.clickable(
                interactionSource = interactionSource,
                indication = null,
                enabled = enabled,
                role = role,
                onClick = onClickAction,
            )
        }
    } else {
        if (needCombined) {
            mod.combinedClickable(
                enabled = enabled,
                role = role,
                onLongClick = onLongClickAction,
                onDoubleClick = onDoubleClickAction,
                onClick = onClickAction,
            )
        } else {
            mod.clickable(enabled = enabled, role = role, onClick = onClickAction)
        }
    }
}

// MARK: - Accessibility Modifier

/**
 * Marks a node `NodeView` hides from TalkBack (`a11y-hidden`, or outside an
 * on-screen modal), for `Accessibility::focus()`. Declared outside Compose,
 * so it is not important for accessibility and leaves the node visible to
 * UI drivers.
 */
internal val A11yHidden = SemanticsPropertyKey<Unit>("NativeA11yHidden")

/**
 * Hides chrome drawn outside the node tree (the top app bar, the bottom
 * bar) from TalkBack while an `a11y-modal` container is on screen, as
 * `NodeView` hides the tree's own nodes. [node] is the tree node the
 * chrome draws, if any: chrome that holds the modal stays reachable.
 */
@Composable
fun Modifier.hiddenWhileA11yModal(node: NativeUINode?): Modifier {
    val path = LocalA11yModalPath.current ?: return this
    return if (node != null && node.id in path) this else clearAndSetSemantics { }
}

/** The Compose role for a wire role. Roles with no Compose twin map to null. */
internal fun composeRole(role: String?): Role? = when (role) {
    "button" -> Role.Button
    "checkbox" -> Role.Checkbox
    "switch" -> Role.Switch
    "radio" -> Role.RadioButton
    "tab" -> Role.Tab
    "dropdown" -> Role.DropdownList
    "image" -> Role.Image
    else -> null
}

/**
 * Tells TalkBack what a node composed from core elements is: its role,
 * state, value, adjust gesture, custom actions, pane title and live
 * region (see [NodeAccessibility.Spec]).
 *
 * A labelled core container (`pressable`, `column`, `row`, `stack`) is read
 * as one element: its `a11y-label` (plus `a11y-hint`) replaces its
 * children's text. An unlabelled control (a role, a range or actions)
 * merges its children's text instead. A `radiogroup`, `tablist` or modal
 * container keeps its children.
 *
 * Must sit INSIDE (after) `nodeGestures` in the chain. `clearAndSetSemantics`
 * clears the semantics of everything inside it, so placed before the
 * clickable it would drop the click action and button role as well as the
 * children's text.
 */
fun Modifier.nodeAccessibility(node: NativeUINode): Modifier {
    val spec = NodeAccessibility.spec(node.type, node.props, hasPress = node.onPress != 0) ?: return this
    val nodeId = node.id
    val pressId = node.onPress

    val properties: SemanticsPropertyReceiver.() -> Unit = {
        spec.description?.let { contentDescription = it }
        // A merging group's label is its text: Compose would move a content
        // description to a child node of its own, read as a separate stop.
        spec.groupLabel?.let {
            if (spec.mergesChildren) text = AnnotatedString(it) else contentDescription = it
        }

        // A clickable already carries its role (see nodeGestures).
        if (pressId == 0) composeRole(spec.role)?.let { role = it }
        when (spec.role) {
            "header" -> heading()
            // A traversal group as well, so Compose places the group in the
            // reading order: TalkBack stops on it once it has an error to say,
            // and Compose orders only traversal groups and the nodes it
            // counts as focusable, which a group of controls is not.
            "radiogroup", "tablist" -> {
                selectableGroup()
                isTraversalGroup = true
            }
        }

        spec.toggle?.let {
            toggleableState = when (it) {
                NodeAccessibility.Toggle.On -> ToggleableState.On
                NodeAccessibility.Toggle.Off -> ToggleableState.Off
                NodeAccessibility.Toggle.Indeterminate -> ToggleableState.Indeterminate
            }
        }
        spec.selected?.let { selected = it }
        spec.stateDescription?.let { stateDescription = it }
        if (spec.disabled) disabled()

        // Expanded rows offer the opposite action, which taps the row:
        // TalkBack reads the state from which of the two is present.
        if (spec.expanded != null && pressId != 0) {
            val toggle = { NativeElementBridge.sendPressEvent(pressId, nodeId); true }
            if (spec.expanded) collapse(action = toggle) else expand(action = toggle)
        }

        when {
            spec.indeterminate -> progressBarRangeInfo = ProgressBarRangeInfo.Indeterminate
            spec.range != null -> {
                val range = spec.range
                progressBarRangeInfo = ProgressBarRangeInfo(range.now, range.min..range.max)
                if (spec.incrementId != 0 || spec.decrementId != 0) {
                    setProgress { target ->
                        val callbackId = NodeAccessibility.adjustment(spec, target) ?: return@setProgress false
                        NativeElementBridge.sendPressEvent(callbackId, nodeId)
                        true
                    }
                }
            }
        }

        if (spec.actions.isNotEmpty()) {
            customActions = spec.actions.map { action ->
                CustomAccessibilityAction(action.label) {
                    NativeElementBridge.sendPressEvent(action.callbackId, nodeId)
                    true
                }
            }
        }

        if (spec.modal) isTraversalGroup = true
        spec.paneTitle?.let { paneTitle = it }
        if (spec.dismissId != 0) {
            dismiss {
                NativeElementBridge.sendPressEvent(spec.dismissId, nodeId)
                true
            }
        }

        when (spec.live) {
            "polite" -> liveRegion = LiveRegionMode.Polite
            "assertive" -> liveRegion = LiveRegionMode.Assertive
        }
    }

    return when {
        spec.description != null -> clearAndSetSemantics(properties)
        else -> semantics(mergeDescendants = spec.mergesChildren, properties = properties)
    }
}
