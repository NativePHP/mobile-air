import SwiftUI

/// Exit effect for the outgoing screen of a router-level swap.
///
/// ContentView applies this to the screen held in
/// `NativeUIBridge.outgoingScreen` (the two-layer swap — see that property's
/// doc). Because the outgoing screen keeps its identity and stays mounted,
/// its exit is driven by ORDINARY animated modifiers, which SwiftUI animates
/// reliably — unlike removal transitions, which are captured at insertion
/// and ignore later updates, and unlike AnyTransition.modifier removals,
/// which don't interpolate in this setup (both verified frame-by-frame
/// on-device).
///
/// Effects by staged transition:
///   - `parallax_push` — the outgoing screen drifts ~1/3 screen-width to
///     the leading edge and dims under a darkening scrim while the incoming
///     screen slides over it (UIKit's native push look).
///   - everything else — static hold: the old screen simply stays put,
///     fully opaque, until the incoming screen has covered it.
struct ScreenExitModifier: ViewModifier {
    /// Flips false → true (same view identity) when the screen becomes
    /// the outgoing layer — the value change is what animates the effect.
    let isExiting: Bool
    /// The transition staged for the navigation that displaced this screen.
    let transition: String?

    private var isParallax: Bool { isExiting && transition == "parallax_push" }

    func body(content: Content) -> some View {
        // "Old drifts BACK underneath": the outgoing screen recedes in
        // depth — scales down, drifts toward the leading edge, dims, and
        // separates from the background with a shadow — while the incoming
        // screen sweeps over it. UIKit's flat 1/3-width drift alone is
        // nearly imperceptible (verified frame-by-frame: the drift ran on
        // schedule and still read as a plain slide), so this uses the
        // Material-style recede, which stays legible in dark mode too.
        //
        // `.easeOut` (front-loaded), unlike the incoming screen's
        // `.easeInOut`: the recede spends its motion budget EARLY, while
        // the old screen is still mostly uncovered — by the halfway point
        // of an easeInOut scrim the screen was already 90% hidden.
        content
            .scaleEffect(isParallax ? 0.88 : 1.0)
            .offset(x: isParallax ? -120 : 0)
            .overlay(
                Color.black
                    .opacity(isParallax ? 0.40 : 0)
                    .ignoresSafeArea()
                    .allowsHitTesting(false)
            )
            .shadow(
                color: .black.opacity(isParallax ? 0.35 : 0),
                radius: 24
            )
            // Starts IMMEDIATELY on the swap, while the incoming screen's
            // insertion is delayed (see the parallax_push case) — the old
            // screen visibly drops back FIRST, then the new one sweeps
            // over it. Without that beat of sequencing the recede happens
            // entirely underneath the cover and reads as a plain slide.
            .animation(.easeOut(duration: 0.50), value: isExiting)
    }
}

/// The TRANSACTION animation for a router-level screen swap — passed to
/// ContentView's ambient `.animation(_:value: screenKey)`.
///
/// This function exists because `.animation(_:)` attached to an
/// `AnyTransition` is IGNORED for `.move` insertions (measured on-device:
/// a `.move` with an attached 0.55s+0.15s-delay animation swept in ~150ms
/// — the ambient transaction animation's pace). The ambient animation is
/// what actually drives `.move`, so it must be computed per staged
/// transition rather than hardcoded. Effects with scoped animations
/// (`ScreenExitModifier`'s recede, `value: isExiting`) are unaffected.
/// The single animation shared by a `view_transition` swap: the screen
/// cross-fade, and the `matchedGeometryEffect` source flip that drives the
/// shared-element morph (`NodeHeroModifier`).
///
/// Deliberately ONE constant used by both. The morph and the cross-fade
/// beneath it have to finish together — if the screens settle first the hero
/// is left flying over a static background, and if the hero settles first it
/// sits at its destination while the old screen is still visibly fading.
let nativeViewTransitionAnimation: Animation = .easeInOut(duration: 0.35)

/// The one string→`Animation` table for the whole renderer.
///
/// This mapping was independently duplicated in `NodeAnimationModifier` and
/// `NodeContentTransitionModifier`; the shared-element morph would have made a
/// third copy, so it lives here instead and those two call in. Vocabulary is
/// the existing one PHP already emits for `animate-easing`, so nothing new has
/// to be learned to tune a morph.
func nativeEasedAnimation(_ easing: String, durationMs: Double) -> Animation {
    let duration = durationMs / 1000.0
    switch easing {
    case "linear":      return .linear(duration: duration)
    case "ease-in":     return .easeIn(duration: duration)
    case "ease-out":    return .easeOut(duration: duration)
    case "ease-in-out": return .easeInOut(duration: duration)
    case "spring":      return .spring(duration: duration)
    default:            return .easeInOut(duration: duration)
    }
}

