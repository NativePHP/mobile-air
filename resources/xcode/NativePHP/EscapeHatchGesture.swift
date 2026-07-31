import SwiftUI
import UIKit

/// Global three-finger swipe-right recognizer, attached to the key window so it
/// fires over ANY content — the local native-ui tree, the WebView, or a remote
/// app rendered through Jump. On fire it posts `JumpEscapeHatch`; the Jump
/// client (discovery plugin) uses it as an escape hatch out of any connected
/// demo app back to the Jump home screen.
///
/// It lives on the window (not an overlay) with `cancelsTouchesInView = false`
/// and simultaneous recognition, so it observes touches without stealing them
/// from the app's own gestures.
struct EscapeHatchGesture: UIViewRepresentable {
    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> UIView {
        // A zero-interaction anchor view; the recognizer goes on its window.
        let v = UIView(frame: .zero)
        v.isUserInteractionEnabled = false
        return v
    }

    func updateUIView(_ uiView: UIView, context: Context) {
        // Attach once, as soon as the view is in a window.
        guard context.coordinator.recognizer == nil else { return }
        DispatchQueue.main.async {
            guard context.coordinator.recognizer == nil,
                  let window = uiView.window ?? Self.keyWindow() else { return }
            let swipe = UISwipeGestureRecognizer(
                target: context.coordinator,
                action: #selector(Coordinator.fired)
            )
            swipe.direction = .right
            swipe.numberOfTouchesRequired = 3
            swipe.cancelsTouchesInView = false
            swipe.delaysTouchesBegan = false
            swipe.delegate = context.coordinator
            window.addGestureRecognizer(swipe)
            context.coordinator.recognizer = swipe
        }
    }

    private static func keyWindow() -> UIWindow? {
        UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .flatMap { $0.windows }
            .first { $0.isKeyWindow }
    }

    final class Coordinator: NSObject, UIGestureRecognizerDelegate {
        var recognizer: UISwipeGestureRecognizer?

        @objc func fired() {
            DebugLogger.shared.log("🖐️ 3-finger swipe-right → JumpEscapeHatch")
            NotificationCenter.default.post(name: NSNotification.Name("JumpEscapeHatch"), object: nil)
        }

        // Coexist with the app's own gestures rather than competing with them.
        func gestureRecognizer(_ g: UIGestureRecognizer,
                               shouldRecognizeSimultaneouslyWith other: UIGestureRecognizer) -> Bool { true }
    }
}
