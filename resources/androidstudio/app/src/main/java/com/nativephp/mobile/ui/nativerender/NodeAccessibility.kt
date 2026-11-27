package com.nativephp.mobile.ui.nativerender

/**
 * How a core node that draws no control of its own (`pressable`, `column`,
 * `row`, `stack`, `gesture_area`, `text`, `icon`, `image`) appears to
 * TalkBack. Without this, a control composed from these nodes could not
 * say what it is, what state it is in, or how to adjust it, and a
 * pressable read as loose text fragments.
 *
 * Kept free of Compose so the policy is unit-testable on the JVM;
 * `Modifier.nodeGestures`, `Modifier.nodeAccessibility` and `NodeView`
 * apply it.
 */
internal object NodeAccessibility {
    /** Core containers whose renderers never read `a11y_label` themselves. */
    val CONTAINER_TYPES = setOf("pressable", "column", "row", "stack")

    /**
     * Nodes the composed-control props (`a11y_role`, state, value, actions,
     * …) apply to. `gesture_area`, `text`, `icon` and `image` read their own
     * label, so only the containers have it replace their children.
     */
    val COMPOSED_TYPES = CONTAINER_TYPES + setOf("gesture_area", "text", "icon", "image")

    /** Roles whose container keeps its children as separate elements. */
    private val GROUP_ROLES = setOf("radiogroup", "tablist")

    /** Roles a node announces as one control, merging its children. */
    private val CONTROL_ROLES = setOf(
        "button", "checkbox", "switch", "radio", "tab", "dropdown", "adjustable", "progress", "header", "image",
    )

    /** A checkbox's or switch's state, as Compose's `ToggleableState`. */
    enum class Toggle { On, Off, Indeterminate }

    /** A numeric value and its bounds, for Android's range info. */
    data class Range(val now: Float, val min: Float, val max: Float)

    /** An entry in TalkBack's actions menu. */
    data class Action(val label: String, val callbackId: Int)

    /** Everything a node tells TalkBack beyond what its renderer draws. */
    data class Spec(
        /** The wire role after defaulting; null for none. */
        val role: String?,
        /** Replaces the node's children: a labelled container read as one element. */
        val description: String?,
        /**
         * Names a group but keeps its children. TalkBack stops on the group
         * itself only while it has an error (see [mergesChildren]).
         */
        val groupLabel: String?,
        /**
         * Merges the children into one element without replacing their
         * text: a control with no label, a range or actions, or a group
         * with an error. TalkBack only
         * focuses a node that merges or is a leaf, so without this a
         * non-tappable control's role, value and actions are never read.
         */
        val mergesChildren: Boolean,
        val toggle: Toggle?,
        val selected: Boolean?,
        val expanded: Boolean?,
        /** The state, value and error, which TalkBack reads after the name. */
        val stateDescription: String?,
        val range: Range?,
        /** No numbers given: a progress node is indeterminate. */
        val indeterminate: Boolean,
        val incrementId: Int,
        val decrementId: Int,
        val dismissId: Int,
        val actions: List<Action>,
        val modal: Boolean,
        val paneTitle: String?,
        /** `polite` or `assertive`; null when the node is not a live region. */
        val live: String?,
        val disabled: Boolean,
    )

    /**
     * The node is announced as a button: a `pressable` with a tap handler.
     * A pressable with a `:menu` is left to its renderer, which owns the
     * menu's tap.
     */
    fun isButton(type: String, hasPress: Boolean, hasMenu: Boolean): Boolean =
        type == "pressable" && hasPress && !hasMenu

    /**
     * The node's role: `a11y_role` when given (`none` clears it), otherwise
     * a button for a tappable pressable. Null for nodes left to their
     * renderer.
     */
    fun role(type: String, a11yRole: String, hasPress: Boolean, hasMenu: Boolean): String? {
        if (type !in COMPOSED_TYPES || hasMenu) return null
        if (a11yRole.isNotEmpty()) return a11yRole.takeUnless { it == "none" }

        return if (isButton(type, hasPress, hasMenu)) "button" else null
    }