func nativeScreenSwapAnimation(for type: String?) -> Animation {
    switch type {
    case "parallax_push":
        // Delayed sweep: the outgoing screen's recede (starts immediately,
        // scoped animation) gets a visible beat before the cover moves.
        return .easeInOut(duration: 0.40).delay(0.10)
    case "none":
        return .linear(duration: 0)
    case "view_transition":
        return nativeViewTransitionAnimation
    case "fade", "fade_from_bottom", "scale_from_center":
        return .easeInOut(duration: 0.3)
    default:
        return .easeInOut(duration: 0.25)
    }
}

/// Map a PHP-side `Edge\Transition` value to a SwiftUI `AnyTransition`.
///
/// Screen transitions are core navigation behavior, so the mapper lives in
/// core — `ContentView` (and any host that swaps native trees) calls this with
/// the current `NativeUIBridge.shared.pendingTransition`. A UI plugin only
/// *stages* the value via a bridge function (e.g. native-ui's
/// `NativeUI.Transition.Set`); it does not own the mapping. This mirrors the
/// Android side, where `transitionFor(_:)` already lives in core.
///
/// **Insertion-only.** These transitions animate the INCOMING screen; the
/// outgoing screen's exit is handled by `ScreenExitModifier` on the held
/// `outgoingScreen` layer, never by a removal transition (see above). Every
/// removal here is `.identity`: the only view removal that ever happens is
/// the held outgoing layer being dropped AFTER the transition, fully covered
/// by the opaque new screen — so the unclocked instant removal is invisible.
///
/// Non-`.move` insertions (opacity / scale / offset) carry their own
/// `.animation(...)` — the ambient `.animation(value: screenKey)` in
/// ContentView only drives `.move`.
///
/// Recognised: slide_from_right, slide_from_left, slide_from_bottom, fade,
/// fade_from_bottom, scale_from_center, parallax_push, none. Unknown values
/// fall back to fade.
func nativeScreenTransition(for type: String?) -> AnyTransition {
    switch type {
    case "slide_from_right":
        return .asymmetric(
            insertion: .move(edge: .trailing),
            removal:   .identity
        )
        .animation(.easeInOut(duration: 0.25))
    case "slide_from_left":
        return .asymmetric(
            insertion: .move(edge: .leading),
            removal:   .identity
        )
        .animation(.easeInOut(duration: 0.25))
    case "slide_from_bottom":
        // Sheet-like rise: the incoming screen covers the held old screen
        // from the bottom edge.
        return .asymmetric(
            insertion: .move(edge: .bottom),
            removal:   .identity
        )
        .animation(.easeInOut(duration: 0.25))
    case "fade":
        // True cross-fade: incoming fades in over the held old screen — no
        // background flash-through, since the old screen stays opaque
        // beneath for the whole window.
        return .asymmetric(insertion: .opacity, removal: .identity)
            .animation(.easeInOut(duration: 0.3))
    case "fade_from_bottom":
        // Short upward drift + fade over the held outgoing screen — the
        // conventional "fade from bottom" (React Navigation's fadeFromBottom,
        // classic Android activity open). Fixed 96pt drift, same no-UIScreen
        // rationale as parallax_push. Clearly distinct from the full-height
        // slide_from_bottom.
        return .asymmetric(
            insertion: .offset(y: 96).combined(with: .opacity),
            removal:   .identity
        )
        .animation(.easeOut(duration: 0.3))
    case "scale_from_center":
        // Scale the incoming screen in (from 0.1), fully opaque, over the held
        // outgoing screen. The old `.scale` (from 0) `.combined(with: .opacity)`
        // hid the zoom — near-transparent while small, so only the last sliver
        // of growth registered. Dropping the opacity shows the whole zoom.
        return .asymmetric(insertion: .scale(scale: 0.1), removal: .identity)
            .animation(.easeInOut(duration: 0.3))
    case "parallax_push":
        // Layered push: the incoming screen slides in from the trailing
        // edge; the outgoing screen's recede (scale + drift + dim + shadow)
        // is applied by `ScreenExitModifier` on the held outgoing layer.
        // The insertion is DELAYED ~120ms so the recede — which starts
        // immediately — gets a clear beat before the cover sweeps over it;
        // that sequencing is what makes the two layers legible. Total
        // ~0.7s: deliberately slower than a stock push so the depth reads.
        return .asymmetric(
            insertion: .move(edge: .trailing),
            removal:   .identity
        )
        .animation(.easeInOut(duration: 0.55).delay(0.15))
    case "view_transition":
        // Shared-element swap. The screens themselves only cross-fade — the
        // motion the user reads comes from the elements morphing across
        // (`NodeHeroModifier`), and a directional slide underneath would
        // fight it. Same held-outgoing/`.identity`-removal shape as `fade`,
        // so the old screen stays opaque beneath for the whole window and
        // the morphing element never flies over a bare background.
        return .asymmetric(insertion: .opacity, removal: .identity)
            .animation(nativeViewTransitionAnimation)
    case "none":
        // Instant cut. The incoming screen renders fully opaque on the same
        // frame (zIndexed above), so nothing flashes.
        return .identity
    default:
        return .asymmetric(insertion: .opacity, removal: .identity)
            .animation(.easeInOut(duration: 0.3))
    }
}
