package com.nativephp.mobile.ui.nativerender

import androidx.compose.material3.MaterialTheme
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.getUnclippedBoundsInRoot
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * The top-bar search field: `search_*` on `native_root_stack`, and folded
 * onto `native_root_tabs` as `nav_search_*` when the screen also has a
 * bottom nav (#469).
 */
@RunWith(AndroidJUnit4::class)
class NativeRootSearchBarTest {
    @get:Rule
    val composeRule = createComposeRule()

    @Before
    fun setUp() {
        NavigationCoordinator.reset()
    }

    @Test
    fun tabsSearchFieldTakesTheTitleSlotWhenTheBarHasNoTitle() {
        render(tabsNode())

        composeRule.onNodeWithText(PLACEHOLDER).assertIsDisplayed()
    }

    @Test
    fun tabsSearchFieldSitsUnderATitledBar() {
        render(tabsNode(title = TITLE))

        composeRule.onNodeWithText(TITLE).assertIsDisplayed()
        composeRule.onNodeWithText(PLACEHOLDER).assertIsDisplayed()
        val titleBottom = composeRule.onNodeWithText(TITLE).getUnclippedBoundsInRoot().bottom
        val fieldTop = composeRule.onNodeWithText(PLACEHOLDER).getUnclippedBoundsInRoot().top
        assertTrue(fieldTop >= titleBottom)
    }

    @Test
    fun tabsSearchFieldSitsUnderACustomTitleView() {
        render(tabsNode(withTitleView = true))

        composeRule.onNodeWithText(PLACEHOLDER).assertIsDisplayed()
    }

    @Test
    fun stackSearchFieldSitsUnderACustomTitleView() {
        composeRule.setContent {
            MaterialTheme {
                NativeRootStackRenderer(
                    node(
                        type = "native_root_stack",
                        props = mapOf("current_uri" to "/home", "search_placeholder" to PLACEHOLDER),
                        children = listOf(node(type = "top_bar_title")),
                    )
                )
            }
        }

        composeRule.onNodeWithText(PLACEHOLDER).assertIsDisplayed()
    }

    @Test
    fun aSearchRoleTabKeepsTheSearchOutOfTheTopBar() {
        render(tabsNode(title = TITLE, withSearchTab = true))

        composeRule.onNodeWithText(TITLE).assertIsDisplayed()
        composeRule.onNodeWithText(PLACEHOLDER).assertDoesNotExist()

        composeRule.onNodeWithText(SEARCH_TAB_LABEL).performClick()

        composeRule.onNodeWithText(PLACEHOLDER).assertIsDisplayed()
    }

    private fun render(root: NativeUINode) {
        composeRule.setContent {
            MaterialTheme {
                NativeRootTabsRenderer(root)
            }
        }
    }

    private fun tabsNode(
        title: String = "",
        withTitleView: Boolean = false,
        withSearchTab: Boolean = false,
    ): NativeUINode {
        val tabs = mutableListOf(
            node(type = "bottom_nav_item", props = mapOf("id" to "home", "label" to "Home", "url" to "/home", "active" to true)),
            node(type = "bottom_nav_item", props = mapOf("id" to "contacts", "label" to "Contacts", "url" to "/contacts")),
        )
        if (withSearchTab) {
            tabs += node(type = "bottom_nav_item", props = mapOf("id" to "search", "label" to SEARCH_TAB_LABEL, "search" to true))
        }
        val props = mutableMapOf<String, Any>(
            "current_uri" to "/home",
            "nav_search_placeholder" to PLACEHOLDER,
            "nav_search_debounce_ms" to 300,
        )
        if (title.isNotEmpty()) props["nav_title"] = title
        // The PHP side sets this only when a search-role tab exists; dynamic
        // mode keeps that tab visible with no items.
        if (withSearchTab) props["nav_search_mode"] = "dynamic"
        val titleView = if (withTitleView) listOf(node(type = "top_bar_title")) else emptyList()
        return node(type = "native_root_tabs", props = props, children = tabs + titleView)
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
        const val TITLE = "People"
        const val PLACEHOLDER = "Find a contact"
        const val SEARCH_TAB_LABEL = "Search"
    }
}
