import SwiftUI
import UIKit

// MARK: - Policy

/// How a core node that draws no control of its own (`pressable`, `column`,
/// `row`, `stack`, `gesture_area`, `text`, `icon`, `image`) appears to
/// VoiceOver. Without this, a control composed from these nodes could not
/// say what it is, what state it is in, or how to adjust it, and a
/// pressable read as loose text fragments with no button trait. Mirrors
/// Android's `NodeAccessibility`.
enum NodeAccessibility {
    /// Core containers whose renderers never read `a11y_label` themselves.
    static let containerTypes: Set<String> = ["pressable", "column", "row", "stack"]

    /// Nodes the composed-control props apply to. `gesture_area`, `text`,
    /// `icon` and `image` read their own label.
    static let composedTypes: Set<String> = containerTypes.union(["gesture_area", "text", "icon", "image"])

    /// Roles a node announces as one control, combining its children.
    static let controlRoles: Set<String> = [
        "button", "checkbox", "switch", "radio", "tab", "dropdown", "adjustable", "progress", "header", "image",
    ]

    /// Roles whose container keeps its children as separate elements.
    static let groupRoles: Set<String> = ["radiogroup", "tablist"]

    /// Types that are one element already and read their own label.
    static let leafTypes: Set<String> = ["text", "icon", "image"]

    /// How the modifier groups a node's children into elements.
    enum Grouping {
        /// Left as they are: the node is no element of its own.
        case none
        /// One element whose label is read from the children.
        case combine
        /// The children stay separate elements inside a named container.
        case contain
        /// One element read as the node's `a11y-label`.
        case ignore
    }

    /// Mirrors Android's `Spec.mergesChildren`: an unlabelled control (a
    /// role, a number or actions) combines its children so the screen
    /// reader can focus it. Decided from which props are present, never
    /// their values, so a change of state keeps the view's structure.
    static func grouping(_ node: NativeUINode) -> Grouping {
        let props = node.props
        if contains(node) { return .contain }
        if containerTypes.contains(node.type), !props.getString("a11y_label").isEmpty { return .ignore }
        if leafTypes.contains(node.type) { return .none }
        if controlRoles.contains(role(node) ?? "") || props.has("a11y_value_now") || props.has("a11y_action_labels") {
            return .combine
        }
        return .none
    }

    /// Whether the node is one accessibility element once the modifier has
    /// run, so its traits, value and identifier belong to that element
    /// rather than spreading to its children.
    static func isElement(_ node: NativeUINode) -> Bool {
        grouping(node) != .none || leafTypes.contains(node.type)
    }

    /// Props that make up the spoken value (see `value(_:role:)`).
    static let valueProps = ["a11y_checked", "a11y_expanded", "a11y_state", "a11y_value", "a11y_value_now", "a11y_error"]

    /// The text a live region reads: its label, its own text, or else its
    /// children's text in order.
    static func spokenText(_ node: NativeUINode) -> String {
        let label = node.props.getString("a11y_label")
        if !label.isEmpty { return label }
        let text = node.props.getString("text")
        if !text.isEmpty { return text }
        return node.children.map(spokenText).filter { !$0.isEmpty }.joined(separator: ", ")
    }

    /// The props that opt a node into this modifier, beyond #580's label.
    static let props = [
        "a11y_role", "a11y_checked", "a11y_selected", "a11y_expanded", "a11y_state", "a11y_error",
        "a11y_value", "a11y_value_now", "a11y_action_labels", "a11y_hidden", "a11y_modal",
        "a11y_pane_title", "a11y_live", "disabled",
    ]

    /// The node's role: `a11y_role` when given (`none` clears it), otherwise
    /// a button for a tappable pressable. A pressable with a `:menu` is a
    /// SwiftUI `Menu`, which is a button already and owns the tap, so it is
    /// left to its renderer.
    static func role(_ node: NativeUINode) -> String? {
        guard composedTypes.contains(node.type), !node.props.getBool("has_menu") else { return nil }
        let role = node.props.getString("a11y_role")
        if !role.isEmpty { return role == "none" ? nil : role }

        return node.type == "pressable" && node.onPress != 0 ? "button" : nil
    }

