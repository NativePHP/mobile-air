package com.nativephp.mobile.ui.nativerender

import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.test.getUnclippedBoundsInRoot
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.unit.LayoutDirection
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.BeforeClass
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class NativeRootNavigationBarSlotTest {
    @get:Rule
    val composeRule = createComposeRule()

    @Before
    fun setUp() {
        NavigationCoordinator.reset()
    }

    @Test
    fun stackMovesTitleForActiveHostWithoutAddingSpaceToBackButton() {
        val title = "Stack navigation title"
        val root = mutableStateOf(stackNode(title = title))

        composeRule.setContent {
            MaterialTheme {
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    NativeRootStackRenderer(root.value)
                }
            }
        }

        assertNavigationSlotBehavior(
            title = title,
            showReservedSlot = { root.value = stackNode(title = title, hasDrawer = true) },
            showBackButton = { root.value = stackNode(title = title, hasDrawer = true, hasBackButton = true) },
        )
    }

    @Test
    fun tabsMoveTitleForActiveHostWithoutAddingSpaceToBackButton() {
        val title = "Tabs navigation title"
        val root = mutableStateOf(tabsNode(title = title))

        composeRule.setContent {
            MaterialTheme {
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    NativeRootTabsRenderer(root.value)
                }
            }
        }

        assertNavigationSlotBehavior(
            title = title,
            showReservedSlot = { root.value = tabsNode(title = title, hasDrawer = true) },
            showBackButton = { root.value = tabsNode(title = title, hasDrawer = true, hasBackButton = true) },
        )
    }

    private fun assertNavigationSlotBehavior(
        title: String,
        showReservedSlot: () -> Unit,
        showBackButton: () -> Unit,
    ) {
        val titleWithoutSlot = titleLeft(title)

        composeRule.runOnIdle(showReservedSlot)
        val titleWithReservedSlot = titleLeft(title)

        assertTrue(titleWithReservedSlot > titleWithoutSlot)
        assertTrue(titleWithReservedSlot >= NAVIGATION_SLOT_WIDTH_DP)

        composeRule.runOnIdle(showBackButton)
        val titleWithBackButton = titleLeft(title)

        assertEquals(titleWithReservedSlot, titleWithBackButton, POSITION_TOLERANCE_DP)
    }

    private fun titleLeft(title: String): Float = composeRule
        .onNodeWithText(title)
        .getUnclippedBoundsInRoot()
        .left
        .value

    private fun stackNode(
        title: String,
        hasDrawer: Boolean = false,
        hasBackButton: Boolean = false,
    ) = rootNode(
        type = "native_root_stack",
        props = mapOf(
            "title" to title,
            "current_uri" to "/navigation-slot-test",
            "back" to hasBackButton,
        ),
        hasDrawer = hasDrawer,
    )

    private fun tabsNode(
        title: String,
        hasDrawer: Boolean = false,
        hasBackButton: Boolean = false,
    ) = rootNode(
        type = "native_root_tabs",
        props = mapOf(
            "nav_title" to title,
            "current_uri" to "/navigation-slot-test",
            "nav_back" to hasBackButton,
        ),
        hasDrawer = hasDrawer,
    )

    private fun rootNode(
        type: String,
        props: Map<String, Any>,
        hasDrawer: Boolean,
    ) = node(
        type = type,
        props = props,
        children = if (hasDrawer) listOf(node(type = DRAWER_SENTINEL)) else emptyList(),
    )

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
        const val DRAWER_SENTINEL = "navigation_slot_layout_test_sentinel"
        const val NAVIGATION_SLOT_WIDTH_DP = 48f
        const val POSITION_TOLERANCE_DP = 0.5f

        @BeforeClass
        @JvmStatic
        fun registerNavigationBarSlotHost() {
            NativeRootHostRegistry.register(
                name = "navigation-bar-slot-layout-test-host",
                consumes = DRAWER_SENTINEL,
                reservesNavigationBarSlot = true,
            ) { _, content -> content() }
        }
    }
}
