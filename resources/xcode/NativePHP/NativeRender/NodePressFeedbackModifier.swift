import SwiftUI

/// Applies instant press feedback transforms on the UI thread — no
/// PHP roundtrip. The element scales / fades / nudges while held and
/// snaps back on release with a spring curve.
///
/// Driven by props:
///   - `press-scale`        — uniform scale while pressed (e.g. 0.95).
///   - `press-opacity`      — opacity while pressed (e.g. 0.7).
///   - `press-translate-y`  — Y offset while pressed (points).
///
/// Press detection uses a zero-distance `DragGesture` attached via
/// `simultaneousGesture` so it composes with the existing tap and
/// long-press handlers — the visual feedback happens immediately on
/// press-in, `@press` still fires on tap, and scrolls aren't blocked.
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
                .simultaneousGesture(
                    DragGesture(minimumDistance: 0)
                        .onChanged { _ in
                            if !isPressed { isPressed = true }
                        }
                        .onEnded { _ in
                            isPressed = false
                        }
                )
        )
    }
}