    /// A tappable pressable read as a button (#580), unless `a11y-role`
    /// says otherwise.
    static func isButton(_ node: NativeUINode) -> Bool {
        ["button", "radio", "tab", "dropdown"].contains(role(node) ?? "")
    }

    /// Whether the node keeps its children as separate elements: a radio
    /// group, a tab list, or a modal overlay.
    static func contains(_ node: NativeUINode) -> Bool {
        containerTypes.contains(node.type)
            && (groupRoles.contains(node.props.getString("a11y_role")) || node.props.getBool("a11y_modal"))
    }

    static func applies(to node: NativeUINode) -> Bool {
        guard composedTypes.contains(node.type), !node.props.getBool("has_menu") else { return false }
        if containerTypes.contains(node.type), !node.props.getString("a11y_label").isEmpty || role(node) == "button" {
            return true
        }

        return props.contains { node.props.has($0) }
    }

    /// The words for a state VoiceOver has no trait for. Looked up in the
    /// app's own strings, so an app can translate them.
    static func localized(_ key: String.LocalizationValue) -> String {
        String(localized: key, bundle: .main)
    }

    /// What VoiceOver reads as the value: the checked or expanded state,
    /// `a11y-state`, `a11y-value` (or the number when no text is given),
    /// then `a11y-error`.
    static func value(_ node: NativeUINode, role: String?) -> String {
        let props = node.props
        var parts: [String] = []

        switch (role, props.getString("a11y_checked")) {
        case ("checkbox", "true"): parts.append(localized("Checked"))
        case ("checkbox", "false"): parts.append(localized("Unchecked"))
        case ("switch", "true"): parts.append(localized("On"))
        case ("switch", "false"): parts.append(localized("Off"))
        case ("checkbox", "mixed"), ("switch", "mixed"): parts.append(localized("Mixed"))
        default: break
        }
        if props.has("a11y_expanded") {
            parts.append(props.getBool("a11y_expanded") ? localized("Expanded") : localized("Collapsed"))
        }

        let state = props.getString("a11y_state")
        if !state.isEmpty { parts.append(state) }

        let value = props.getString("a11y_value")
        if !value.isEmpty {
            parts.append(value)
        } else if props.has("a11y_value_now") {
            parts.append(Double(props.getFloat("a11y_value_now")).formatted())
        }

        let error = props.getString("a11y_error")
        if !error.isEmpty { parts.append(error) }

        return parts.joined(separator: ", ")
    }

    static func traits(_ node: NativeUINode, role: String?) -> AccessibilityTraits {
        var traits: AccessibilityTraits = []
        switch role {
        case "button", "radio", "tab", "dropdown": traits.formUnion(.isButton)
        case "checkbox", "switch": traits.formUnion(.isToggle)
        case "header": traits.formUnion(.isHeader)
        case "image": traits.formUnion(.isImage)
        case "progress": traits.formUnion(.updatesFrequently)
        case "tablist": traits.formUnion(.isTabBar)
        default: break
        }
        if node.props.getBool("a11y_selected") { traits.formUnion(.isSelected) }
        if containerTypes.contains(node.type), node.props.getBool("a11y_modal") { traits.formUnion(.isModal) }

        return traits
    }

    /// An entry in VoiceOver's actions rotor.
    struct Action: Identifiable {
        let label: String
        let callbackId: Int
        var id: Int { callbackId }
    }

    /// Pairs each action label with its callback, skipping malformed entries.
    static func actions(_ node: NativeUINode) -> [Action] {
        zip(node.props.getStringList("a11y_action_labels"), node.props.getStringList("a11y_action_callbacks"))
            .compactMap { label, callback in
                guard !label.isEmpty, let id = Int(callback), id != 0 else { return nil }
                return Action(label: label, callbackId: id)
            }
    }
}

// MARK: - Modifier

