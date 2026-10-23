package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.clickable
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
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
import org.junit.AfterClass
import org.junit.BeforeClass
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Actual state-restore proof for the per-mount saveable panes: a pane's
 * saveable state (the stand-in for a list's scroll offset) must survive
 * the pane being swapped away while its mount stays live in the
 * `screen_tokens` list, come back when its token is top again, and
 * RESET for a fresh mount of the same URI (new token). This exercises
 * the real renderer pipeline — AnimatedContent pane swap,
 * SaveableStateProvider keying, ledger eviction — not source text.
 */
@RunWith(AndroidJUnit4::class)
class ScreenTokenPaneStateTest {
    @get:Rule
    val composeRule = createComposeRule()

    private fun root(tokens: List<String>) = mutableStateOf(
        node(
            type = "native_root_tabs",
            props = mapOf(
                "screen_tokens" to tokens,
                "stack_depth" to tokens.size,
                "current_uri" to "/pane-test",
            ),
            children = listOf(node(type = PANE_COUNTER)),
        ),
    )

    @Test
    fun savedPaneStateSurvivesTheSwapAwayAndBackAndResetsForAFreshMount() {
        val feedToken = "f".repeat(32)
        val threadToken = "t".repeat(32)
        val tree = root(listOf(feedToken))

        composeRule.setContent {
            MaterialTheme {
                NativeRootTabsRenderer(tree.value)
            }
        }

        // The feed pane is live: "scroll" it (three increments).
        composeRule.onNodeWithText("count:0").performClick()
        composeRule.onNodeWithText("count:1").performClick()
        composeRule.onNodeWithText("count:2").performClick()
        composeRule.onNodeWithText("count:3").assertExists()

        // Push a thread over it: the feed's mount stays live (buried in
        // the token list), the thread pane is a FRESH mount at zero.
        tree.value = root(listOf(feedToken, threadToken)).value
        composeRule.waitForIdle()
        composeRule.onNodeWithText("count:0").assertExists()

        // popTo back to the feed: the SAME mount's saved state returns.
        tree.value = root(listOf(feedToken)).value
        composeRule.waitForIdle()
        composeRule.onNodeWithText("count:3").assertExists()

        // Fresh mount of the SAME URI (account switch / replace): a new
        // token is a new mount — its pane starts clean.
        val freshFeedToken = "n".repeat(32)
        tree.value = root(listOf(freshFeedToken)).value
        composeRule.waitForIdle()
        composeRule.onNodeWithText("count:0").assertExists()
    }

    private fun node(
        type: String,
        props: Map<String, Any> = emptyMap(),
        children: List<NativeUINode> = emptyList(),
    ) = NativeUINode(
        id = 1,
        type = type,
        layout = null,
        style = null,
        props = GenericProps(props),
        onPress = 0,
        onLongPress = 0,
        children = children,
    )

    private companion object {
        const val PANE_COUNTER = "screen_token_pane_state_test_counter"

        /** Stand-in for a saveable scroll offset: survives pane swaps. */
        @Composable
        fun PaneCounter() {
            var count by rememberSaveable { mutableIntStateOf(0) }
            Text(
                "count:$count",
                modifier = Modifier.clickable { count++ },
            )
        }

        @BeforeClass
        @JvmStatic
        fun registerCounterPane() {
            NativeRendererRegistry.register(PANE_COUNTER, NodeRenderer { _, _ -> PaneCounter() })
        }

        @AfterClass
        @JvmStatic
        fun unregisterCounterPane() {
            NativeRendererRegistry.register(PANE_COUNTER, NodeRenderer { _, _ -> })
        }
    }
}
