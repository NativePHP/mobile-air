import SwiftUI
import UIKit

/// Applies instant press feedback transforms on the UI thread — no
/// PHP roundtrip. The element scales / fades / nudges while held and
/// snaps back on release with a spring curve.
///
/// Driven by props:
///   - `press-scale`        — uniform scale while pressed (e.g. 0.95).
///   - `press-opacity`      — opacity while pressed (e.g. 0.7).
///   - `press-translate-y`  — Y offset while pressed (points).
///
/// Press detection uses a UIKit long-press recognizer with a zero
/// duration (`PressTrackingGesture`) that recognizes alongside every
/// other gesture and never cancels touches, so the feedback shows on
/// press-in while `@tap` / `@press` and long-press keep firing. A
/// SwiftUI `DragGesture(minimumDistance: 0)` claimed the touch before
/// an enclosing ScrollView could start panning, so a swipe that began
/// on a pressable never scrolled.
///
/// Multiplies / adds onto the base `NodeAnimationModifier` transforms,
/// so `<column :scale="1.2" :press-scale="0.95">` shows a base scale
/// of 1.2 that briefly shrinks toward 1.14 (1.2 × 0.95) on tap.
struct NodePressFeedbackModifier: ViewModifier {
    let props: GenericProps

    @State private var isPressed = false

    func body(content: Content) -> some View {
        // press-* defaults to "no feedback" sentinels (0 / 0 / 0).
        // Treat 0 on scale/opacity as "not configured" so default
        // identity values are 1.0 — author has to opt in explicitly.
        let pressScale = props.getFloat("press-scale", default: 0)
        let pressOpacity = props.getFloat("press-opacity", default: 0)
        let pressTy = CGFloat(props.getFloat("press-translate-y", default: 0))

        let hasFeedback = pressScale > 0 || pressOpacity > 0 || pressTy != 0

        guard hasFeedback else {
            return AnyView(content)
        }

        // Identity values when not pressed; configured values when pressed.
        let scale = isPressed && pressScale > 0 ? CGFloat(pressScale) : 1.0
        let opacity = isPressed && pressOpacity > 0 ? Double(pressOpacity) : 1.0
        let ty = isPressed ? pressTy : 0

        return AnyView(
            content
                .scaleEffect(scale)
                .opacity(opacity)
                .offset(y: ty)
                .animation(.spring(response: 0.22, dampingFraction: 0.7), value: isPressed)
                .gesture(PressTrackingGesture { pressing in
                    if isPressed != pressing { isPressed = pressing }
                })
        )
    }
}

/// Reports whether a finger is down on the view, without competing for
/// the touch: it recognizes simultaneously with everything (scroll pans,
/// taps, long-presses) and never cancels or delays touches. The press
/// ends on lift, on cancellation, or once the finger has travelled far
/// enough to read as a scroll — measured in window space, because the
/// content under a scrolling finger moves with it.
struct PressTrackingGesture: UIGestureRecognizerRepresentable {
    /// Same slop UIKit allows a tap before calling it a drag.
    private static let scrollSlop: CGFloat = 10

    let onPressingChanged: (Bool) -> Void

    func makeCoordinator(converter: CoordinateSpaceConverter) -> Coordinator {
        Coordinator()
    }

    func makeUIGestureRecognizer(context: Context) -> UILongPressGestureRecognizer {
        let recognizer = UILongPressGestureRecognizer()
        recognizer.minimumPressDuration = 0
        recognizer.cancelsTouchesInView = false
        recognizer.delaysTouchesBegan = false
        recognizer.delaysTouchesEnded = false
        recognizer.delegate = context.coordinator
        return recognizer
    }

    func handleUIGestureRecognizerAction(_ recognizer: UILongPressGestureRecognizer, context: Context) {
        let location = recognizer.location(in: nil)

        switch recognizer.state {
        case .began:
            context.coordinator.origin = location
            onPressingChanged(true)
        case .changed:
            if hypot(location.x - context.coordinator.origin.x, location.y - context.coordinator.origin.y) > Self.scrollSlop {
                onPressingChanged(false)
            }
        default:
            onPressingChanged(false)
        }
    }

    final class Coordinator: NSObject, UIGestureRecognizerDelegate {
        var origin: CGPoint = .zero

        func gestureRecognizer(
            _ gestureRecognizer: UIGestureRecognizer,
            shouldRecognizeSimultaneouslyWith otherGestureRecognizer: UIGestureRecognizer
        ) -> Bool {
            true
        }
    }
}
