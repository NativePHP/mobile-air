package com.nativephp.mobile.ui.nativerender

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class NodeAccessibilityTest {
    @Test
    fun labelledPressableIsDescribedByItsLabel() {
        assertEquals("Open uptime", NodeAccessibility.description("pressable", "Open uptime", "", hasMenu = false))
    }

    @Test
    fun labelledContainersAreDescribedByTheirLabel() {
        listOf("column", "row", "stack").forEach { type ->
            assertEquals(
                "Events: 5, last 10h ago",
                NodeAccessibility.description(type, "Events: 5, last 10h ago", "", hasMenu = false),
            )
        }
    }

    @Test
    fun hintIsAppendedToTheLabel() {
        assertEquals(
            "Open settings. Opens the settings screen",
            NodeAccessibility.description("pressable", "Open settings", "Opens the settings screen", hasMenu = false),
        )
    }

    @Test
    fun unlabelledNodesKeepTheirChildren() {
        assertNull(NodeAccessibility.description("pressable", "", "", hasMenu = false))
        assertNull(NodeAccessibility.description("column", "", "Ignored without a label", hasMenu = false))
    }

    @Test
    fun otherTypesAreLeftToTheirRenderers() {
        assertNull(NodeAccessibility.description("button", "Save", "", hasMenu = false))
        assertNull(NodeAccessibility.description("icon", "Settings", "", hasMenu = false))
        assertNull(NodeAccessibility.description("text_input", "Email", "", hasMenu = false))
    }

    @Test
    fun menuPressableIsLeftToItsRenderer() {
        assertNull(NodeAccessibility.description("pressable", "More", "", hasMenu = true))
        assertFalse(NodeAccessibility.isButton("pressable", hasPress = true, hasMenu = true))
    }

    @Test
    fun onlyATappablePressableIsAButton() {
        assertTrue(NodeAccessibility.isButton("pressable", hasPress = true, hasMenu = false))
        assertFalse(NodeAccessibility.isButton("pressable", hasPress = false, hasMenu = false))
        assertFalse(NodeAccessibility.isButton("column", hasPress = true, hasMenu = false))
        assertFalse(NodeAccessibility.isButton("row", hasPress = true, hasMenu = false))
    }
}
