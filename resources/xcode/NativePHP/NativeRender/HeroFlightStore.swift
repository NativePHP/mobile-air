import SwiftUI

/// Owns the shared-element ("hero") flights for `view_transition` navigations.
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
        /// The element's `ref` — unique per screen, so it doubles as identity.
        let id: String
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

    /// Refs whose real elements must stay hidden — set at the swap so the
    /// incoming hero never flashes at its destination before taking off.
    @Published private(set) var hidden: Set<String> = []

    // MARK: - Reporting

    /// Record where a ref'd element currently sits, in global coordinates.
    ///
    /// Outgoing screens are ignored: their frames were already captured at the
    /// swap, and letting a screen that is on its way out keep reporting would
    /// overwrite the origin we are about to fly from.
    func report(ref: String, rect: CGRect, isOutgoing: Bool) {
        guard !isOutgoing, !rect.isEmpty else { return }

        liveFrames[ref] = rect

        // The incoming screen has just told us where this ref landed, which is
        // the last thing a flight was waiting for.
        guard let from = pendingFrom.removeValue(forKey: ref),
              let node = pendingNodes.removeValue(forKey: ref) else { return }

        launch(ref: ref, node: node, from: from, to: rect)
    }

    // MARK: - Swap lifecycle

    /// Begin a `view_transition`. Called synchronously from the publish path,
    /// before the incoming tree is handed to SwiftUI.
    func beginSwap(incomingNodes: [String: NativeUINode]) {
        let matched = Set(incomingNodes.keys).intersection(liveFrames.keys)

        pendingFrom = liveFrames.filter { matched.contains($0.key) }
        pendingNodes = incomingNodes.filter { matched.contains($0.key) }
        liveFrames.removeAll()

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
        swapToken += 1
    }

    // MARK: - Flights

    private func launch(ref: String, node: NativeUINode, from: CGRect, to: CGRect) {
        let mode = node.props.getString("morph", default: "frame")
        let (start, end) = Self.rects(mode: mode, from: from, to: to)

        // Nothing actually moves — skip the overlay entirely rather than
        // paying for a copy that renders one frame and lands.
        guard !start.equalTo(end) else {
            land(ref)
            return
        }

        let duration = Self.duration(for: node)
        flights.append(Flight(
            id: ref,
            node: node,
            from: start,
            to: end,
            animation: Self.animation(for: node, duration: duration),
            duration: duration
        ))

        DispatchQueue.main.asyncAfter(deadline: .now() + duration) { [weak self] in
            self?.land(ref)
        }
    }

    /// Drop a flight and reveal the real element again.
    private func land(_ ref: String) {
        flights.removeAll { $0.id == ref }
        hidden.remove(ref)
        pendingFrom.removeValue(forKey: ref)
        pendingNodes.removeValue(forKey: ref)
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
