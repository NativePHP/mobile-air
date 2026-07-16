import SwiftUI

/// SwiftUI View modifier that wires onPress / onLongPress callbacks from a
/// NativeUINode to tap and long-press gestures.  Used by plugin renderers
/// (e.g. NativeUI) that build their UI in SwiftUI rather than UIKit.
extension View {
    func applyClickHandlers(node: NativeUINode) -> some View {
        self.modifier(ClickHandlerModifier(node: node))
    }
}

private struct ClickHandlerModifier: ViewModifier {
    let node: NativeUINode

    func body(content: Content) -> some View {
        var view = AnyView(content)

        // Double-tap is carried in props (`on_double_tap`), not a dedicated
        // node field. Attached before the single tap so the 2-count gesture
        // gets first claim. Reuses the Press event type — the callback id
        // routes to the @doubleTap handler.
        let doubleTapId = node.props.getInt("on_double_tap")
        if doubleTapId != 0 {
            let nodeId = node.id
            view = AnyView(
                view.onTapGesture(count: 2) {
                    NativeUIBridge.sendPressEvent(doubleTapId, nodeId: nodeId)
                }
            )
        }

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
