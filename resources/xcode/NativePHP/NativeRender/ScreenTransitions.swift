import SwiftUI
import UIKit

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

    /// Modal reveal: the outgoing screen slides DOWN off the bottom edge
    /// while the incoming screen sits fully rendered beneath it (its
    /// insertion is `.identity`; ContentView z-orders the outgoing layer on
    /// top for this transition). Inverse of `slide_from_bottom`.
    private var isSlideDown: Bool { isExiting && transition == "slide_to_bottom" }

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
            // Modal dismissal slide — pushes the whole held screen below the
            // bottom edge. UIScreen height is a safe upper bound here (the
            // held layer is full-screen); animated by the scoped animation
            // below, same as the parallax recede.
            .offset(y: isSlideDown ? UIScreen.main.bounds.height : 0)
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
            .animation(
                isSlideDown ? .easeInOut(duration: 0.30) : .easeOut(duration: 0.50),
                value: isExiting
            )
    }
}

/// The screen cross-fade beneath a `morph` swap.
///
/// 350ms on purpose: it matches the default pace of each element flying above
/// it (`HeroFlightStore.duration`) and Android's `transitionFor("morph")`. The
/// morph and the cross-fade have to finish together — if the screens settle
/// first the hero is left flying over a static background, and if the hero
/// settles first it sits at its destination while the old screen is still
/// visibly fading.
let nativeMorphAnimation: Animation = .easeInOut(duration: 0.35)

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
func nativeScreenSwapAnimation(for type: String?) -> Animation {
    switch type {
    case "parallax_push":
        // Delayed sweep: the outgoing screen's recede (starts immediately,
        // scoped animation) gets a visible beat before the cover moves.
        return .easeInOut(duration: 0.40).delay(0.10)
    case "none":
        return .linear(duration: 0)
    case "slide_to_bottom":
        // Incoming inserts with .identity (it sits beneath); the visible
        // motion is the outgoing layer's scoped slide-down animation.
        return .linear(duration: 0)
    case "morph":
        return nativeMorphAnimation
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
    case "morph":
        // Shared-element swap. The screens themselves only cross-fade — the
        // motion the user reads comes from the elements morphing across
        // (`NodeHeroModifier`), and a directional slide underneath would
        // fight it. Same held-outgoing/`.identity`-removal shape as `fade`,
        // so the old screen stays opaque beneath for the whole window and
        // the morphing element never flies over a bare background.
        return .asymmetric(insertion: .opacity, removal: .identity)
            .animation(nativeMorphAnimation)
    case "slide_to_bottom":
        // Modal reveal: the incoming screen appears instantly BENEATH the
        // held outgoing layer (ContentView z-orders the outgoing on top for
        // this transition); the outgoing's ScreenExitModifier slides it down
        // off-screen. No insertion motion here by design.
        return .identity
    case "none":
        // Instant cut. The incoming screen renders fully opaque on the same
        // frame (zIndexed above), so nothing flashes.
        return .identity
    default:
        return .asymmetric(insertion: .opacity, removal: .identity)
            .animation(.easeInOut(duration: 0.3))
    }
}

/// The interactive edge swipe from the root of a stack session back to the
/// tabs screen beneath it (`NativeUIBridge.backdropScreen`).
///
/// `StackRootEdgeSwipeBack`'s pan drives it. While the finger moves, the
/// stack screen follows it and the tabs screen comes into view underneath,
/// drifting in from a third of the width and brightening, as a system pop
/// looks. Released so that it `commits(...)`, the stack screen slides off
/// and the back goes to PHP. When PHP publishes the tabs level,
/// the bridge makes the revealed view the current screen with no further
/// animation (`finish()`). Otherwise the stack screen slides back.
///
/// If PHP doesn't go back (an `onBackPressed()` override that keeps the
/// screen publishes nothing), the stack screen slides back after a second.
final class RootBackSwipe: ObservableObject {
    static let shared = RootBackSwipe()

    /// Horizontal offset of the current screen in points, positive to the
    /// right. 0 while no swipe is in progress.
    @Published private(set) var offset: CGFloat = 0

    /// From the start of a swipe until the stack screen is back in place or
    /// PHP has popped. The backdrop screen is only visible while this is set.
    @Published private(set) var isActive = false

    /// Set once a committed swipe has sent the back to PHP. The bridge
    /// completes the swipe when the backdrop's level is published.
    private(set) var isAwaitingPop = false

    /// Width of the swiping screen, captured when the swipe begins.
    private(set) var width: CGFloat = 1

