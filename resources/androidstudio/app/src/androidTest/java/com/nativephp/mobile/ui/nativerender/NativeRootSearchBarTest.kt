package com.nativephp.mobile.ui.nativerender

import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.MutableState
import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.getUnclippedBoundsInRoot
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.text.AnnotatedString
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

    // PHP keeps a pushed-over screen alive and shows it again on back, so
    // the field shows each level's own query.
    @Test
    fun stackSearchQueryBelongsToItsLevel() {
        val root = mutableStateOf(stackNode(uri = "/people", depth = 1))
        composeRule.setContent {
            MaterialTheme {
                NativeRootStackRenderer(root.value)
            }
        }

        typeQuery("ali")
        publish(root, stackNode(uri = "/people/teams", depth = 2))
        assertQuery("")
        typeQuery("bob")
        publish(root, stackNode(uri = "/people", depth = 1))
        assertQuery("ali")
        // The popped level's query went with it.
        publish(root, stackNode(uri = "/people/teams", depth = 2))
        assertQuery("")
    }

    @Test
    fun tabsSearchQueryBelongsToItsLevel() {
        val root = mutableStateOf(tabsNode(title = TITLE))
        render(root)

        typeQuery("ali")
        publish(root, tabsNode(title = TITLE, uri = "/home/teams", depth = 2))
        assertQuery("")
        typeQuery("bob")
        publish(root, tabsNode(title = TITLE))
        assertQuery("ali")
        publish(root, tabsNode(title = TITLE, uri = "/home/teams", depth = 2))
        assertQuery("")
        // A tab change replaces the stack, so coming back starts empty too.
        publish(root, tabsNode(title = TITLE))
        publish(root, tabsNode(title = TITLE, uri = "/contacts", activeTab = "contacts"))
        assertQuery("")
        publish(root, tabsNode(title = TITLE))
        assertQuery("")
    }

    private fun typeQuery(text: String) {
        composeRule.onNode(hasSetTextAction()).performTextInput(text)
    }

    private fun assertQuery(text: String) {
        composeRule.onNode(hasSetTextAction())
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.EditableText, AnnotatedString(text)))
    }

    private fun publish(root: MutableState<NativeUINode>, node: NativeUINode) {
        composeRule.runOnIdle { root.value = node }
        composeRule.waitForIdle()
    }

    private fun render(root: NativeUINode) = render(mutableStateOf(root))

    private fun render(root: MutableState<NativeUINode>) {
        composeRule.setContent {
            MaterialTheme {
                NativeRootTabsRenderer(root.value)
            }
        }
    }

    private fun stackNode(uri: String, depth: Int) = node(
        type = "native_root_stack",
        props = mapOf(
            "title" to TITLE,
            "current_uri" to uri,
            "stack_depth" to depth,
            "search_placeholder" to PLACEHOLDER,
        ),
    )

    private fun tabsNode(
        title: String = "",
        withTitleView: Boolean = false,
        withSearchTab: Boolean = false,
        uri: String = "/home",
        depth: Int = 1,
        activeTab: String = "home",
    ): NativeUINode {
        val tabs = mutableListOf(
            node(type = "bottom_nav_item", props = mapOf("id" to "home", "label" to "Home", "url" to "/home", "active" to (activeTab == "home"))),
            node(type = "bottom_nav_item", props = mapOf("id" to "contacts", "label" to "Contacts", "url" to "/contacts", "active" to (activeTab == "contacts"))),
        )
        if (withSearchTab) {
            tabs += node(type = "bottom_nav_item", props = mapOf("id" to "search", "label" to SEARCH_TAB_LABEL, "search" to true))
        }
        val props = mutableMapOf<String, Any>(
            "current_uri" to uri,
            "stack_depth" to depth,
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
