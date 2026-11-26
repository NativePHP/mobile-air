import SwiftUI
import UIKit

/// Owns the shared-element ("hero") flights for `morph` navigations.
///
/// ## Why a flight store rather than `matchedGeometryEffect`
///
/// `matchedGeometryEffect` can only slave one view's frame to another's, and
/// both views stay inside their own screen layer. During a swap the incoming
/// screen is cross-fading in over the outgoing one, so a hero travelling
/// inside either layer is subject to that layer's opacity — measured on device
/// as "animates most of the way, then fades", because the tail of the journey
/// is progressively occluded.
///
/// Compose has no such problem: `SharedTransitionLayout` lifts the shared
/// element into its own layer ABOVE both panes. This store gives SwiftUI the
/// same model by hand — the FLIP approach (First, Last, Invert, Play):
///
///   1. **First** — every element carrying a `ref` continuously reports its
///      on-screen frame here.
///   2. **Last** — at the swap we snapshot those frames, then wait for the
///      incoming screen to report where the same refs landed.
///   3. **Play** — a *copy* of the element is rendered in
///      `HeroFlightOverlay`, above both screens, and animated from the old
///      frame to the new one. The real elements on both screens stay hidden
///      for the duration, so nothing double-draws and nothing is occluded.
///
/// Only refs present on BOTH screens fly. A grid has six tagged tiles and one
/// partner; the other five never had anywhere to go.
@MainActor
final class HeroFlightStore: ObservableObject {
    static let shared = HeroFlightStore()

    private init() {}

    /// One element in transit.
    struct Flight: Identifiable, Equatable {
        /// Unique per launch, NOT the ref. A pop that lands a ref and launches
        /// it again in the same update would otherwise reuse the old view and
        /// its `arrived` state, and the copy would appear already landed.
        let id: String
        /// The element's `ref` — unique per screen.
        let ref: String
        let node: NativeUINode
        let from: CGRect
        let to: CGRect
        let animation: Animation
        let duration: Double

        static func == (a: Flight, b: Flight) -> Bool {
            a.id == b.id && a.from == b.from && a.to == b.to
        }
    }

    /// Live frames of ref'd elements on the screen currently on top.
    private var liveFrames: [String: CGRect] = [:]

    /// Frames captured at the instant of a swap — each flight's origin.
    private var pendingFrom: [String: CGRect] = [:]

    /// Incoming-tree nodes keyed by ref, so the overlay can render a copy.
    private var pendingNodes: [String: NativeUINode] = [:]

    /// Bumped per swap so a late safety sweep can tell whether it still owns
    /// the navigation it was scheduled for.
    private var swapToken = 0

    @Published private(set) var flights: [Flight] = []

    private var flightSerial = 0

    /// Refs whose real elements must stay hidden — set at the swap so the
    /// incoming hero never flashes at its destination before taking off.
    @Published private(set) var hidden: Set<String> = []

    // MARK: - Reporting

    /// Record where a ref'd element currently sits, in global coordinates.
    ///
    /// Outgoing screens are ignored: their frames were already captured at the
    /// swap, and letting a screen that is on its way out keep reporting would
    /// overwrite the origin we are about to fly from.
    func report(ref: String, rect: CGRect, isOutgoing: Bool, screen: String? = nil) {
        guard !isOutgoing, !rect.isEmpty else { return }

        if let screen {
            reportChrome(ref: ref, rect: rect, screen: screen)
        }

        liveFrames[ref] = rect

        // The incoming screen has just told us where this ref landed, which is
        // the last thing a flight was waiting for.
        guard pendingFrom[ref] != nil else { return }

        // A swap INTO native chrome whose current screen is a pushed level
        // (a tab showing `/docs/mobile/4/the-basics`) mounts that level
        // through a NavigationStack push, and its first report is the same
        // bogus off-screen pass described in `reportChrome`: measured as the
        // copy flying to (-181, -417) and off the top of the screen. Same
        // fix: on screen launches now, off screen waits a beat for the
        // corrected frame. A swap between plain screens never reports off
        // screen first, so it launches exactly as before.
        if !Self.isOnScreen(rect) {
            guard !swapSettling.contains(ref) else { return }
            swapSettling.insert(ref)
            let token = swapToken
            DispatchQueue.main.asyncAfter(deadline: .now() + Self.chromeSettleDelay) { [weak self] in
                guard let self, self.swapToken == token else { return }
                self.swapSettling.remove(ref)
                guard let to = self.liveFrames[ref],
                      let from = self.pendingFrom.removeValue(forKey: ref),
                      let node = self.pendingNodes.removeValue(forKey: ref) else { return }
                self.launch(ref: ref, node: node, from: from, to: to)
            }
            return
        }

        swapSettling.remove(ref)
        guard let from = pendingFrom.removeValue(forKey: ref),
              let node = pendingNodes.removeValue(forKey: ref) else { return }

        launch(ref: ref, node: node, from: from, to: rect)
    }

