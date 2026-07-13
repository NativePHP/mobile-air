import SwiftUI

/// Applies animation + transform modifiers to a node.
///
/// Driven by props on the node:
///   - `animate-duration` (ms float, > 0 enables animations on state change).
///   - `animate-delay`    (ms float, start offset; staggers loop phase).
///   - `animate-easing`   (string).
///   - `animate-loop`     (bool — yoyo forever between identity and configured).
///   - `translate-x` / `translate-y` (points, or `__sv:` wire ref).
///   - `scale`            (uniform; 1.0 = identity, or `__sv:` ref).
///   - `rotate`           (degrees, or `__sv:` ref).
///
/// SharedValue bindings (`__sv:` wire refs in companion `{prop}_sv`
/// keys) take precedence over literal values when present. The
/// modifier observes `SharedValueStore` so per-frame updates from
/// gestures redraw at 120fps — all on the UI thread.
///
/// Per-frame interpolation for STATE-CHANGE animations runs on iOS's
/// Core Animation render server (off the main thread). Gesture-driven
/// updates run on the main thread but at native cadence (sub-frame).
struct NodeAnimationModifier: ViewModifier {
    let style: NodeStyle?
    let props: GenericProps

    @State private var loopActive = false
    @ObservedObject private var store = SharedValueStore.shared

    func body(content: Content) -> some View {
        let durationMs = props.getFloat("animate-duration", default: 0)
        let loop = props.getBool("animate-loop")
        let translateX = resolveTransform("translate-x", literal: CGFloat(props.getFloat("translate-x", default: 0)))
        let translateY = resolveTransform("translate-y", literal: CGFloat(props.getFloat("translate-y", default: 0)))
        let scale      = resolveTransform("scale",       literal: CGFloat(props.getFloat("scale",       default: 1)))
        let rotate     = Double(resolveTransform("rotate", literal: CGFloat(props.getFloat("rotate", default: 0))))
        let opacity: Double = {
            let ref = props.getString("opacity_sv", default: "")
            // Style opacity stays 1.0 when SV-bound (the collector routes
            // the binding through the prop bag), so it doubles as the
            // pre-seed base for a wire-fresh id.
            let literal = CGFloat(style?.opacity ?? 1)
            if !ref.isEmpty, let v = store.evaluate(ref, initial: literal) {
                return Double(v)
            }
            return Double(literal)
        }()

        let animate = durationMs > 0 || loop
        let hasTransform = translateX != 0 || translateY != 0
            || scale != 1 || rotate != 0
        let hasSharedBinding = bound("translate-x") || bound("translate-y")
            || bound("scale") || bound("rotate") || bound("opacity")

        // Fast path — nothing animatable, no transform, no binding.
        guard animate || hasTransform || hasSharedBinding else {
            return AnyView(content)
        }

        // Loop mode oscillates between identity and configured.
        // Bindings are NOT looped — they ride the shared value directly.
        let useTarget = loop ? loopActive : true
        let appliedTx     = bound("translate-x") ? translateX : (useTarget ? translateX : 0)
        let appliedTy     = bound("translate-y") ? translateY : (useTarget ? translateY : 0)
        let appliedScale  = bound("scale")       ? scale      : (useTarget ? scale      : 1.0)
        let appliedRotate = bound("rotate")      ? rotate     : (useTarget ? rotate     : 0.0)
        let appliedOpacity = bound("opacity")    ? opacity    : (useTarget ? opacity    : 1.0)

        let snapshot = AnimatableSnapshot(
            opacity: appliedOpacity,
            translateX: appliedTx,
            translateY: appliedTy,
            scale: appliedScale,
            rotate: appliedRotate
        )

        let effectiveMs = durationMs > 0 ? durationMs : 600
        let baseAnim = animationFor(
            easing: props.getString("animate-easing", default: "ease-in-out"),
            durationMs: effectiveMs
        )
        // `animate-delay` offsets the start of the whole animation (the
        // delay is outside `repeatForever`, so loops stagger their phase
        // rather than pausing every cycle).
        let delaySec = Double(props.getFloat("animate-delay", default: 0)) / 1000.0
        let anim = (loop ? baseAnim.repeatForever(autoreverses: true) : baseAnim).delay(delaySec)

        // Opacity applies when:
        //   - this modifier is in animate mode (durationMs > 0 or loop), OR
        //   - opacity is shared-value-bound (driven by a gesture).
        // Otherwise leave it to NodeStyleModifier.
        let applyOpacity = animate || bound("opacity")

        let view = content
            .opacity(applyOpacity ? appliedOpacity : 1.0)
            .scaleEffect(appliedScale)
            .rotationEffect(.degrees(appliedRotate))
            .offset(x: appliedTx, y: appliedTy)
            .animation(animate ? anim : nil, value: snapshot)
            .onAppear { if loop && !loopActive { loopActive = true } }

        return AnyView(view)
    }

    /// If a SharedValue binding is present for `prop`, evaluate it
    /// against the store. Otherwise return the literal. The literal is
    /// also the pre-seed base: PHP writes the SharedValue's current
    /// snapshot alongside every `{prop}_sv` binding, so an id the store
    /// hasn't seen yet (fresh from a re-render) evaluates its formula
    /// against that snapshot instead of 0.
    private func resolveTransform(_ prop: String, literal: CGFloat) -> CGFloat {
        let ref = props.getString("\(prop)_sv", default: "")
        if !ref.isEmpty, let v = store.evaluate(ref, initial: literal) {
            return v
        }
        return literal
    }

    private func bound(_ prop: String) -> Bool {
        !props.getString("\(prop)_sv", default: "").isEmpty
    }

    private func animationFor(easing: String, durationMs: Float) -> Animation {
        let duration = Double(durationMs) / 1000.0
        switch easing {
        case "linear":        return .linear(duration: duration)
        case "ease-in":       return .easeIn(duration: duration)
        case "ease-out":      return .easeOut(duration: duration)
        case "ease-in-out":   return .easeInOut(duration: duration)
        case "spring":        return .spring(duration: duration)
        default:              return .easeInOut(duration: duration)
        }
    }
}

private struct AnimatableSnapshot: Equatable {
    let opacity: Double
    let translateX: CGFloat
    let translateY: CGFloat
    let scale: CGFloat
    let rotate: Double
}