/// Applies `NodeAccessibility` to a node.
///
/// With an `a11y-label`, a core container becomes one element read as that
/// label (children ignored), plus `a11y-hint`. A `radiogroup`, `tablist` or
/// modal container is named by its label but keeps its children. Without a
/// label, a node with a control role (a tappable pressable is a button)
/// combines its children into one element, as SwiftUI's `Button` does with
/// its label.
///
/// State that changes from render to render (checked, selected, expanded,
/// value, error, disabled) is applied without changing the view's
/// structure, so a toggle doesn't rebuild the node.
struct NodeAccessibilityModifier: ViewModifier {
    let node: NativeUINode

    func body(content: Content) -> some View {
        let props = node.props
        let isContainer = NodeAccessibility.containerTypes.contains(node.type)
        // `gesture_area`, `text`, `icon` and `image` label themselves.
        let label = isContainer ? props.getString("a11y_label") : ""
        let hint = isContainer ? props.getString("a11y_hint") : ""
        let role = NodeAccessibility.role(node)
        let grouping = NodeAccessibility.grouping(node)
        // Traits and the value go on an element. On a node that is none,
        // they would spread to each of its children instead.
        let isElement = NodeAccessibility.isElement(node)
        let hasValue = isElement && NodeAccessibility.valueProps.contains { props.has($0) }
        let hidden = props.getBool("a11y_hidden")
        let disabled = props.getBool("disabled")
        let value = NodeAccessibility.value(node, role: role)
        let traits = NodeAccessibility.traits(node, role: role)
        // A tappable node whose role is not a button (`checkbox`, `none`, …)
        // takes back the button trait its tap handler would give it.
        let removed: AccessibilityTraits = props.has("a11y_role") && node.onPress != 0 && !NodeAccessibility.isButton(node)
            ? .isButton : []
        let nodeId = node.id
        let pressId = node.onPress
        let incrementId = role == "adjustable" ? props.getCallbackId("on_a11y_increment") : 0
        let decrementId = role == "adjustable" ? props.getCallbackId("on_a11y_decrement") : 0
        let dismissId = traits.contains(.isModal) ? props.getCallbackId("on_a11y_dismiss") : 0
        let actions = NodeAccessibility.actions(node)

        content
            .nodeModifier(if: grouping == .combine) {
                $0.accessibilityElement(children: .combine)
            }
            .nodeModifier(if: grouping == .contain) {
                $0.accessibilityElement(children: .contain)
            }
            .nodeModifier(if: grouping == .ignore) {
                $0.accessibilityElement(children: .ignore)
            }
            .nodeModifier(if: !label.isEmpty) {
                $0.accessibilityLabel(Text(label))
            }
            .nodeModifier(if: !hint.isEmpty) {
                $0.accessibilityHint(Text(hint))
            }
            .accessibilityAddTraits(isElement ? traits : [])
            .accessibilityRemoveTraits(isElement ? removed : [])
            // Only on nodes that carry a value prop, so a #580 pressable
            // keeps the value it combines from its children.
            .nodeModifier(if: hasValue) {
                $0.accessibilityValue(Text(value))
            }
            // SwiftUI's only way to say "dimmed". It also disables any
            // control inside the pressable, which Android leaves enabled.
            .disabled(disabled)
            .accessibilityHidden(hidden)
            // Explicit activation, so a double-tap fires `@press` rather than
            // a synthesized tap at the element's centre, which could land on
            // a child that is now hidden from VoiceOver.
            .nodeModifier(if: pressId != 0) {
                $0.accessibilityAction {
                    guard !disabled else { return }
                    KeyboardFocusPolicy.dispatchPress {
                        NativeElementBridge.sendPressEvent(pressId, nodeId: nodeId)
                    }
                }
            }
            .nodeModifier(if: incrementId != 0 || decrementId != 0) {
                $0.accessibilityAdjustableAction { direction in
                    switch direction {
                    case .increment where incrementId != 0:
                        NativeElementBridge.sendPressEvent(incrementId, nodeId: nodeId)
                    case .decrement where decrementId != 0:
                        NativeElementBridge.sendPressEvent(decrementId, nodeId: nodeId)
                    default:
                        break
                    }
                }
            }
            // Whenever the prop is present, even empty, so actions coming
            // and going don't change the view's structure.
            .nodeModifier(if: props.has("a11y_action_labels")) {
                $0.accessibilityActions {
                    // VoiceOver on iOS 27.0.1 lists these last first in the
                    // rotor; reversed, they read in the order given.
                    ForEach(Array(actions.reversed())) { action in
                        Button(action.label) {
                            NativeElementBridge.sendPressEvent(action.callbackId, nodeId: nodeId)
                        }
                    }
                }
            }
            .nodeModifier(if: dismissId != 0) {
                $0.accessibilityAction(.escape) {
                    NativeElementBridge.sendPressEvent(dismissId, nodeId: nodeId)
                }
            }
            .modifier(NodeAccessibilityChangesModifier(node: node))
    }
}