    /// Swap refs whose first destination report was off screen, waiting out
    /// `chromeSettleDelay` for the corrected one.
    private var swapSettling: Set<String> = []

    // MARK: - Swap lifecycle

    /// Begin a `morph` swap. Called synchronously from the publish path,
    /// before the incoming tree is handed to SwiftUI.
    func beginSwap(incomingNodes: [String: NativeUINode]) {
        let matched = Set(incomingNodes.keys).intersection(liveFrames.keys)

        pendingFrom = liveFrames.filter { matched.contains($0.key) }
        pendingNodes = incomingNodes.filter { matched.contains($0.key) }
        liveFrames.removeAll()

        // A full-screen swap replaces whatever native chrome was showing, so
        // its per-level bookkeeping goes with it.
        resetChrome()
        swapSettling.removeAll()

        // Hide the pair NOW. The incoming copy mounts hidden and only becomes
        // visible when its flight lands; without this it paints once at its
        // destination before the overlay copy has taken off.
        hidden = matched

        swapToken += 1
        let token = swapToken

        // Safety net: a ref that never reports back (destination removed from
        // the tree, screen swapped again mid-flight) would otherwise stay
        // hidden forever. Unstick anything still pending.
        DispatchQueue.main.asyncAfter(deadline: .now() + 1.2) { [weak self] in
            guard let self, self.swapToken == token else { return }
            for ref in self.pendingFrom.keys { self.land(ref) }
        }
    }

    /// Abandon any in-flight state — a non-hero navigation, or teardown.
    func cancelAll() {
        pendingFrom.removeAll()
        pendingNodes.removeAll()
        liveFrames.removeAll()
        flights.removeAll()
        hidden.removeAll()
        takeOffTimed.removeAll()
        swapSettling.removeAll()
        swapToken += 1
        resetChrome()
    }

    // MARK: - Native chrome (push / pop inside a NavigationStack)
    //
    // Inside native chrome there is no screen swap. Each push or pop is a
    // real NavigationStack transition: it keeps the back chevron, swipe-back
    // and PHP's stack depth. The flights still run in the same overlay, but
    // the bookkeeping is different from a swap in three ways:
    //
    //   - The level you pushed FROM stays mounted under the one you pushed
    //     TO, and both carry the same ref. A single `liveFrames` entry per ref
    //     can't hold both ends, so frames are kept per level
    //     (`screenFrames`), keyed by the `heroScreen` environment value the
    //     renderers set on each level.
    //   - Only the top level of a stack may update its frames. UIKit takes a
    //     covered level out of the window, and what SwiftUI reports for it
    //     then is not where it was drawn. Keeping the last frames it had
    //     while it was on top is exactly what a pop needs to fly back to.
    //   - A pop is a different navigation from the push it undoes: PHP
    //     stages `slide_from_left` for it, and a chevron tap or swipe-back
    //     never reaches PHP before it starts. So the store remembers which
    //     levels were entered by a morph (`morphEntries`) and flies the pop
    //     back on its own, whatever transition is staged.

    /// Frames per stack level, keyed by `heroScreen` then by ref.
    private var screenFrames: [String: [String: CGRect]] = [:]

