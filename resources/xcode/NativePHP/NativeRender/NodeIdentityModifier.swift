import SwiftUI

/// Exposes a node's `ref` to the accessibility tree as its identifier, which
/// is how UI drivers (Maestro, XCUITest) target an element by name.
///
/// `ref` is the element's public handle — the same one that pairs shared
/// elements across a morph transition — so a driver targeting `id: "photo-1"`
/// and a morph matching `ref="photo-1"` are naming the same thing. Without
/// this the identifier never leaves PHP: nothing in the iOS renderer read
/// `ref` at all, so every `tapOn: id:` failed with "Element with Id matching
/// regex not found" even though the ref was present in the tree.
///
/// Only applied to nodes with a `ref` (see `NodeDecorations.ref`).
struct NodeIdentityModifier: ViewModifier {
    let node: NativeUINode

    func body(content: Content) -> some View {
        let ref = node.props.getString("ref", default: "")

        if NodeAccessibility.applies(to: node) {
            // `NodeAccessibilityModifier` has already made this node one
            // element (a labelled container, or a pressable read as a
            // button). Name that element rather than wrapping it in another.
            content.accessibilityIdentifier(ref)
        } else {
            content
                // `.contain` keeps this node's DESCENDANTS as separate
                // accessibility elements. Without it, naming a container
                // collapses everything inside it into one element: the full
                // player exposed only its outermost `player-surface` and hid
                // `player-art`, `player-title` and the close control, so a UI
                // driver could open the screen and then had nothing to tap.
                //
                // It also matters beyond testing — a screen reader would have
                // lost the same content.
                .accessibilityElement(children: .contain)
                .accessibilityIdentifier(ref)
        }
    }
}