    /// Bumped by every begin and finish, so the completion of an animation
    /// that a later swipe or a publish superseded does nothing.
    private var generation = 0

    private init() {}

    /// How far the swipe has gone, from 0 (in place) to 1 (fully off).
    var progress: CGFloat { min(abs(offset) / width, 1) }

    func begin(width: CGFloat) {
        generation += 1
        self.width = max(width, 1)
        isAwaitingPop = false
        offset = 0
        isActive = true
    }

    /// `direction` is 1 when the swipe moves the screen to the right (a
    /// left-edge swipe) and -1 in right-to-left layouts.
    func update(translation: CGFloat, direction: CGFloat) {
        guard isActive, !isAwaitingPop else { return }
        offset = direction * min(max(translation * direction, 0), width)
    }

    func end(velocity: CGFloat, direction: CGFloat) {
        guard isActive, !isAwaitingPop else { return }
        let distance = offset * direction
        let speed = velocity * direction
        if Self.commits(distance: distance, speed: speed, width: width) {
            commit(direction: direction, distance: distance, speed: speed)
        } else {
            cancel()
        }
    }

    /// Whether a swipe released `distance` points in, moving at `speed`
    /// points per second (both positive in the back direction), goes back,
    /// as a system pop decides: a flick toward the far edge goes back from
    /// anywhere, and a release past the middle goes back unless the finger
    /// was heading back toward the edge.
    static func commits(distance: CGFloat, speed: CGFloat, width: CGFloat) -> Bool {
        distance > 0 && (speed > 500 || (distance > width / 2 && speed >= 0))
    }

    /// Slide the stack screen back into place.
    func cancel() {
        guard isActive else { return }
        isAwaitingPop = false
        let started = generation
        withAnimation(.easeOut(duration: 0.25)) {
            offset = 0
        } completion: { [weak self] in
            guard let self, self.generation == started else { return }
            self.isActive = false
        }
    }

    /// PHP has published the revealed level and the bridge made it the
    /// current screen. Call inside the bridge's no-animation transaction.
    func finish() {
        generation += 1
        isAwaitingPop = false
        isActive = false
        offset = 0
    }

    private func commit(direction: CGFloat, distance: CGFloat, speed: CGFloat) {
        let started = generation
        // Keep the finger's pace for the rest of the way, within limits.
        let duration = min(max((width - distance) / max(speed, 1), 0.12), 0.3)
        withAnimation(.easeOut(duration: duration)) {
            offset = direction * width
        } completion: { [weak self] in
            guard let self, self.generation == started, self.isActive else { return }
            self.isAwaitingPop = true
            NativeElementBridge.sendSystemBackEvent()
            DispatchQueue.main.asyncAfter(deadline: .now() + 1.0) { [weak self] in
                guard let self, self.generation == started, self.isAwaitingPop else { return }
                self.cancel()
            }
        }
    }
}

/// Moves a native screen for `RootBackSwipe`. ContentView applies it to every
/// screen layer, with the same structure for every role, so a layer changing
/// role (the backdrop becoming the current screen) keeps its identity.
struct RootBackSwipeModifier: ViewModifier {
    enum Role {
        /// The screen on top: follows the finger.
        case current
        /// The kept tabs screen beneath a stack session: hidden unless a
        /// swipe is in progress, never hit-testable.
        case backdrop
        /// The outgoing layer of a router-level swap: unaffected.
        case other
    }

    let role: Role

    @ObservedObject private var swipe = RootBackSwipe.shared
    @Environment(\.layoutDirection) private var layoutDirection

    func body(content: Content) -> some View {
        let revealing = role == .backdrop && swipe.isActive
        let remaining = 1 - swipe.progress
        let direction: CGFloat = swipe.offset < 0 ? -1 : 1
        // The swipe is in screen coordinates; SwiftUI mirrors offsets in a
        // right-to-left layout, so mirror them back.
        let screen: CGFloat = layoutDirection == .rightToLeft ? -1 : 1

        content
            .overlay(
                Color.black
                    .opacity(revealing ? 0.15 * remaining : 0)
                    .ignoresSafeArea()
                    .allowsHitTesting(false)
            )
            .offset(x: screen * (role == .current
                ? swipe.offset
                : revealing ? -direction * swipe.width * 0.3 * remaining : 0))
            .opacity(role == .backdrop && !swipe.isActive ? 0 : 1)
            .allowsHitTesting(role != .backdrop)
            .accessibilityHidden(role == .backdrop)
    }
}