/// What VoiceOver is told when a node changes rather than when it is
/// focused: a modal appearing or going away, a pane title, a live region's
/// new text, and a new error. iOS has no live regions or pane titles, so
/// these are announcements.
private struct NodeAccessibilityChangesModifier: ViewModifier {
    let node: NativeUINode
    /// Where VoiceOver goes when a modal appears: into it, rather than to
    /// the first element on screen, which is the navigation title.
    @AccessibilityFocusState private var modalFocused: Bool
    /// Whether this node counts as a modal on screen in `ScreenReader`, so
    /// every count it adds is taken away again.
    @State private var countedModal = false
    /// Whether the view is on screen: a render can reach a lower stack
    /// level or another tab, where a modal isn't visible.
    @State private var onScreen = false

    /// Counts the modal in or out, and moves VoiceOver into it when it is
    /// shown (unless `Accessibility::focus()` is about to move it).
    private func setModalShown(_ shown: Bool) {
        guard shown != countedModal else { return }
        countedModal = shown
        if shown {
            ScreenReader.modalDidAppear()
            UIAccessibility.post(notification: .screenChanged, argument: nil)
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.25) {
                if countedModal && !ScreenReader.isFocusPending { modalFocused = true }
            }
        } else {
            ScreenReader.modalDidDisappear()
            UIAccessibility.post(notification: .screenChanged, argument: nil)
        }
    }

    func body(content: Content) -> some View {
        let props = node.props
        let isContainer = NodeAccessibility.containerTypes.contains(node.type)
        let modal = isContainer && props.getBool("a11y_modal")
        // A modal kept in the tree but not displayed is not on screen.
        let modalShown = modal && node.layout?.display != Display.none
        let paneTitle = modal ? "" : props.getString("a11y_pane_title")
        let live = props.getString("a11y_live")
        let liveText = live.isEmpty ? "" : NodeAccessibility.spokenText(node)
        let error = props.getString("a11y_error")

        content
            // On every container, so `a11y-modal` turning on or off doesn't
            // change the view's structure.
            .nodeModifier(if: isContainer) {
                $0.accessibilityFocused($modalFocused)
            }
            // The rest of the screen is outside a shown modal. Counted from
            // the value as well as the view's lifetime, since a render can
            // turn `a11y-modal` or `display` off without removing the node.
            .onAppear {
                onScreen = true
                setModalShown(modalShown)
                if !paneTitle.isEmpty { ScreenReader.announce(paneTitle, assertive: false) }
            }
            .onChange(of: modalShown) { _, shown in setModalShown(onScreen && shown) }
            .onDisappear {
                onScreen = false
                setModalShown(false)
            }
            .onChange(of: paneTitle) { _, title in
                if !title.isEmpty { ScreenReader.announce(title, assertive: false) }
            }
            .onChange(of: liveText) { _, text in
                if !text.isEmpty { ScreenReader.announce(text, assertive: live == "assertive") }
            }
            .onChange(of: error) { _, error in
                if !error.isEmpty { ScreenReader.announce(error, assertive: false) }
            }
    }
}
