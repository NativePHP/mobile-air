package com.nativephp.mobile.ui.nativerender

import androidx.compose.animation.core.Easing
import androidx.compose.animation.core.FastOutLinearInEasing
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.LinearOutSlowInEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.selection.DisableSelection
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.unit.dp

/**
 * Recursive composable that renders a NativeUINode and its children.
 * Dispatches to registered renderers via NativeRendererRegistry.
 * Falls back to a flex container for unknown types.
 *
 * @param overrideModifier Optional modifier from parent FlexContainer that includes
 *   flex-specific properties (weight, margin). When provided, it's prepended to
 *   the node's own layout/style/gesture modifiers.
 */
@Composable
fun NodeView(node: NativeUINode, overrideModifier: Modifier? = null) {
    key(node.id) {
        val renderer = NativeRendererRegistry.get(node.type)
        val isDarkMode = isSystemInDarkTheme()
        val safeAreaTop = LocalSafeAreaTop.current
        val safeAreaBottom = LocalSafeAreaBottom.current
        val availableWidth = LocalAvailableWidth.current
        val availableHeight = LocalAvailableHeight.current

        // Start with flex modifier from parent (weight, margin), then add own modifiers.
        // Order: margin/weight → style (background/border) → layout (padding) → gestures
        // Background must come BEFORE padding so it covers the padded area (CSS box model).
        val base = overrideModifier ?: run {
            // No parent FlexContainer — apply size from node layout directly
            var mod: Modifier = Modifier
            val layout = node.layout
            if (layout != null) {
                when (layout.widthMode) {
                    SizeMode.FILL -> mod = mod.fillMaxWidth()
                    SizeMode.FIXED -> if (layout.width > 0f) mod = mod.width(layout.width.dp)
                    SizeMode.PERCENT -> if (layout.width > 0f) {
                        mod = mod.fillMaxWidth((layout.width / 100f).coerceIn(0f, 1f))
                    }
                }
                when (layout.heightMode) {
                    SizeMode.FILL -> mod = mod.fillMaxHeight()
                    SizeMode.FIXED -> if (layout.height > 0f) mod = mod.height(layout.height.dp)
                    SizeMode.PERCENT -> if (layout.height > 0f) {
                        mod = mod.fillMaxHeight((layout.height / 100f).coerceIn(0f, 1f))
                    }
                }
            }
            mod
        }
        // Order matters: gestures must come BEFORE padding so the
        // clickable bounds cover the FULL sized surface (background +
        // padding region), not just the inner content rect. Compose
        // applies modifiers outer→inner; if `nodeLayout` (padding) ran
        // before `nodeGestures` the ripple would be inset by the
        // padding and visibly fall short of the pressable's edges —
        // looks like the ripple is "ignoring" the padding.
        //
        // Stack: size (base) → bg/border (nodeStyle) → optional clip
        // (rounded tappable surfaces) → click region (nodeGestures) →
        // inset children (nodeLayout).
        //
        // The conditional `.clip(RoundedCornerShape)` is the reason
        // ripples stay inside the pill / rounded-button shape instead
        // of bleeding past the corners. It applies ONLY when both
        // conditions hold:
        //   - the node has a press / long-press handler (so ripple
        //     will actually be drawn — clipping a non-tappable surface
        //     wouldn't do anything useful)
        //   - the node has a non-zero border radius
        // Non-tappable rounded surfaces (cards, chips with overflowing
        // avatars, etc.) intentionally skip the clip — the existing
        // policy in NodeModifiers.kt explains why we don't clip
        // unconditionally: it'd cut off children that legitimately
        // overflow the rounded shape.
        val hasPress = node.onPress != 0 || node.onLongPress != 0
        val radius = node.style?.borderRadius ?: 0f

        // ── Phase 1a/1b — animation + transform value resolution ─────
        val animateDuration = node.props.getFloat("animate-duration", 0f)
        val animateLoop = node.props.getBool("animate-loop")

        // SharedValue bindings (companion `_sv` props) take precedence
        // over literals. The `evaluate` call is @Composable — its
        // mutableState read subscribes the caller to recomposition.
        // The literal (PHP's snapshot of the SharedValue at publish
        // time) doubles as evaluate's `initial`, so an id the store
        // hasn't seen yet — fresh from a re-render that minted a new
        // SharedValue — renders at its initial value instead of 0.
        val txRef = node.props.getString("translate-x_sv", "")
        val tyRef = node.props.getString("translate-y_sv", "")
        val scRef = node.props.getString("scale_sv", "")
        val rtRef = node.props.getString("rotate_sv", "")
        val opRef = node.props.getString("opacity_sv", "")

        val txLit = node.props.getFloat("translate-x", 0f)
        val tyLit = node.props.getFloat("translate-y", 0f)
        val scLit = node.props.getFloat("scale", 1f)
        val rtLit = node.props.getFloat("rotate", 0f)

        val translateX = if (txRef.isNotEmpty()) SharedValueStore.evaluate(txRef, txLit) ?: 0f else txLit
        val translateY = if (tyRef.isNotEmpty()) SharedValueStore.evaluate(tyRef, tyLit) ?: 0f else tyLit
        val scaleVal   = if (scRef.isNotEmpty()) SharedValueStore.evaluate(scRef, scLit) ?: 1f else scLit
        val rotateVal  = if (rtRef.isNotEmpty()) SharedValueStore.evaluate(rtRef, rtLit) ?: 0f else rtLit

        val hasTransforms = translateX != 0f || translateY != 0f
            || scaleVal != 1f || rotateVal != 0f
        val hasSharedBinding = txRef.isNotEmpty() || tyRef.isNotEmpty()
            || scRef.isNotEmpty() || rtRef.isNotEmpty() || opRef.isNotEmpty()
        val needsAnimLayer = animateDuration > 0f || hasTransforms || animateLoop || hasSharedBinding

        // Compute animated values upfront so we can build the modifier
        // chain in the correct order below (graphicsLayer must come
        // BEFORE nodeStyle so transforms + alpha wrap the bg, not just
        // the inner content — same reason `.contentShape` doesn't help
        // here: it's a draw-layer issue, not a hit-test one).
        var animAlpha = 1f
        var animTx = 0f
        var animTy = 0f
        var animScale = 1f
        var animRotate = 0f
        var applyAnimAlpha = false

        if (needsAnimLayer) {
            val style = node.style
            val darkOpacity = if (isDarkMode) node.props.getFloat("dark_opacity", 0f) else 0f
            val literalOpacity = when {
                darkOpacity > 0f -> darkOpacity
                style != null -> style.opacity
                else -> 1f
            }
            val targetOpacity = if (opRef.isNotEmpty()) SharedValueStore.evaluate(opRef, literalOpacity) ?: literalOpacity
                                else literalOpacity
            val easingName = node.props.getString("animate-easing", "ease-in-out")
            val isSpring = easingName == "spring"
            val easing: Easing = when (easingName) {
                "linear"      -> LinearEasing
                "ease-in"     -> FastOutLinearInEasing
                "ease-out"    -> LinearOutSlowInEasing
                "ease-in-out" -> FastOutSlowInEasing
                else          -> FastOutSlowInEasing
            }
            val effectiveMs = if (animateDuration > 0f) animateDuration.toInt() else 600

            // `animate-easing="spring"` uses Compose's spring physics
            // instead of a tween, to match SwiftUI's `.spring(...)`.
            // Duration on spring is approximate — the dampingRatio /
            // stiffness drive the actual feel.
            val delayMs = node.props.getFloat("animate-delay", 0f).toInt().coerceAtLeast(0)

            fun <T> oneShotSpec(): androidx.compose.animation.core.AnimationSpec<T> =
                if (isSpring) spring(dampingRatio = Spring.DampingRatioMediumBouncy, stiffness = Spring.StiffnessMedium)
                else tween(durationMillis = animateDuration.toInt().coerceAtLeast(1), delayMillis = delayMs, easing = easing)

            if (animateLoop) {
                val infinite = rememberInfiniteTransition(label = "node_loop")
                // `infiniteRepeatable` only accepts duration-based specs,
                // so `spring` falls back to a tween for loop mode —
                // springs have natural completion, the "yoyo" repeat
                // wouldn't have a meaningful spring shape anyway.
                // `animate-delay` staggers loop phases across siblings via
                // a start offset rather than a per-cycle delay.
                val loopSpec = infiniteRepeatable<Float>(
                    animation = tween(durationMillis = effectiveMs, easing = easing),
                    repeatMode = RepeatMode.Reverse,
                    initialStartOffset = androidx.compose.animation.core.StartOffset(
                        delayMs, androidx.compose.animation.core.StartOffsetType.Delay
                    ),
                )
                animAlpha   = infinite.animateFloat(initialValue = 1f, targetValue = targetOpacity, animationSpec = loopSpec, label = "loop_alpha").value
                animTx      = infinite.animateFloat(initialValue = 0f, targetValue = translateX,    animationSpec = loopSpec, label = "loop_tx").value
                animTy      = infinite.animateFloat(initialValue = 0f, targetValue = translateY,    animationSpec = loopSpec, label = "loop_ty").value
                animScale   = infinite.animateFloat(initialValue = 1f, targetValue = scaleVal,      animationSpec = loopSpec, label = "loop_scale").value
                animRotate  = infinite.animateFloat(initialValue = 0f, targetValue = rotateVal,     animationSpec = loopSpec, label = "loop_rotate").value
                applyAnimAlpha = true
            } else {
                val spec: androidx.compose.animation.core.AnimationSpec<Float> = oneShotSpec()
                animAlpha   = animateFloatAsState(targetValue = targetOpacity, animationSpec = spec, label = "node_alpha").value
                animTx      = animateFloatAsState(targetValue = translateX,    animationSpec = spec, label = "node_tx").value
                animTy      = animateFloatAsState(targetValue = translateY,    animationSpec = spec, label = "node_ty").value
                animScale   = animateFloatAsState(targetValue = scaleVal,      animationSpec = spec, label = "node_scale").value
                animRotate  = animateFloatAsState(targetValue = rotateVal,     animationSpec = spec, label = "node_rotate").value
                applyAnimAlpha = animateDuration > 0f || opRef.isNotEmpty()
            }
        }

        // ── Phase 2 — press feedback value resolution ────────────────
        val pressScale = node.props.getFloat("press-scale", 0f)
        val pressOpacityProp = node.props.getFloat("press-opacity", 0f)
        val pressTy = node.props.getFloat("press-translate-y", 0f)
        val hasPressFeedback = hasPress && (pressScale > 0f || pressOpacityProp > 0f || pressTy != 0f)
        val interactionSource = if (hasPressFeedback) remember { MutableInteractionSource() } else null

        var pressAnimScale = 1f
        var pressAnimAlpha = 1f
        var pressAnimTy = 0f

        if (hasPressFeedback && interactionSource != null) {
            val isPressed by interactionSource.collectIsPressedAsState()
            val springSpec = spring<Float>(dampingRatio = Spring.DampingRatioMediumBouncy, stiffness = Spring.StiffnessMedium)

            val targetScale = if (isPressed && pressScale > 0f) pressScale else 1f
            val targetOpacity = if (isPressed && pressOpacityProp > 0f) pressOpacityProp else 1f
            val targetTy = if (isPressed) pressTy else 0f

            pressAnimScale = animateFloatAsState(targetScale, springSpec, label = "press_scale").value
            pressAnimAlpha = animateFloatAsState(targetOpacity, springSpec, label = "press_opacity").value
            pressAnimTy = animateFloatAsState(targetTy, springSpec, label = "press_ty").value
        }

        // ── Modifier chain ───────────────────────────────────────────
        // Outer to inner:
        //   base (sizing)
        //   → press feedback graphicsLayer  (wraps everything for visual scale/alpha on press)
        //   → animation graphicsLayer       (wraps bg too so translate/alpha affect the box, not just inner content)
        //   → nodeStyle                     (background + border)
        //   → clip (rounded pressable)
        //   → nodeGestures                  (clickable; ripple now lives inside the scaled visual)
        //   → nodeLayout                    (padding)
        var modifier: Modifier = base

        if (hasPressFeedback) {
            modifier = modifier.graphicsLayer {
                scaleX = pressAnimScale
                scaleY = pressAnimScale
                alpha = pressAnimAlpha
                // dp → px to match iOS's point-based offset.
                translationY = pressAnimTy.dp.toPx()
            }
        }

        if (needsAnimLayer) {
            modifier = modifier.graphicsLayer {
                if (applyAnimAlpha) alpha = animAlpha
                // PHP-side values are logical/dp units (matching iOS
                // points). Compose's `translationX/Y` take raw pixels,
                // so convert here. Without this every Android-side
                // translation is `1/density`× too small.
                translationX = animTx.dp.toPx()
                translationY = animTy.dp.toPx()
                scaleX = animScale
                scaleY = animScale
                rotationZ = animRotate
            }
        }

        modifier = modifier.nodeStyle(node.style, node.props, isDarkMode)

        if (hasPress && radius > 0f) {
            modifier = modifier.clip(RoundedCornerShape(radius.dp))
        }
        modifier = modifier
            .nodeGestures(node, interactionSource)
            .nodeLayout(node.layout, safeAreaTop, safeAreaBottom, availableWidth, availableHeight)

        if (!NativeNodeDecoratorRegistry.isEmpty()) {
            modifier = NativeNodeDecoratorRegistry.apply(node, modifier)
        }

        // In-place text change animation (`content_transition` — numeric
        // roll / crossfade). Wraps the content in AnimatedContent keyed on
        // the text prop; absent prop → straight render, hot path unchanged.
        val contentTransition = node.props.getString("content_transition", "")

        val renderContent: @Composable () -> Unit = {
            if (contentTransition.isNotEmpty()) {
                NodeContentTransition(node, contentTransition, modifier)
            } else if (renderer != null) {
                renderer.Render(node, modifier)
            } else {
                DefaultContainerNode(node, modifier)
            }
        }

        // Opt-in text selection (`select-text` / `select-none`). SelectionContainer
        // makes the whole wrapped subtree one selectable scope (drag handles +
        // Copy toolbar); DisableSelection carves a subtree back out inside a
        // selectable ancestor. Absent prop → rendered as-is (inherits ancestor).
        when (if (node.props.has("selectable")) node.props.getInt("selectable") else -1) {
            1 -> SelectionContainer { renderContent() }
            0 -> DisableSelection { renderContent() }
            else -> renderContent()
        }
    }
}

/**
 * Default container renderer — renders children using Compose Column/Row.
 * Used as fallback for unknown types and by container renderers.
 */
@Composable
fun DefaultContainerNode(node: NativeUINode, modifier: Modifier = Modifier) {
    if (node.children.isEmpty()) {
        Box(modifier = modifier)
    } else {
        val direction = when (node.type) {
            "row" -> FlexDirection.ROW
            else -> node.layout?.flexDirection ?: FlexDirection.COLUMN
        }

        FlexContainer(
            direction = direction,
            justify = node.layout?.justifyContent ?: JustifyContent.START,
            align = node.layout?.alignItems ?: AlignItems.STRETCH,
            gap = node.layout?.gap ?: 0f,
            wrap = node.layout?.flexWrap ?: 0,
            childNodes = node.children,
            modifier = modifier
        ) {
            // Content is rendered by FlexContainer itself via NodeView calls
        }
    }
}