    /// The level each stack currently shows on top. Reports from any other
    /// level of that stack are ignored (see above).
    private var topScreen: [String: String] = [:]

    /// Levels entered by a morph push, mapped to the level they were pushed
    /// from. A pop from the key back to the value flies the shared refs back.
    private var morphEntries: [String: String] = [:]

    /// The refs the current chrome navigation hid or is flying. Chrome
    /// bookkeeping only ever lands THESE. A full-screen morph into a tabs or
    /// stack screen is still in flight when that screen's coordinator first
    /// sets its path (`receive()` runs a main-queue turn after the publish),
    /// and landing everything there killed the swap's copies mid-air.
    private var chromeRefs: Set<String> = []

    /// Bumped per chrome navigation. Separate from `swapToken` so a chrome
    /// navigation can't disarm the safety sweep of a swap still in flight.
    private var chromeToken = 0

    /// The navigation PHP published last inside native chrome: the URI it
    /// landed on and the transition it staged. `pendingTransition` alone is
    /// not enough, because nothing clears it: a stale `morph` would make any
    /// later path growth morph (a depth backfill, a tab re-seeding its
    /// path), and a chevron or swipe-back pop would read whatever the last
    /// navigation staged. Set by the bridge on every navigation publish and
    /// used up by the path change that lands on that URI.
    private var stagedNavigation: (uri: String, transition: String?)?

    /// The level a chrome push is waiting on, and the flights it owes.
    private var chromeTarget: String?
    private var chromePendingFrom: [String: CGRect] = [:]
    private var chromePendingNodes: [String: NativeUINode] = [:]

    /// `heroScreen` value for one level of one stack. The stack name keeps two
    /// tabs that happen to show the same URI apart.
    nonisolated static func screenId(stack: String, uri: String) -> String {
        stack + "|" + uri
    }

    private static func stack(of screen: String) -> String {
        String(screen.split(separator: "|", maxSplits: 1, omittingEmptySubsequences: false).first ?? "")
    }

    private func reportChrome(ref: String, rect: CGRect, screen: String) {
        let stack = Self.stack(of: screen)
        if let top = topScreen[stack], top != screen { return }

        screenFrames[screen, default: [:]][ref] = rect

        guard screen == chromeTarget, chromePendingFrom[ref] != nil else { return }

        // The pushed level's FIRST report is often not where it lands. UIKit
        // lays the new level out once before its push animation positions
        // it, and SwiftUI reports that pass: measured on device as
        // (-181, -417) for an icon that settled at (20, 135) one frame later.
        // That bogus pass is always off screen, so a frame on screen launches
        // at once. Anything else waits a short beat for the corrected pass
        // (a destination really can be off screen, scrolled out of view).
        // Waiting on every report instead would cost ~100ms on a cold level's
        // first push, measured as the copy sitting still while the screens
        // slid, then chasing them.
        if Self.isOnScreen(rect) {
            chromeLanding.removeValue(forKey: ref)
            if let from = chromePendingFrom.removeValue(forKey: ref),
               let node = chromePendingNodes.removeValue(forKey: ref) {
                launch(ref: ref, node: node, from: from, to: rect, timedFromTakeOff: true)
            }
            return
        }

        let alreadyScheduled = chromeLanding[ref] != nil
        chromeLanding[ref] = rect
        guard !alreadyScheduled else { return }

        let token = chromeToken
        DispatchQueue.main.asyncAfter(deadline: .now() + Self.chromeSettleDelay) { [weak self] in
            guard let self, self.chromeToken == token,
                  let to = self.chromeLanding.removeValue(forKey: ref),
                  let from = self.chromePendingFrom.removeValue(forKey: ref),
                  let node = self.chromePendingNodes.removeValue(forKey: ref) else { return }
            self.launch(ref: ref, node: node, from: from, to: to, timedFromTakeOff: true)
        }
    }

    private static func isOnScreen(_ rect: CGRect) -> Bool {
        let bounds = UIApplication.shared.connectedScenes
            .compactMap { ($0 as? UIWindowScene)?.screen.bounds }
            .first ?? .zero
        return bounds.contains(rect.center)
    }

