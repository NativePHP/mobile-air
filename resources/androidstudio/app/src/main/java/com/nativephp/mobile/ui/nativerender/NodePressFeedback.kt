package com.nativephp.mobile.ui.nativerender

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.spring
import androidx.compose.foundation.interaction.InteractionSource
import androidx.compose.foundation.interaction.PressInteraction
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.Measurable
import androidx.compose.ui.layout.MeasureResult
import androidx.compose.ui.layout.MeasureScope
import androidx.compose.ui.node.LayoutModifierNode
import androidx.compose.ui.node.ModifierNodeElement
import androidx.compose.ui.platform.InspectorInfo
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch

/**
 * Native-thread press feedback (`press-scale`, `press-opacity`,
 * `press-translate-y`): while [interactionSource] reports a press, the node
 * springs towards the pressed values, and back when it's released. A value of
 * 0 (or 0 translate) leaves that channel alone.
 *
 * A modifier node rather than composable calls in NodeView: its state lives
 * in the modifier chain, which Compose matches by element type, so a node
 * that gains or loses feedback between renders adds or drops this node
 * without moving any of NodeView's remembered state.
 */
fun Modifier.pressFeedback(
    interactionSource: InteractionSource,
    scale: Float,
    opacity: Float,
    translateY: Float,
): Modifier = this then PressFeedbackElement(interactionSource, scale, opacity, translateY)

private data class PressFeedbackElement(
    val interactionSource: InteractionSource,
    val scale: Float,
    val opacity: Float,
    val translateY: Float,
) : ModifierNodeElement<PressFeedbackNode>() {
    override fun create() = PressFeedbackNode(interactionSource, scale, opacity, translateY)

    override fun update(node: PressFeedbackNode) {
        node.update(interactionSource, scale, opacity, translateY)
    }

    override fun InspectorInfo.inspectableProperties() {
        name = "pressFeedback"
        properties["scale"] = scale
        properties["opacity"] = opacity
        properties["translateY"] = translateY
    }
}

private class PressFeedbackNode(
    private var interactionSource: InteractionSource,
    scale: Float,
    opacity: Float,
    translateY: Float,
) : Modifier.Node(), LayoutModifierNode {
    // State, so the layer block below redraws when PHP changes a value.
    private var scale by mutableFloatStateOf(scale)
    private var opacity by mutableFloatStateOf(opacity)
    private var translateY by mutableFloatStateOf(translateY)

    // 0 released, 1 pressed. Each channel is linear in it, so one spring
    // moves all three exactly as one spring per channel would.
    private val pressed = Animatable(0f)
    private val springSpec = spring<Float>(dampingRatio = Spring.DampingRatioMediumBouncy, stiffness = Spring.StiffnessMedium)
    private var collecting: Job? = null

    override fun onAttach() {
        collectPresses()
    }

    fun update(interactionSource: InteractionSource, scale: Float, opacity: Float, translateY: Float) {
        this.scale = scale
        this.opacity = opacity
        this.translateY = translateY

        if (this.interactionSource != interactionSource) {
            this.interactionSource = interactionSource
            collectPresses()
        }
    }

    private fun collectPresses() {
        collecting?.cancel()
        collecting = coroutineScope.launch {
            val presses = mutableListOf<PressInteraction.Press>()
            var animation: Job? = null

            interactionSource.interactions.collect { interaction ->
                when (interaction) {
                    is PressInteraction.Press -> presses.add(interaction)
                    is PressInteraction.Release -> presses.remove(interaction.press)
                    is PressInteraction.Cancel -> presses.remove(interaction.press)
                }

                val target = if (presses.isEmpty()) 0f else 1f
                if (pressed.targetValue != target) {
                    animation?.cancel()
                    animation = launch { pressed.animateTo(target, springSpec) }
                }
            }
        }
    }

    override fun MeasureScope.measure(measurable: Measurable, constraints: Constraints): MeasureResult {
        val placeable = measurable.measure(constraints)

        return layout(placeable.width, placeable.height) {
            placeable.placeWithLayer(0, 0) {
                val t = pressed.value
                val s = if (scale > 0f) 1f + (scale - 1f) * t else 1f
                scaleX = s
                scaleY = s
                alpha = if (opacity > 0f) 1f + (opacity - 1f) * t else 1f
                // dp → px to match iOS's point-based offset.
                translationY = (translateY * t).dp.toPx()
            }
        }
    }
}
