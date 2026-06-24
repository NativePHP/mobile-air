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
/// fade_from_bottom, scale_from_center, none. Unknown values fall back to
/// opacity.
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
        return .opacity
    case "fade_from_bottom":
        return .move(edge: .bottom).combined(with: .opacity)
    case "scale_from_center":
        return .scale.combined(with: .opacity)
    case "none":
        return .identity
    default:
        return .opacity
    }
}