    /// How long a pushed level's frames get to settle before its flights
    /// launch. Three frames at 60Hz: enough for the corrected layout pass,
    /// short enough that the flight still runs alongside the push.
    private static let chromeSettleDelay: Double = 0.05

    /// Latest frame each pending ref reported on the pushed level, waiting
    /// out `chromeSettleDelay`.
    private var chromeLanding: [String: CGRect] = [:]

    /// Record the navigation PHP just published. Called by the bridge on
    /// every navigation publish, before the tree reaches SwiftUI. `uri` is
    /// nil when the publish swaps the whole screen rather than moving inside
    /// native chrome, so a coordinator mounting fresh under that swap never
    /// reads its `morph` as a push of its own.
    func stageNavigation(uri: String?, transition: String?) {
        stagedNavigation = uri.map { (uri: $0, transition: transition) }
    }

    /// A NavigationStack inside native chrome changed its levels. Called by
    /// the coordinators from their `path` observer, so it runs for PHP
    /// pushes and pops AND for the chevron and swipe-back, synchronously,
    /// before SwiftUI lays out the new top level.
    ///
    /// `before` and `after` are the stack's levels bottom to top, root
    /// included. `incoming` is the cached tree of the new top level.
    func stackChanged(
        stack: String,
        before: [String],
        after: [String],
        incoming: NativeUINode?
    ) {
        guard let toUri = after.last else { return }
        let to = Self.screenId(stack: stack, uri: toUri)
        topScreen[stack] = to

        // The navigation PHP staged for THIS change, if it was PHP's. A path
        // change with no matching staged publish is the user's own chevron or
        // swipe-back.
        var staged: (uri: String, transition: String?)?
        if let pending = stagedNavigation, pending.uri == toUri {
            staged = pending
            stagedNavigation = nil
        }

        // Levels no longer on this stack can't be popped from again, and
        // their frames will never be read.
        let live = Set(after.map { Self.screenId(stack: stack, uri: $0) })
        let enteredFrom = before.last.flatMap { morphEntries[Self.screenId(stack: stack, uri: $0)] }
        for key in morphEntries.keys where Self.stack(of: key) == stack && !live.contains(key) {
            morphEntries.removeValue(forKey: key)
        }
        // Pruned on the way out: a pop flies FROM the level it is dropping.
        defer {
            for key in screenFrames.keys where Self.stack(of: key) == stack && !live.contains(key) {
                screenFrames.removeValue(forKey: key)
            }
        }

        guard let fromUri = before.last else { return }
        let from = Self.screenId(stack: stack, uri: fromUri)
        guard from != to else { return }

        if after.count > before.count {
            morphEntries.removeValue(forKey: to)
            if staged?.transition == "morph", let incoming {
                morphEntries[to] = from
                beginChromePush(from: from, to: to, incoming: incoming)
            } else {
                landChrome()
            }
            return
        }

        // Same depth with a different level is a replace(): nothing to pair.
        // A pop flies back only to the exact level its morph came from, and
        // only as the default pop. `back(transition: Fade)` asked for a fade,
        // so it gets the stock pop, as on Android. The chevron and swipe-back
        // never stage anything and count as the default.
        let popTransition = staged?.transition
        let isDefaultPop = staged == nil || popTransition == nil
            || popTransition == "slide_from_left" || popTransition == "morph"
        guard after.count < before.count, enteredFrom == to, isDefaultPop, let incoming else {
            landChrome()
            return
        }

        // An interactive swipe-back has already moved the level under the
        // user's finger by the time it lands here, and if it is cancelled
        // nothing pops at all. Flying a copy from where the element WAS would
        // jump it back across the screen, so a swipe just lets the levels
        // slide, as a stock pop does.
        if Self.isInteractivePop() {
            landChrome()
            return
        }

        beginChromePop(from: from, to: to, incoming: incoming)
    }

