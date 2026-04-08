import SwiftUI

/// SwiftUI View modifier that wires onPress / onLongPress callbacks from a
/// NativeUINode to tap and long-press gestures.  Used by plugin renderers
/// (e.g. ComposeUI) that build their UI in SwiftUI rather than UIKit.
extension View {
    func applyClickHandlers(node: NativeUINode) -> some View {
        self.modifier(ClickHandlerModifier(node: node))
    }
}

private struct ClickHandlerModifier: ViewModifier {
    let node: NativeUINode

    func body(content: Content) -> some View {
        var view = AnyView(content)

        if node.onPress != 0 {
            let cbId = node.onPress
            let nodeId = node.id
            view = AnyView(
                view.onTapGesture {
                    NativeUIBridge.sendPressEvent(cbId, nodeId: nodeId)
                }
            )
        }

        if node.onLongPress != 0 {
            let cbId = node.onLongPress
            let nodeId = node.id
            view = AnyView(
                view.onLongPressGesture(minimumDuration: 0.5) {
                    NativeUIBridge.sendLongPressEvent(cbId, nodeId: nodeId)
                }
            )
        }

        return view
    }
}
