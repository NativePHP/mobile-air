package com.nativephp.mobile.ui.nativerender

/**
 * How a core layout node (`pressable`, `column`, `row`, `stack`) appears
 * to TalkBack. These types draw no control of their own, so without this
 * their `a11y-label` / `a11y-hint` never reached the screen reader, and a
 * pressable read as loose text fragments with no button role.
 *
 * Kept free of Compose so the policy is unit-testable on the JVM;
 * `Modifier.nodeAccessibility` applies it.
 */
internal object NodeAccessibility {
    /** Core containers whose renderers never read `a11y_label` themselves. */
    val CONTAINER_TYPES = setOf("pressable", "column", "row", "stack")

    /**
     * The node is announced as a button: a `pressable` with a tap handler.
     * A pressable with a `:menu` is left to its renderer, which owns the
     * menu's tap.
     */
    fun isButton(type: String, hasPress: Boolean, hasMenu: Boolean): Boolean =
        type == "pressable" && hasPress && !hasMenu

    /**
     * The description that replaces the node's children for TalkBack, or
     * null to leave the subtree as it is (no label, not a core container,
     * or a menu pressable). The hint is appended the way `mobile-ui`'s
     * inputs do it, since Compose has no separate hint semantics.
     */
    fun description(type: String, label: String, hint: String, hasMenu: Boolean): String? {
        if (type !in CONTAINER_TYPES || hasMenu || label.isEmpty()) return null

        return if (hint.isEmpty()) label else "$label. $hint"
    }
}