    private func beginChromePush(from: String, to: String, incoming: NativeUINode) {
        landChrome()

        let origin = screenFrames[from] ?? [:]
        let nodes = NativeElementBridge.collectRefNodes(incoming)
            .filter { $0.value.props.getString("morph", default: "frame") != "none" }
        let matched = Set(nodes.keys).intersection(origin.keys)
        guard !matched.isEmpty else { return }

        chromeTarget = to
        chromePendingFrom = origin.filter { matched.contains($0.key) }
        chromePendingNodes = nodes.filter { matched.contains($0.key) }
        // A pushed level is mounted fresh, so whatever an earlier visit to
        // the same URI left here is stale. Its first reports refill it.
        screenFrames[to] = nil

        // Hide the pair before the pushed level's first layout, for the same
        // reason as a swap: otherwise the destination paints once in place
        // before its copy takes off. The origin hides too, so it doesn't
        // slide away underneath its own copy. Added to, never replacing,
        // what a swap may still have hidden.
        hidden.formUnion(matched)
        chromeRefs = matched

        chromeToken += 1
        let token = chromeToken
        DispatchQueue.main.asyncAfter(deadline: .now() + 1.2) { [weak self] in
            guard let self, self.chromeToken == token else { return }
            for ref in self.chromePendingFrom.keys { self.land(ref) }
            self.chromeTarget = nil
        }
    }

    private func beginChromePop(from: String, to: String, incoming: NativeUINode) {
        landChrome()

        // Both ends are known already: the popped level is on top and has been
        // reporting, and the level under it kept the frames it last had on top.
        let origin = screenFrames[from] ?? [:]
        let destination = screenFrames[to] ?? [:]
        let nodes = NativeElementBridge.collectRefNodes(incoming)
            .filter { $0.value.props.getString("morph", default: "frame") != "none" }
        let matched = Set(nodes.keys)
            .intersection(origin.keys)
            .intersection(destination.keys)
        guard !matched.isEmpty else { return }

        hidden.formUnion(matched)
        chromeRefs = matched
        chromeToken += 1
        for ref in matched {
            guard let start = origin[ref], let end = destination[ref], let node = nodes[ref] else { continue }
            launch(ref: ref, node: node, from: start, to: end, timedFromTakeOff: true)
        }
    }

    /// Land the last chrome navigation's flights and pending pairs at once.
    /// Used before a new chrome navigation starts, so nothing from the last
    /// one is left hidden. Never touches a full-screen swap's flights (see
    /// `chromeRefs`).
    private func landChrome() {
        for ref in chromeRefs.union(chromePendingFrom.keys) { land(ref) }
        chromeRefs.removeAll()
        chromeToken += 1
        chromeTarget = nil
        chromePendingFrom.removeAll()
        chromePendingNodes.removeAll()
        chromeLanding.removeAll()
    }

    private func resetChrome() {
        chromeRefs.removeAll()
        chromeToken += 1
        stagedNavigation = nil
        screenFrames.removeAll()
        topScreen.removeAll()
        morphEntries.removeAll()
        chromeTarget = nil
        chromePendingFrom.removeAll()
        chromePendingNodes.removeAll()
        chromeLanding.removeAll()
    }

    /// Whether a navigation controller in the key window is running an
    /// interactive pop right now (the edge swipe).
    private static func isInteractivePop() -> Bool {
        let windows = UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .flatMap(\.windows)
        for window in windows {
            guard let root = window.rootViewController else { continue }
            if findInteractive(in: root) { return true }
        }
        return false
    }

    private static func findInteractive(in vc: UIViewController) -> Bool {
        if let nav = vc as? UINavigationController,
           let coordinator = nav.transitionCoordinator,
           coordinator.initiallyInteractive || coordinator.isInteractive {
            return true
        }
        for child in vc.children where findInteractive(in: child) { return true }
        if let presented = vc.presentedViewController, presented !== vc,
           findInteractive(in: presented) { return true }
        return false
    }

    // MARK: - Flights

