import SwiftUI

/// Marks a node as a shared element and keeps its on-screen frame reported.
///
/// Identity is the element's `ref` — the same handle used for test targeting.
/// Two screens naming an element the same thing morph it between them during a
/// `view_transition` navigation. A ref that exists only as a test handle and
/// must never travel opts out with `morph="none"`.
///
/// This modifier does NOT animate anything. The flight itself is rendered by
/// `HeroFlightOverlay`, above both screens, from frames collected here — see
/// `HeroFlightStore` for why SwiftUI needs the element lifted out of the screen
/// layers rather than animated in place.
///
/// Its two jobs:
///   - **Report** the element's global frame, which is the origin of the next
///     flight and the destination of the current one.
///   - **Hide** the element while its copy is in transit, so the real one does
///     not double-draw beneath the overlay or leave a ghost at the origin.
///
/// Motion is shaped by props read on the far side, in the store:
///   - `morph`          — frame (default) | position | size | none
///   - `morph_duration` — ms; overrides the shared 350ms view-transition pace
///   - `morph_easing`   — linear / ease-in / ease-out / ease-in-out / spring
struct NodeHeroModifier: ViewModifier {
    let props: GenericProps

    @Environment(\.heroIsOutgoing) private var isOutgoing
    @Environment(\.heroIsFlyingCopy) private var isFlyingCopy
    @ObservedObject private var store = HeroFlightStore.shared

    func body(content: Content) -> some View {
        let ref = props.getString("ref", default: "")
        let mode = props.getString("morph", default: "frame")

        // Fast path — the overwhelming majority of nodes carry no ref at all.
        guard !ref.isEmpty, mode != "none" else {
            return AnyView(content)
        }

        // The overlay's own copy renders through this same modifier. It must
        // stay visible — it IS the flight — and must not report its mid-air
        // position as the element's real geometry.
        guard !isFlyingCopy else {
            return AnyView(content)
        }

        return AnyView(
            content
                .opacity(store.hidden.contains(ref) ? 0 : 1)
                .onGeometryChange(for: CGRect.self) { proxy in
                    proxy.frame(in: .global)
                } action: { rect in
                    store.report(ref: ref, rect: rect, isOutgoing: isOutgoing)
                }
        )
    }
}