    /**
     * The description that replaces the node's children for TalkBack, or
     * null to leave the subtree as it is (no label, not a core container,
     * a menu pressable, or a group or modal that contains its children).
     * The hint is appended the way `mobile-ui`'s inputs do it, since
     * Compose has no separate hint semantics.
     */
    fun description(
        type: String,
        label: String,
        hint: String,
        hasMenu: Boolean,
        role: String? = null,
        modal: Boolean = false,
    ): String? {
        if (type !in CONTAINER_TYPES || hasMenu || label.isEmpty()) return null
        if (role in GROUP_ROLES || modal) return null

        return if (hint.isEmpty()) label else "$label. $hint"
    }

    /** Whether the node and everything inside it is hidden from TalkBack. */
    fun isHidden(type: String, props: GenericProps): Boolean =
        type in COMPOSED_TYPES && props.getBool("a11y_hidden")

    /**
     * What TalkBack is told about a node, or null when there is nothing to
     * add: not a composed type, a menu pressable, or no a11y props at all.
     */
    fun spec(type: String, props: GenericProps, hasPress: Boolean): Spec? {
        if (type !in COMPOSED_TYPES) return null
        val hasMenu = props.getBool("has_menu")
        if (hasMenu) return null

        val role = role(type, props.getString("a11y_role"), hasPress, hasMenu)
        val label = props.getString("a11y_label")
        val modal = type in CONTAINER_TYPES && props.getBool("a11y_modal")
        val hint = props.getString("a11y_hint")
        // A modal's label is its pane title instead.
        val groupLabel = label.takeIf { it.isNotEmpty() && type in CONTAINER_TYPES && role in GROUP_ROLES }

        val toggle = if (role == "checkbox" || role == "switch") {
            when (props.getString("a11y_checked")) {
                "true" -> Toggle.On
                "false" -> Toggle.Off
                "mixed" -> Toggle.Indeterminate
                else -> null
            }
        } else {
            null
        }

        val value = props.getString("a11y_value")
        val error = props.getString("a11y_error")
        // The error is spoken with the state rather than set as the node's
        // `error()`: TalkBack 12 reads that only on editable fields, and
        // newer versions read it as well, which would say it twice.
        val stateDescription = listOf(props.getString("a11y_state"), value, error)
            .filter { it.isNotEmpty() }
            .joinToString(", ")
            .ifEmpty { null }

        val incrementId = props.getCallbackId("on_a11y_increment")
        val decrementId = props.getCallbackId("on_a11y_decrement")
        val numeric = props.has("a11y_value_now")
        val range = when {
            numeric -> range(
                props.getFloat("a11y_value_now"),
                props.getFloat("a11y_value_min", 0f),
                props.getFloat("a11y_value_max", 100f),
            )
            // TalkBack adjusts through setProgress, which needs a range. An
            // adjustable node given only a spoken value gets a midpoint, and
            // its value text is read in place of the percentage.
            role == "adjustable" && (incrementId != 0 || decrementId != 0) -> Range(0.5f, 0f, 1f)
            else -> null
        }

        val actions = actions(props.getStringList("a11y_action_labels"), props.getStringList("a11y_action_callbacks"))
        val contains = role in GROUP_ROLES || modal
        val live = props.getString("a11y_live").takeIf { it == "polite" || it == "assertive" }
        // A live region announces the text it holds, so it holds its
        // children's text: unmerged, TalkBack gets a change with nothing to say.
        val oneElement = !contains &&
            (role in CONTROL_ROLES || range != null || props.has("a11y_action_labels") || live != null)
        // A labelled gesture area that is one element is read by its label,
        // as a labelled container is. Merged instead, Compose would put the
        // label its renderer sets on a child node of its own, which TalkBack
        // reads as a separate stop.
        val description = description(type, label, hint, hasMenu, role, modal)
            ?: label.takeIf { it.isNotEmpty() && type == "gesture_area" && oneElement }
                ?.let { if (hint.isEmpty()) it else "$it. $hint" }
        // A radio group or tab list with an error becomes a stop of its own,
        // to read the error. Compose orders a container only when it merges,
        // so it merges: its radios or tabs merge themselves, so they stay
        // separate stops. Unmerged, TalkBack placed the group at the end.
        val groupWithError = role in GROUP_ROLES && type in CONTAINER_TYPES && error.isNotEmpty()
        val mergesChildren = description == null && (oneElement || groupWithError)

        val spec = Spec(
            role = role,
            description = description,
            groupLabel = groupLabel,
            mergesChildren = mergesChildren,
            toggle = toggle,
            selected = if (props.has("a11y_selected")) props.getBool("a11y_selected") else null,
            expanded = if (props.has("a11y_expanded")) props.getBool("a11y_expanded") else null,
            stateDescription = stateDescription,
            range = range,
            indeterminate = role == "progress" && !numeric,
            incrementId = if (role == "adjustable") incrementId else 0,
            decrementId = if (role == "adjustable") decrementId else 0,
            dismissId = if (modal) props.getCallbackId("on_a11y_dismiss") else 0,
            actions = actions,
            modal = modal,
            paneTitle = props.getString("a11y_pane_title").ifEmpty { if (modal) label.ifEmpty { null } else null },
            live = live,
            disabled = props.getBool("disabled"),
        )

        return spec.takeUnless { it == EMPTY }
    }

