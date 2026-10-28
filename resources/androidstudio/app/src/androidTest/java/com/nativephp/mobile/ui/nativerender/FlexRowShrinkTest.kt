package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.runtime.MutableState
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.assertLeftPositionInRootIsEqualTo
import androidx.compose.ui.test.assertWidthIsEqualTo
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.unit.dp
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

class FlexRowShrinkTest {
    @get:Rule
    val composeRule = createComposeRule()

    @Test
    fun fixedWidthChildAfterAChildThatTakesTheWholeLineKeepsItsWidth() {
        composeRule.setContent {
            Box(Modifier.width(300.dp)) {
                FlexContainer(
                    direction = FlexDirection.ROW,
                    gap = 4f,
                    childNodes = listOf(lineFiller(id = 1, ref = "bubble"), slot(id = 3, ref = "slot", width = 98f)),
                    content = {},
                )
            }
        }

        composeRule.onNodeWithTag("slot").assertWidthIsEqualTo(98.dp)
        composeRule.onNodeWithTag("bubble").assertWidthIsEqualTo(198.dp)
        composeRule.onNodeWithTag("slot").assertLeftPositionInRootIsEqualTo(202.dp)
    }

    @Test
    fun contentSizedShrinkZeroChildKeepsItsWidth() {
        composeRule.setContent {
            Box(Modifier.width(300.dp)) {
                FlexContainer(
                    direction = FlexDirection.ROW,
                    gap = 8f,
                    childNodes = listOf(
                        lineFiller(id = 1, ref = "text"),
                        node(id = 3, type = "column", layout = layout(shrink = 0f), ref = "time", children = listOf(sized(id = 4, width = 60f))),
                    ),
                    content = {},
                )
            }
        }

        composeRule.onNodeWithTag("time").assertWidthIsEqualTo(60.dp)
        composeRule.onNodeWithTag("text").assertWidthIsEqualTo(232.dp)
    }

    @Test
    fun rowThatFitsIsArrangedAsBefore() {
        composeRule.setContent {
            Box(Modifier.width(300.dp)) {
                FlexContainer(
                    direction = FlexDirection.ROW,
                    justify = JustifyContent.END,
                    gap = 4f,
                    childNodes = listOf(
                        node(id = 1, type = "column", layout = layout(), ref = "short", children = listOf(sized(id = 2, width = 50f))),
                        slot(id = 3, ref = "slot", width = 98f),
                    ),
                    modifier = Modifier.width(300.dp),
                    content = {},
                )
            }
        }

        composeRule.onNodeWithTag("short").assertWidthIsEqualTo(50.dp)
        composeRule.onNodeWithTag("short").assertLeftPositionInRootIsEqualTo(148.dp)
        composeRule.onNodeWithTag("slot").assertLeftPositionInRootIsEqualTo(202.dp)
    }

    @Test
    fun growChildTakesWhatTheOthersLeave() {
        composeRule.setContent {
            Box(Modifier.width(300.dp)) {
                FlexContainer(
                    direction = FlexDirection.ROW,
                    childNodes = listOf(
                        node(id = 1, type = "column", layout = layout(), ref = "short", children = listOf(sized(id = 2, width = 50f))),
                        node(id = 3, type = "column", layout = layout(grow = 1f, heightMode = SizeMode.FIXED, height = 10f), ref = "grow"),
                        slot(id = 4, ref = "slot", width = 40f),
                    ),
                    modifier = Modifier.width(300.dp),
                    content = {},
                )
            }
        }

        composeRule.onNodeWithTag("grow").assertWidthIsEqualTo(210.dp)
        composeRule.onNodeWithTag("slot").assertLeftPositionInRootIsEqualTo(260.dp)
    }

    @Test
    fun rowWithNoWidthLimitKeepsEveryChildsWidth() {
        composeRule.setContent {
            Box(Modifier.width(300.dp).horizontalScroll(rememberScrollState())) {
                FlexContainer(
                    direction = FlexDirection.ROW,
                    childNodes = listOf(
                        node(id = 1, type = "column", layout = layout(), ref = "wide", children = listOf(sized(id = 2, width = 400f))),
                        slot(id = 3, ref = "slot", width = 98f),
                    ),
                    content = {},
                )
            }
        }

        composeRule.onNodeWithTag("wide").assertWidthIsEqualTo(400.dp)
        composeRule.onNodeWithTag("slot").assertWidthIsEqualTo(98.dp)
    }

    @Test
    fun childrenKeepTheirStateWhenTheSiblingThatKeepsItsWidthComesAndGoes() {
        var probesCreated = 0
        NativeRendererRegistry.register("flex_row_shrink_probe") { _, modifier ->
            remember { probesCreated++ }
            Box(modifier.size(10.dp))
        }
        lateinit var showsSlot: MutableState<Boolean>

        composeRule.setContent {
            showsSlot = remember { mutableStateOf(true) }
            val probe = node(id = 1, type = "flex_row_shrink_probe", layout = layout(), ref = "probe")
            Box(Modifier.width(300.dp)) {
                FlexContainer(
                    direction = FlexDirection.ROW,
                    childNodes = if (showsSlot.value) listOf(probe, slot(id = 2, ref = "slot", width = 98f)) else listOf(probe),
                    content = {},
                )
            }
        }

        composeRule.runOnIdle { showsSlot.value = false }
        composeRule.runOnIdle { showsSlot.value = true }
        composeRule.waitForIdle()

        assertEquals(1, probesCreated)
    }

    /** A content-sized column whose only child is `w-full`, so it takes the whole width it's offered. */
    private fun lineFiller(id: Int, ref: String): NativeUINode = node(
        id = id,
        type = "column",
        layout = layout(),
        ref = ref,
        children = listOf(node(id = id + 1, type = "column", layout = layout(widthMode = SizeMode.FILL, heightMode = SizeMode.FIXED, height = 20f))),
    )

    /** `w-[n] flex-shrink-0`, like the action slot beside a chat bubble. */
    private fun slot(id: Int, ref: String, width: Float): NativeUINode = node(
        id = id,
        type = "row",
        layout = layout(widthMode = SizeMode.FIXED, width = width, heightMode = SizeMode.FIXED, height = 30f, shrink = 0f),
        ref = ref,
    )

    private fun sized(id: Int, width: Float): NativeUINode = node(
        id = id,
        type = "column",
        layout = layout(widthMode = SizeMode.FIXED, width = width, heightMode = SizeMode.FIXED, height = 20f),
    )

    /** flex-shrink defaults to 1, as the PHP extension packs it for every node. */
    private fun layout(
        widthMode: Int = SizeMode.WRAP,
        width: Float = 0f,
        heightMode: Int = SizeMode.WRAP,
        height: Float = 0f,
        grow: Float = 0f,
        shrink: Float = 1f,
    ): NodeLayout = NodeLayout.EMPTY.copy(
        width = width,
        widthMode = widthMode,
        height = height,
        heightMode = heightMode,
        flexGrow = grow,
        flexShrink = shrink,
    )

    private fun node(
        id: Int,
        type: String,
        layout: NodeLayout,
        ref: String = "",
        children: List<NativeUINode> = emptyList(),
    ): NativeUINode = NativeUINode(
        id = id,
        type = type,
        layout = layout,
        style = null,
        props = GenericProps(if (ref.isEmpty()) emptyMap() else mapOf("ref" to ref)),
        onPress = 0,
        onLongPress = 0,
        children = children,
    )
}
