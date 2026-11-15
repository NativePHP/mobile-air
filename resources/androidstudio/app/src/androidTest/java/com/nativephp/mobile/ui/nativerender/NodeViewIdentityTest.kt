package com.nativephp.mobile.ui.nativerender

import androidx.compose.runtime.MutableState
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performTouchInput
import org.junit.Rule
import org.junit.Test

class NodeViewIdentityTest {
    @get:Rule
    val composeRule = createComposeRule()

    @Test
    fun positionalIdReusedByDifferentNodeTypeDoesNotReuseComposeState() {
        lateinit var showsConditionalChildren: MutableState<Boolean>

        composeRule.setContent {
            showsConditionalChildren = remember { mutableStateOf(true) }

            FlexContainer(
                childNodes = if (showsConditionalChildren.value) initialChildren() else reducedChildren(),
                content = {},
            )
        }

        composeRule.runOnIdle {
            showsConditionalChildren.value = false
        }
        composeRule.waitForIdle()
    }

    // Each prop NodeView branches on, with a value that turns its branch on.
    // A node keeps its id and type, and so its Compose state, when only its
    // props change, so turning any of these on or off must not move
    // NodeView's remembered state (#647: press feedback did).
    private val branchingProps: Map<String, Map<String, Any>> = mapOf(
        "press-scale" to mapOf("press-scale" to 0.9f),
        "press-opacity" to mapOf("press-opacity" to 0.5f),
        "press-translate-y" to mapOf("press-translate-y" to 2f),
        "animate-duration" to mapOf("animate-duration" to 300f),
        "animate-loop" to mapOf("animate-loop" to true, "animate-duration" to 300f),
        "animate-easing spring" to mapOf("animate-easing" to "spring", "animate-duration" to 300f),
        "translate-x" to mapOf("translate-x" to 4f),
        "scale" to mapOf("scale" to 1.1f),
        "rotate" to mapOf("rotate" to 5f),
        "dark_opacity" to mapOf("dark_opacity" to 0.5f),
        "opacity_sv" to mapOf("opacity_sv" to "__sv:9001"),
        "translate-x_sv" to mapOf("translate-x_sv" to "__sv:9002"),
        "scale_sv" to mapOf("scale_sv" to "__sv:9003"),
        "content_transition" to mapOf("content_transition" to "crossfade", "text" to "1"),
        "select-text" to mapOf("selectable" to 1),
        "select-none" to mapOf("selectable" to 0),
        "ref" to mapOf("ref" to "target"),
    )

    @Test
    fun nodeKeepsComposingWhenAnyBranchingPropTurnsOnOrOff() {
        val all = branchingProps.values.fold(emptyMap<String, Any>()) { acc, props -> acc + props }
        val cases = (branchingProps + ("all of them" to all)).toList()
        lateinit var shown: MutableState<NativeUINode>

        composeRule.setContent {
            shown = remember { mutableStateOf(pressable(emptyMap(), onPress = 1)) }
            FlexContainer(childNodes = listOf(shown.value), content = {})
        }

        cases.forEachIndexed { index, (name, props) ->
            for (onPress in listOf(1, 0)) {
                // A new id per case, so each starts from fresh Compose state.
                val id = 10 + index * 2 + onPress
                for (step in listOf(emptyMap(), props, emptyMap())) {
                    try {
                        composeRule.runOnIdle { shown.value = pressable(step, onPress, id) }
                        composeRule.waitForIdle()
                    } catch (e: Throwable) {
                        throw AssertionError("Turning $name on and off with onPress=$onPress", e)
                    }
                }
            }
        }
    }

    @Test
    fun nodeKeepsComposingWhenPressFeedbackStopsDuringAPress() {
        lateinit var props: MutableState<Map<String, Any>>

        composeRule.setContent {
            props = remember { mutableStateOf(mapOf("press-scale" to 0.9f, "ref" to "target")) }
            FlexContainer(childNodes = listOf(pressable(props.value, onPress = 1)), content = {})
        }

        composeRule.onNodeWithTag("target").performTouchInput { down(center) }
        composeRule.runOnIdle { props.value = mapOf("ref" to "target") }
        // Cancel rather than lift: a lift is a tap, which goes to PHP.
        composeRule.onNodeWithTag("target").performTouchInput { cancel() }
        composeRule.waitForIdle()
    }

    private fun pressable(props: Map<String, Any>, onPress: Int, id: Int = 1): NativeUINode = NativeUINode(
        id = id,
        type = "pressable",
        layout = null,
        style = null,
        props = GenericProps(props),
        onPress = onPress,
        onLongPress = 0,
        children = listOf(node(id = id + 100_000, type = "text")),
    )

    private fun initialChildren(): List<NativeUINode> = listOf(
        node(id = 1, type = "text"),
        node(id = 2, type = "text"),
        node(id = 3, type = "pressable", pressScale = 0.98f),
        node(id = 4, type = "pressable", pressScale = 0.98f),
        node(id = 5, type = "pressable", pressScale = 0.98f),
    )

    private fun reducedChildren(): List<NativeUINode> = listOf(
        node(id = 1, type = "text"),
        node(id = 2, type = "pressable", pressScale = 0.98f),
    )

    private fun node(id: Int, type: String, pressScale: Float? = null): NativeUINode = NativeUINode(
        id = id,
        type = type,
        layout = null,
        style = null,
        props = GenericProps(pressScale?.let { mapOf("press-scale" to it) } ?: emptyMap()),
        onPress = if (pressScale == null) 0 else 1,
        onLongPress = 0,
        children = emptyList(),
    )
}
