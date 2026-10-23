package com.nativephp.mobile.ui.nativerender

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class NativeRootHostRegistryTest {
    @Test
    fun reservesNavigationBarSlotOnlyWhileTheConsumedSentinelIsPresent() {
        val sentinelType = "navigation_slot_test_sentinel"
        NativeRootHostRegistry.register(
            name = "navigation-slot-test-host",
            consumes = sentinelType,
            reservesNavigationBarSlot = true,
        ) { _, content -> content() }

        assertFalse(NativeRootHostRegistry.reservesNavigationBarSlot(node()))
        assertTrue(
            NativeRootHostRegistry.reservesNavigationBarSlot(
                node(children = listOf(node(type = sentinelType))),
            ),
        )
    }

    @Test
    fun doesNotReserveNavigationBarSlotWithoutTheHostDeclaration() {
        val sentinelType = "non_reserving_navigation_slot_test_sentinel"
        NativeRootHostRegistry.register(
            name = "non-reserving-navigation-slot-test-host",
            consumes = sentinelType,
        ) { _, content -> content() }

        assertFalse(
            NativeRootHostRegistry.reservesNavigationBarSlot(
                node(children = listOf(node(type = sentinelType))),
            ),
        )
    }

    private fun node(
        type: String = "native_root_stack",
        children: List<NativeUINode> = emptyList(),
    ) = NativeUINode(
        id = 1,
        type = type,
        layout = null,
        style = null,
        props = GenericProps(),
        onPress = 0,
        onLongPress = 0,
        children = children,
    )
}
