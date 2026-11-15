package com.nativephp.mobile.ui.nativerender

import androidx.compose.runtime.MutableState
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.test.junit4.createComposeRule
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

    @Test
    fun nodeThatGainsPressFeedbackKeepsComposing() {
        assertPressFeedbackFlipComposes(from = 0f, to = 0.9f)
    }

    @Test
    fun nodeThatLosesPressFeedbackKeepsComposing() {
        assertPressFeedbackFlipComposes(from = 0.9f, to = 0f)
    }

    // Same id, same type, only press-scale changes, so key(node.id, node.type)
    // keeps the node's Compose state across the re-render.
    private fun assertPressFeedbackFlipComposes(from: Float, to: Float) {
        lateinit var pressScale: MutableState<Float>

        composeRule.setContent {
            pressScale = remember { mutableStateOf(from) }

            FlexContainer(
                childNodes = listOf(node(id = 1, type = "pressable", pressScale = pressScale.value)),
                content = {},
            )
        }

        composeRule.runOnIdle {
            pressScale.value = to
        }
        composeRule.waitForIdle()
    }

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