    private func launch(
        ref: String,
        node: NativeUINode,
        from: CGRect,
        to: CGRect,
        timedFromTakeOff: Bool = false
    ) {
        let mode = node.props.getString("morph", default: "frame")
        let (start, end) = Self.rects(mode: mode, from: from, to: to)

        // Nothing actually moves — skip the overlay entirely rather than
        // paying for a copy that renders one frame and lands.
        guard !start.equalTo(end) else {
            land(ref)
            return
        }

        let duration = Self.duration(for: node)
        flightSerial += 1
        flights.append(Flight(
            id: "\(ref)#\(flightSerial)",
            ref: ref,
            node: node,
            from: start,
            to: end,
            animation: Self.animation(for: node, duration: duration),
            duration: duration
        ))

        // Lands THIS flight only. If the ref has since been landed and flown
        // again (a pop straight after its push), the newer flight owns it.
        let id = "\(ref)#\(flightSerial)"

        // A chrome push launches while the pushed level is still mounting,
        // and the copy's view can appear well after this call (137ms
        // measured on a cold level). Timed from here, the flight landed with
        // a third of its journey left and the copy vanished mid-air, so
        // chrome flights are timed from take-off (`flightTookOff`) instead.
        // This timer is then only a backstop for a copy that never appears.
        let delay = timedFromTakeOff ? duration + 0.6 : duration
        if timedFromTakeOff { takeOffTimed.insert(id) }
        DispatchQueue.main.asyncAfter(deadline: .now() + delay) { [weak self] in
            guard let self, self.flights.contains(where: { $0.id == id }) else { return }
            self.land(ref)
        }
    }

    /// Flights whose landing is timed from the moment the copy starts
    /// moving rather than from launch. See `launch`.
    private var takeOffTimed: Set<String> = []

    /// Called by the overlay when a flight's copy starts moving.
    func flightTookOff(_ flight: Flight) {
        guard takeOffTimed.remove(flight.id) != nil else { return }
        DispatchQueue.main.asyncAfter(deadline: .now() + flight.duration) { [weak self] in
            guard let self, self.flights.contains(where: { $0.id == flight.id }) else { return }
            self.land(flight.ref)
        }
    }

    /// Drop a flight and reveal the real element again.
    private func land(_ ref: String) {
        for flight in flights where flight.ref == ref { takeOffTimed.remove(flight.id) }
        chromeRefs.remove(ref)
        flights.removeAll { $0.ref == ref }
        hidden.remove(ref)
        pendingFrom.removeValue(forKey: ref)
        pendingNodes.removeValue(forKey: ref)
        chromePendingFrom.removeValue(forKey: ref)
        chromePendingNodes.removeValue(forKey: ref)
        chromeLanding.removeValue(forKey: ref)
    }

    // MARK: - Geometry

    /// `morph` decides which half of the geometry is actually shared.
    ///
    ///   frame    — travels AND resizes (the default).
    ///   position — travels at its destination size; only the move animates.
    ///   size     — resizes in place at the destination; only the size animates.
    ///
    /// Interpolating the rect ourselves means these are exact here, unlike the
    /// Compose side, whose shared-element API always animates the full bounds.
    static func rects(mode: String, from: CGRect, to: CGRect) -> (CGRect, CGRect) {
        switch mode {
        case "position":
            return (CGRect(center: from.center, size: to.size), to)
        case "size":
            return (CGRect(center: to.center, size: from.size), to)
        default:
            return (from, to)
        }
    }

    private static func duration(for node: NativeUINode) -> Double {
        let ms = Double(node.props.getFloat("morph_duration", default: 0))
        return ms > 0 ? ms / 1000.0 : 0.35
    }

    private static func animation(for node: NativeUINode, duration: Double) -> Animation {
        let easing = node.props.getString("morph_easing", default: "")
        guard !easing.isEmpty else {
            return nativeEasedAnimation("ease-in-out", durationMs: duration * 1000)
        }
        return nativeEasedAnimation(easing, durationMs: duration * 1000)
    }
}

extension CGRect {
    var center: CGPoint { CGPoint(x: midX, y: midY) }

    init(center: CGPoint, size: CGSize) {
        self.init(
            x: center.x - size.width / 2,
            y: center.y - size.height / 2,
            width: size.width,
            height: size.height
        )
    }
}
