import SwiftUI

/// SwiftUI View modifier that wires onPress / onLongPress callbacks from a
/// NativeUINode to tap and long-press gestures.  Used by plugin renderers
/// (e.g. NativeUI) that build their UI in SwiftUI rather than UIKit.
extension View {
    func applyClickHandlers(node: NativeUINode) -> some View {
        self.modifier(ClickHandlerModifier(node: node))
    }
}

/// Fires press-down the instant the finger makes contact and press-up on
/// release. The down/up callback ids ride the props dict (`on_press_down` /
/// `on_press_up`) and reuse the Press event type — the callback id alone
/// routes to the @tapDown / @tapUp handler on the PHP side.
///
/// `DragGesture(minimumDistance: 0)` is SwiftUI's touch-contact primitive:
/// `.onChanged` fires on first contact (and on every move — gated by
/// `pressed` so down fires once), `.onEnded` on release. Attached as a
/// simultaneousGesture so it composes with an @tap tap on the same node.
/// A held button must never stick: `onDisappear` synthesizes the up if the
/// view is torn down mid-press (navigation, sheet dismiss, re-render drop).
struct PressDownUpModifier: ViewModifier {
    let downId: Int
    let upId: Int
    let nodeId: Int

    @State private var pressed = false

    func body(content: Content) -> some View {
        content
            .contentShape(Rectangle())
            .simultaneousGesture(
                DragGesture(minimumDistance: 0)
                    .onChanged { _ in
                        guard !pressed else { return }
                        pressed = true
                        if downId != 0 {
                            NativeElementBridge.sendPressEvent(downId, nodeId: nodeId)
                        }
                    }
                    .onEnded { _ in
                        guard pressed else { return }
                        pressed = false
                        if upId != 0 {
                            NativeElementBridge.sendPressEvent(upId, nodeId: nodeId)
                        }
                    }
            )
            .onDisappear {
                guard pressed else { return }
                pressed = false
                if upId != 0 {
                    NativeElementBridge.sendPressEvent(upId, nodeId: nodeId)
                }
            }
    }
}

private struct ClickHandlerModifier: ViewModifier {
    let node: NativeUINode

    func body(content: Content) -> some View {
        var view = AnyView(content)

        // Press-down/up: attached first so the zero-distance drag tracks
        // touch contact without stealing the tap recognizers below.
        let pressDownId = node.props.getInt("on_press_down")
        let pressUpId = node.props.getInt("on_press_up")
        if pressDownId != 0 || pressUpId != 0 {
            view = AnyView(
                view.modifier(PressDownUpModifier(
                    downId: pressDownId, upId: pressUpId, nodeId: node.id
                ))
            )
        }

        // Double-tap is carried in props (`on_double_tap`), not a dedicated
        // node field. Attached before the single tap so the 2-count gesture
        // gets first claim. Reuses the Press event type — the callback id
        // routes to the @doubleTap handler.
        let doubleTapId = node.props.getInt("on_double_tap")
        if doubleTapId != 0 {
            let nodeId = node.id
            view = AnyView(
                view.onTapGesture(count: 2) {
                    KeyboardFocusPolicy.dispatchPress {
                        NativeUIBridge.sendPressEvent(doubleTapId, nodeId: nodeId)
                    }
                }
            )
        }

        if node.onPress != 0 {
            let cbId = node.onPress
            let nodeId = node.id
            view = AnyView(
                view.onTapGesture {
                    KeyboardFocusPolicy.dispatchPress {
                        NativeUIBridge.sendPressEvent(cbId, nodeId: nodeId)
                    }
                }
            )
        }

        if node.onLongPress != 0 {
            let cbId = node.onLongPress
            let nodeId = node.id
            view = AnyView(
                view.onLongPressGesture(minimumDuration: 0.5) {
                    KeyboardFocusPolicy.dispatchPress {
                        NativeUIBridge.sendLongPressEvent(cbId, nodeId: nodeId)
                    }
                }
            )
        }

        return view
    }
}
