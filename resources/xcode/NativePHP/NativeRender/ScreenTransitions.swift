import SwiftUI

/// Map a PHP-side `Edge\Transition` value to a SwiftUI `AnyTransition`.
///
/// Screen transitions are core navigation behavior, so the mapper lives in
/// core — `ContentView` (and any host that swaps native trees) calls this with
/// the current `NativeUIBridge.shared.pendingTransition`. A UI plugin only
/// *stages* the value via a bridge function (e.g. native-ui's
/// `NativeUI.Transition.Set`); it does not own the mapping. This mirrors the
/// Android side, where `transitionFor(_:)` already lives in core.
///
/// Recognised: slide_from_right, slide_from_left, slide_from_bottom, fade,
/// fade_from_bottom, scale_from_center, parallax_push, none. Unknown values
/// fall back to opacity.
func nativeScreenTransition(for type: String?) -> AnyTransition {
    switch type {
    case "slide_from_right":
        return .asymmetric(
            insertion: .move(edge: .trailing),
            removal:   .move(edge: .leading)
        )
    case "slide_from_left":
        return .asymmetric(
            insertion: .move(edge: .leading),
            removal:   .move(edge: .trailing)
        )
    case "slide_from_bottom":
        return .move(edge: .bottom)
    case "fade":
        // The ambient `.animation(value: screenKey)` in ContentView drives
        // geometric transitions (`.move`, `.scale`) but NOT a bare opacity
        // change on the `.id`-swapped opaque screen — so pure fade collapsed
        // to an instant cut, and combining it with a scale just produced a
        // (mini) scale transition with the opacity still not animating.
        // Attaching the animation directly to the transition forces SwiftUI
        // to animate the opacity itself, giving a true cross-fade with no
        // geometric/zoom component.
        return .opacity.animation(.easeInOut(duration: 0.3))
    case "fade_from_bottom":
        return .move(edge: .bottom).combined(with: .opacity)
    case "scale_from_center":
        // Scale the incoming screen in (from 0.1), fully opaque, over the held
        // outgoing screen. Two gotchas this avoids:
        //  • The old `.scale` (from 0) `.combined(with: .opacity)` hid the zoom
        //    — near-transparent while small, so only the last sliver of growth
        //    registered. Dropping the opacity shows the whole zoom.
        //  • Like `fade`, a scale is NOT animated by ContentView's ambient
        //    `.animation(value:)` (only `.move` is). Without attaching the
        //    animation directly, the screen just snaps to full size and the
        //    `scale:` value has no visible effect. `.animation(_:)` fixes that.
        return .asymmetric(insertion: .scale(scale: 0.1), removal: .identity)
            .animation(.easeInOut(duration: 0.3))
    case "parallax_push":
        // iOS-style native push: the incoming screen slides fully in from the
        // trailing edge while the outgoing screen drifts a short distance to
        // the leading edge underneath (a layered depth cue, not a flat slide).
        // A fixed ~30%-of-phone-width drift keeps this warning-free (no
        // deprecated UIScreen.main) and the incoming screen covers most of it.
        return .asymmetric(
            insertion: .move(edge: .trailing),
            removal:   .offset(x: -120)
        )
    case "none":
        return .identity
    default:
        return .opacity
    }
}
