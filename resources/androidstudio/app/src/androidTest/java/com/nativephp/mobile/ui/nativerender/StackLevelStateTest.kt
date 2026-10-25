package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.clickable
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Before
import org.junit.BeforeClass
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * A screen's remembered state (a list's scroll position, through
 * `rememberLazyListState()`) survives a screen pushed over it and popped
 * again, and a popped screen pushed again starts fresh. The probe stands in
 * for a list: it counts its taps in `rememberSaveable`.
 */
@RunWith(AndroidJUnit4::class)
class StackLevelStateTest {
    @get:Rule
    val composeRule = createComposeRule()

    @Before
    fun setUp() {
        NavigationCoordinator.reset()
    }

    @Test
    fun stackKeepsALevelsStateUnderAPushAndDropsAPoppedLevels() {
        val root = mutableStateOf(screen("native_root_stack", "/list", depth = 1, label = "list"))
        composeRule.setContent { MaterialTheme { NativeRootStackRenderer(root.value) } }

        assertKeepsAndDrops { uri, depth, label -> root.value = screen("native_root_stack", uri, depth, label) }
    }

    @Test
    fun tabsKeepAScreensStateUnderAPushAndDropAPoppedScreens() {
        val root = mutableStateOf(screen("native_root_tabs", "/list", depth = 1, label = "list"))
        composeRule.setContent { MaterialTheme { NativeRootTabsRenderer(root.value) } }

        assertKeepsAndDrops { uri, depth, label -> root.value = screen("native_root_tabs", uri, depth, label) }
    }

    private fun assertKeepsAndDrops(publish: (uri: String, depth: Int, label: String) -> Unit) {
        tap("list 0", times = 3)

        composeRule.runOnIdle { publish("/detail", 2, "detail") }
        tap("detail 0", times = 2)

        // PHP pops back to the list: it is where it was left.
        composeRule.runOnIdle { publish("/list", 1, "list") }
        composeRule.onNodeWithText("list 3").assertExists()

        // The detail was popped, so pushing it again starts it fresh.
        composeRule.runOnIdle { publish("/detail", 2, "detail") }
        composeRule.onNodeWithText("detail 0").assertExists()
    }

    private fun tap(text: String, times: Int) {
        val label = text.substringBefore(' ')
        repeat(times) { i -> composeRule.onNodeWithText("$label $i").performClick() }
        composeRule.onNodeWithText("$label $times").assertExists()
    }

    private fun screen(type: String, uri: String, depth: Int, label: String) = node(
        id = 1,
        type = type,
        props = mapOf("current_uri" to uri, "stack_depth" to depth, "title" to uri),
        // A new id per screen, as PHP's publish gives one.
        children = listOf(node(id = uri.hashCode(), type = PROBE, props = mapOf("label" to label))),
    )

    private fun node(
        id: Int,
        type: String,
        props: Map<String, Any>,
        children: List<NativeUINode> = emptyList(),
    ) = NativeUINode(
        id = id,
        type = type,
        layout = null,
        style = null,
        props = GenericProps(props),
        onPress = 0,
        onLongPress = 0,
        children = children,
    )

    private companion object {
        const val PROBE = "stack_level_state_test_probe"

        @BeforeClass
        @JvmStatic
        fun registerProbe() {
            NativeRendererRegistry.register(PROBE) { node, modifier ->
                var count by rememberSaveable { mutableIntStateOf(0) }
                Text(
                    "${node.props.getString("label", "")} $count",
                    modifier = modifier.clickable { count++ },
                )
            }
        }
    }
}
