package com.nativephp.mobile.ui.nativerender

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
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

    private fun spec(type: String, vararg props: Pair<String, Any>, hasPress: Boolean = false) =
        NodeAccessibility.spec(type, GenericProps(mapOf(*props)), hasPress)

    private fun node(
        id: Int,
        type: String = "column",
        vararg children: NativeUINode,
        modal: Boolean = false,
        hidden: Boolean = false,
    ) =
        NativeUINode(
            id = id,
            type = type,
            layout = if (hidden) {
                NodeLayout(0f, 0, 0f, 0, 0f, 0f, 0f, 0f, 0f, 0f, 0f, 0f, 0f, 0f, 0, 0, 0, 0f, display = Display.NONE)
            } else {
                null
            },
            style = null,
            props = GenericProps(if (modal) mapOf("a11y_modal" to true) else emptyMap()),
            onPress = 0,
            onLongPress = 0,
            children = children.toList(),
        )

    @Test
    fun aRoleOverridesTheDefaultButton() {
        assertEquals("checkbox", NodeAccessibility.role("pressable", "checkbox", hasPress = true, hasMenu = false))
        assertEquals("button", NodeAccessibility.role("pressable", "", hasPress = true, hasMenu = false))
        assertNull(NodeAccessibility.role("pressable", "none", hasPress = true, hasMenu = false))
        assertEquals("header", NodeAccessibility.role("text", "header", hasPress = false, hasMenu = false))
        assertNull(NodeAccessibility.role("button", "checkbox", hasPress = true, hasMenu = false))
        assertNull(NodeAccessibility.role("pressable", "checkbox", hasPress = true, hasMenu = true))
    }

    @Test
    fun checkedMapsToToggleStateOnlyForCheckboxesAndSwitches() {
        assertEquals(NodeAccessibility.Toggle.On, spec("pressable", "a11y_role" to "checkbox", "a11y_checked" to "true")?.toggle)
        assertEquals(NodeAccessibility.Toggle.Off, spec("pressable", "a11y_role" to "switch", "a11y_checked" to "false")?.toggle)
        assertEquals(NodeAccessibility.Toggle.Indeterminate, spec("row", "a11y_role" to "checkbox", "a11y_checked" to "mixed")?.toggle)
        assertNull(spec("pressable", "a11y_role" to "radio", "a11y_checked" to "true")?.toggle)
    }

    @Test
    fun selectedFalseIsKeptApartFromUnset() {
        assertEquals(false, spec("pressable", "a11y_role" to "radio", "a11y_selected" to false, hasPress = true)?.selected)
        assertNull(spec("pressable", "a11y_role" to "radio", hasPress = true)?.selected)
    }

    @Test
    fun stateAndValueAreReadTogetherStateFirst() {
        assertEquals(
            "Downloading, 40%",
            spec("column", "a11y_role" to "progress", "a11y_state" to "Downloading", "a11y_value" to "40%")?.stateDescription,
        )
    }

    @Test
    fun aProgressNodeWithoutNumbersIsIndeterminate() {
        val busy = spec("column", "a11y_role" to "progress", "a11y_value" to "Loading")
        assertTrue(busy!!.indeterminate)
        assertNull(busy.range)

        val known = spec("column", "a11y_role" to "progress", "a11y_value_now" to 40f, "a11y_value_min" to 0f, "a11y_value_max" to 100f)
        assertFalse(known!!.indeterminate)
        assertEquals(NodeAccessibility.Range(40f, 0f, 100f), known.range)
    }

    @Test
    fun rangeBoundsAreOrderedAndTheValueClampedInside() {
        assertEquals(NodeAccessibility.Range(10f, 0f, 10f), NodeAccessibility.range(14f, 10f, 0f))
    }

    @Test
    fun adjustingCallsIncrementOrDecrementByDirection() {
        val slider = spec(
            "gesture_area",
            "a11y_role" to "adjustable",
            "a11y_value_now" to 40f,
            "on_a11y_increment" to 7,
            "on_a11y_decrement" to 8,
        )!!
        assertEquals(7, NodeAccessibility.adjustment(slider, 45f))
        assertEquals(8, NodeAccessibility.adjustment(slider, 35f))
        assertNull(NodeAccessibility.adjustment(slider, 40f))
    }

    @Test
    fun anAdjustableWithOnlySpokenTextStillAdjusts() {
        val stepper = spec("row", "a11y_role" to "adjustable", "a11y_value" to "3 guests", "on_a11y_increment" to 7, "on_a11y_decrement" to 8)!!
        assertEquals(NodeAccessibility.Range(0.5f, 0f, 1f), stepper.range)
        assertEquals(7, NodeAccessibility.adjustment(stepper, 0.55f))
        assertEquals("3 guests", stepper.stateDescription)
    }

    @Test
    fun adjustCallbacksAreIgnoredWithoutTheAdjustableRole() {
        val node = spec("row", "a11y_value_now" to 4f, "on_a11y_increment" to 7)!!
        assertNull(NodeAccessibility.adjustment(node, 5f))
    }

    @Test
    fun actionsPairLabelsWithCallbacksAndSkipBrokenEntries() {
        assertEquals(
            listOf(NodeAccessibility.Action("Archive", 12), NodeAccessibility.Action("Delete", 13)),
            NodeAccessibility.actions(listOf("Archive", "", "Delete", "Pin"), listOf("12", "14", "13", "x")),
        )
    }

    @Test
    fun groupsAndModalsKeepTheirChildren() {
        val group = spec("column", "a11y_role" to "radiogroup", "a11y_label" to "Priority")!!
        assertNull(group.description)
        assertEquals("Priority", group.groupLabel)

        val dialog = spec("stack", "a11y_modal" to true, "a11y_label" to "Delete draft?", "on_a11y_dismiss" to 9)!!
        assertNull(dialog.description)
        assertEquals("Delete draft?", dialog.paneTitle)
        assertEquals(9, dialog.dismissId)
    }

    @Test
    fun aPaneTitleIsKeptOnALabelledContainerThatStillMerges() {
        val pane = spec("column", "a11y_label" to "Step 2", "a11y_pane_title" to "Shipping")!!
        assertEquals("Step 2", pane.description)
        assertEquals("Shipping", pane.paneTitle)
    }

    @Test
    fun onlyKnownLiveModesAreLiveRegions() {
        assertEquals("assertive", spec("text", "a11y_live" to "assertive")?.live)
        assertNull(spec("text", "a11y_live" to "rude"))
    }

    @Test
    fun errorAndDisabledAreCarried() {
        val field = spec("pressable", "a11y_error" to "Pick a priority", "disabled" to true, hasPress = true)!!
        assertEquals("Pick a priority", field.stateDescription)
        assertTrue(field.disabled)
    }

    @Test
    fun nativeControlsAndPropFreeNodesGetNothing() {
        assertNull(spec("button", "a11y_role" to "checkbox", "a11y_checked" to "true"))
        assertNull(spec("column"))
        assertNull(spec("text", "a11y_label" to "Read by the text renderer"))
        assertNotNull(spec("pressable", hasPress = true))
    }

    @Test
    fun hiddenAppliesToComposedTypesOnly() {
        assertTrue(NodeAccessibility.isHidden("icon", GenericProps(mapOf("a11y_hidden" to true))))
        assertFalse(NodeAccessibility.isHidden("button", GenericProps(mapOf("a11y_hidden" to true))))
    }

    @Test
    fun aModalHidesTheSiblingsOfItselfAndEachAncestor() {
        // root(1) ─ header(2)
        //         ─ body(3) ─ list(4)
        //                   ─ overlay(5, modal) ─ title(6)
        //         ─ tabs(7)
        val tree = node(
            1, "stack",
            node(2),
            node(3, "column", node(4), node(5, "stack", node(6), modal = true)),
            node(7),
        )
        assertEquals(setOf(2, 4, 7), NodeAccessibility.hiddenByModal(tree, 390f))
    }

    @Test
    fun theModalScopeNamesTheModalAndItsAncestors() {
        val tree = node(1, "stack", node(2), node(3, "column", node(4), node(5, "stack", node(6), modal = true)))
        assertEquals(setOf(1, 3, 5), NodeAccessibility.modalScope(tree, 390f)?.path)
    }

    @Test
    fun theLastModalInTreeOrderWins() {
        val tree = node(1, "stack", node(2, "stack", node(3), modal = true), node(4, "stack", node(5), modal = true))
        assertEquals(setOf(2), NodeAccessibility.hiddenByModal(tree, 390f))
    }

    @Test
    fun noModalHidesNothing() {
        assertNull(NodeAccessibility.hiddenByModal(node(1, "stack", node(2), node(3)), 390f))
    }

    @Test
    fun aModalThatIsNotDisplayedHidesNothing() {
        val tree = node(1, "stack", node(2), node(3, "stack", node(4), modal = true, hidden = true))
        assertNull(NodeAccessibility.hiddenByModal(tree, 390f))
    }

    @Test
    fun unlabelledControlsMergeTheirChildrenSoTalkBackCanFocusThem() {
        assertTrue(spec("gesture_area", "a11y_role" to "adjustable", "on_a11y_increment" to 7)!!.mergesChildren)
        assertTrue(spec("column", "a11y_role" to "progress", "a11y_value" to "Loading")!!.mergesChildren)
        assertTrue(spec("row", "a11y_action_labels" to listOf("Delete"), "a11y_action_callbacks" to listOf("9"))!!.mergesChildren)
        assertTrue(spec("column", "a11y_value_now" to 3f)!!.mergesChildren)
    }

    @Test
    fun labelledGroupsModalsAndStateOnlyNodesDoNotMerge() {
        // Labelled: the label already replaces the children.
        assertFalse(spec("pressable", "a11y_role" to "checkbox", "a11y_label" to "Terms", hasPress = true)!!.mergesChildren)
        assertFalse(spec("column", "a11y_role" to "radiogroup")!!.mergesChildren)
        assertFalse(spec("stack", "a11y_modal" to true, "a11y_role" to "button")!!.mergesChildren)
        assertFalse(spec("column", "a11y_state" to "Syncing")!!.mergesChildren)
    }

    @Test
    fun aModalLabelIsItsPaneTitleNotAGroupLabel() {
        val dialog = spec("stack", "a11y_modal" to true, "a11y_label" to "Delete draft?")!!
        assertNull(dialog.groupLabel)
        assertEquals("Delete draft?", dialog.paneTitle)
    }

    @Test
    fun theErrorIsSpokenWithTheState() {
        val group = spec("column", "a11y_role" to "radiogroup", "a11y_label" to "Priority", "a11y_error" to "Choose a priority.")!!
        assertEquals("Choose a priority.", group.stateDescription)
        // With its error the group is a stop of its own, so it merges.
        assertTrue(group.mergesChildren)
        assertFalse(spec("column", "a11y_role" to "radiogroup", "a11y_label" to "Priority", "a11y_error" to "")!!.mergesChildren)
        assertEquals(
            "Uploading, 60%, Failed",
            spec("column", "a11y_role" to "progress", "a11y_state" to "Uploading", "a11y_value" to "60%", "a11y_error" to "Failed")?.stateDescription,
        )
    }

    @Test
    fun aLiveRegionMergesTheTextItAnnounces() {
        assertTrue(spec("text", "a11y_live" to "polite")!!.mergesChildren)
        assertTrue(spec("column", "a11y_live" to "assertive")!!.mergesChildren)
    }

    @Test
    fun aLabelledGestureAreaThatIsOneElementIsReadByItsLabel() {
        val slider = spec(
            "gesture_area", "a11y_role" to "adjustable", "a11y_label" to "Volume", "a11y_hint" to "Swipe to change",
            "a11y_value_now" to 40f, "on_a11y_increment" to 7,
        )!!
        assertEquals("Volume. Swipe to change", slider.description)
        assertFalse(slider.mergesChildren)
        assertEquals(40f, slider.range?.now)

        val row = spec("gesture_area", "a11y_label" to "Quarterly report", "a11y_action_labels" to listOf("Archive"), "a11y_action_callbacks" to listOf("9"))!!
        assertEquals("Quarterly report", row.description)
        assertEquals(listOf("Archive"), row.actions.map { it.label })

        // Unlabelled it merges its children; with only a state its renderer names it.
        assertTrue(spec("gesture_area", "a11y_role" to "adjustable", "a11y_value_now" to 40f)!!.mergesChildren)
        assertNull(spec("gesture_area", "a11y_label" to "Card", "a11y_state" to "Pinned")!!.description)
    }
}