    /** The bounds in order and the value inside them. */
    fun range(now: Float, min: Float, max: Float): Range {
        val lo = minOf(min, max)
        val hi = maxOf(min, max)
        return Range(now.coerceIn(lo, hi), lo, hi)
    }

    /**
     * Which callback a TalkBack adjustment calls: `setProgress` asks for a
     * target value, and only its direction matters, since PHP decides the
     * step. Null when the target is the current value.
     */
    fun adjustment(spec: Spec, target: Float): Int? {
        val current = spec.range?.now ?: return null
        return when {
            target > current -> spec.incrementId.takeIf { it != 0 }
            target < current -> spec.decrementId.takeIf { it != 0 }
            else -> null
        }
    }

    /** Pairs each action label with its callback, skipping malformed entries. */
    fun actions(labels: List<String>, callbacks: List<String>): List<Action> =
        labels.zip(callbacks).mapNotNull { (label, callback) ->
            val id = callback.toIntOrNull() ?: 0
            if (label.isEmpty() || id == 0) null else Action(label, id)
        }

    /** The modal container on screen and what TalkBack skips while it is. */
    data class ModalScope(
        /** The modal and each of its ancestors, by node id. */
        val path: Set<Int>,
        /** Every sibling of the modal and of each of its ancestors. */
        val hidden: Set<Int>,
    )

    /**
     * What a modal container on screen hides from TalkBack: every sibling
     * of the modal and of each of its ancestors, so only the overlay and
     * what contains it stay reachable. Uses the last modal in tree order,
     * which is the one drawn on top. Null when no modal is shown. Resolved
     * at [width] like `NodeView`, so a modal kept in the tree but hidden
     * (`display: none`, or by a responsive variant) hides nothing.
     */
    fun modalScope(root: NativeUINode, width: Float): ModalScope? {
        val trail = ArrayList<NativeUINode>()
        var path: List<NativeUINode>? = null

        fun walk(node: NativeUINode) {
            if (node.layout?.display == Display.NONE) return
            trail.add(node)
            if (node.type in CONTAINER_TYPES && node.props.getBool("a11y_modal")) {
                path = ArrayList(trail)
            }
            node.children.forEach { walk(it.resolved(width)) }
            trail.removeAt(trail.size - 1)
        }
        walk(root.resolved(width))

        val modalPath = path ?: return null
        val hidden = HashSet<Int>()
        // By id: a node with responsive variants resolves to a copy.
        for (i in 0 until modalPath.size - 1) {
            val next = modalPath[i + 1].id
            modalPath[i].children.forEach { if (it.id != next) hidden.add(it.id) }
        }
        return ModalScope(modalPath.mapTo(HashSet()) { it.id }, hidden)
    }

    fun hiddenByModal(root: NativeUINode, width: Float): Set<Int>? = modalScope(root, width)?.hidden

    private val EMPTY = Spec(
        role = null, description = null, groupLabel = null, mergesChildren = false, toggle = null, selected = null,
        expanded = null, stateDescription = null, range = null, indeterminate = false,
        incrementId = 0, decrementId = 0, dismissId = 0, actions = emptyList(), modal = false,
        paneTitle = null, live = null, disabled = false,
    )
}
