import SwiftUI

/// Shared-element morph for nodes carrying a `ref` — the native analogue of
/// CSS `view-transition-name`, keyed off the element's own name.
///
/// Identity is the element's `ref`, which already existed as a test-targeting
/// handle. Two screens naming an element the same thing is exactly what a
/// shared element IS, so there is no second naming concept to learn. A ref
/// that exists only for tests and must never travel opts out with
/// `morph="none"`.
///
/// Motion is shaped by three optional props:
///   - `morph`          — frame (default) | position | size | none
///   - `morph_duration` — ms; overrides the shared view-transition pace
///   - `morph_easing`   — linear / ease-in / ease-out / ease-in-out / spring
///
/// ## Why this isn't the textbook `matchedGeometryEffect` usage
///
/// The canonical SwiftUI hero animation relies on one view being REMOVED and
/// its partner INSERTED in the same animated transaction; SwiftUI then
/// interpolates the inserted view's frame from the removed one's. That
/// handoff cannot happen here: the router's two-layer swap deliberately keeps
/// the outgoing screen mounted (see `NativeUIBridge.OutgoingScreen`) and drops
/// it a beat later, so at swap time both heroes coexist and nothing is ever
/// removed. Left alone, that yields two `isSource: true` views sharing one id —
/// undefined behaviour — and no motion at all.
///
/// ## What drives the morph instead
///
/// The geometry source is flipped between the two coexisting heroes inside an
/// animated transaction, which `matchedGeometryEffect` does interpolate:
///
///   1. At the swap the outgoing hero is the source, so the freshly-inserted
///      incoming hero is slaved to it and renders at the OLD screen's frame —
///      the detail image appears at the grid thumbnail's position.
///   2. One runloop tick later `heroSourceIsIncoming` flips inside
///      `withAnimation`, making the incoming hero the source. It is now
///      driven by its own natural layout, so it animates out of the
///      thumbnail's frame and into its destination — the fly.
///
/// The outgoing hero, now the non-source, is slaved to the destination frame
/// for the rest of the window. It would otherwise render a duplicate flying
/// underneath, so it is hidden once it loses source status; the screen above
/// it is cross-fading in and covers the gap.
///
/// A name that appears on only one screen never has a partner to match, so the
/// effect resolves to that single view and is inert — the intended graceful
/// degradation rather than an error.
struct NodeHeroModifier: ViewModifier {
    let props: GenericProps

    @Environment(\.heroNamespace) private var namespace
    @Environment(\.heroIsOutgoing) private var isOutgoing
    @ObservedObject private var bridge = NativeUIBridge.shared

    func body(content: Content) -> some View {
        let name = props.getString("ref", default: "")
        let mode = props.getString("morph", default: "frame")

        // Fast path — the overwhelming majority of nodes. No ref, an explicit
        // opt-out, or no host providing a namespace (plain tree rendering,
        // previews, tests).
        guard !name.isEmpty, mode != "none", let namespace else {
            return AnyView(content)
        }

        let isSource = isOutgoing ? !bridge.heroSourceIsIncoming
                                  : bridge.heroSourceIsIncoming

        return AnyView(
            content
                // Hiding the outgoing copy only once it stops being the
                // source keeps the OLD screen looking untouched right up to
                // the moment the morph starts — hiding it unconditionally
                // would blank the thumbnail a frame early, which reads as a
                // flicker on the screen the user is still looking at.
                .opacity(isOutgoing && !isSource ? 0 : 1)
                .matchedGeometryEffect(
                    id: name,
                    in: namespace,
                    properties: Self.properties(for: mode),
                    isSource: isSource
                )
                // Per-element pacing. A SCOPED animation keyed on the same
                // value the source flip changes overrides the ambient
                // withAnimation transaction for this view only, which is what
                // lets two heroes in one navigation arrive at different
                // moments. Absent props leave the ambient pace untouched.
                .animation(customAnimation, value: bridge.heroSourceIsIncoming)
        )
    }

    /// `morph` → which geometry the pair shares.
    ///
    ///   frame    — position AND size: the element travels and resizes.
    ///   position — travels but keeps its own size (a fly, not a grow).
    ///   size     — resizes in place without travelling.
    private static func properties(for mode: String) -> MatchedGeometryProperties {
        switch mode {
        case "position": return .position
        case "size":     return .size
        default:         return .frame
        }
    }

    /// nil when the node sets no timing props, which leaves the ambient
    /// `nativeViewTransitionAnimation` in charge — the common case, and the
    /// one where every hero should stay in step with the screen cross-fade.
    private var customAnimation: Animation? {
        let duration = Double(props.getFloat("morph_duration", default: 0))
        let easing = props.getString("morph_easing", default: "")

        guard duration > 0 || !easing.isEmpty else { return nil }

        return nativeEasedAnimation(
            easing.isEmpty ? "ease-in-out" : easing,
            // Match the shared view-transition pace when only an easing was
            // named, so `morph-easing="spring"` alone is a sensible thing to
            // write.
            durationMs: duration > 0 ? duration : 350
        )
    }
}
