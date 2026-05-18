import Foundation
import QuartzCore

/// Records end-to-end interaction latency for the iOS pipeline. Mirror
/// of the Android `PerformanceTracker` interaction-tracking half — we
/// keep the data model and JSON shape compatible so PHP-side analysis
/// in `BenchmarkComponent` can use one path for both platforms.
///
/// The four checkpoints we record per interaction:
///
///   T0  Event sent (sendPressEvent / sendTextChangeEvent / etc.)
///   T1  Tree update arrived from PHP (receiveTreeUpdate)
///   T2  Tree posted to main thread (DispatchQueue.main.async block runs)
///   T3  Next CADisplayLink tick after T2 — the frame is drawn
///
/// Derived metrics:
///   event_delivery_ms = T1 - T0    (PHP processing + wire)
///   compose_post_ms   = T2 - T1    (main-thread dispatch)
///   frame_paint_ms    = T3 - T2    (SwiftUI render to screen)
///   total_ms          = T3 - T0
///
/// Capture-window mode collects frame intervals between Start/Stop so
/// scenarios like "scroll a 10k list" report ONLY frames during the
/// scripted scroll, not bookend frames from menu navigation.
final class InteractionTracker {
    static let shared = InteractionTracker()

    var enabled: Bool = false
    var logRealtime: Bool = false

    private let lock = NSLock()

    /// callbackId → T0 timestamp. Cleared once the interaction completes.
    private var interactionStarts: [Int: CFTimeInterval] = [:]
    private var interactionTypes: [Int: String] = [:]

    /// Most recent tree update / post timestamps. Single-slot — if rapid
    /// interactions overlap we still measure totals correctly; only the
    /// pipeline split (event_delivery vs compose_post) becomes
    /// approximate. Same approach as Android.
    private var lastTreeUpdateTime: CFTimeInterval = 0
    private var lastTreePostTime: CFTimeInterval = 0
    private var pendingFrameDrawn: Bool = false

    /// Completed interaction measurements.
    private(set) var interactions: [InteractionMeasurement] = []

    /// Capture-window frame intervals (ms). Populated by FrameTracker
    /// when `captureWindowActive == true`.
    private(set) var captureWindowFrames: [Double] = []
    private(set) var captureWindowActive: Bool = false
    private var captureWindowStartTime: CFTimeInterval = 0

    private init() {}

    // MARK: - Lifecycle

    func setEnabled(_ value: Bool, logRealtime: Bool = false) {
        lock.lock(); defer { lock.unlock() }
        self.enabled = value
        self.logRealtime = logRealtime
        if !value {
            captureWindowActive = false
        }
    }

    func reset() {
        lock.lock(); defer { lock.unlock() }
        interactionStarts.removeAll()
        interactionTypes.removeAll()
        interactions.removeAll()
        captureWindowFrames.removeAll()
        lastTreeUpdateTime = 0
        lastTreePostTime = 0
        pendingFrameDrawn = false
    }

    // MARK: - Recording

    func onInteractionStart(callbackId: Int, type: String = "press") {
        guard enabled else { return }
        lock.lock(); defer { lock.unlock() }
        interactionStarts[callbackId] = CACurrentMediaTime()
        interactionTypes[callbackId] = type
    }

    func onTreeUpdateReceived() {
        guard enabled else { return }
        lock.lock(); defer { lock.unlock() }
        lastTreeUpdateTime = CACurrentMediaTime()
    }

    func onTreePostedToMain() {
        guard enabled else { return }
        lock.lock(); defer { lock.unlock() }
        lastTreePostTime = CACurrentMediaTime()
        pendingFrameDrawn = true
    }

    /// Called from FrameTracker's CADisplayLink callback. Closes out any
    /// in-flight interactions whose T0 preceded the most recent tree
    /// update.
    func onFrameDrawn(timestamp: CFTimeInterval) {
        guard enabled else { return }
        lock.lock(); defer { lock.unlock() }
        guard pendingFrameDrawn else { return }
        pendingFrameDrawn = false

        let tUpdate = lastTreeUpdateTime
        let tPost = lastTreePostTime
        guard tUpdate > 0, tPost > 0 else { return }
        let tDraw = timestamp

        // Snapshot to avoid mutating during enumeration.
        let candidates = interactionStarts.filter { $0.value < tUpdate }
        for (cbId, tStart) in candidates {
            interactionStarts.removeValue(forKey: cbId)
            let type = interactionTypes.removeValue(forKey: cbId) ?? "press"

            let eventDelivery = max(0, (tUpdate - tStart) * 1000)
            let composePost = max(0, (tPost - tUpdate) * 1000)
            let framePaint = max(0, (tDraw - tPost) * 1000)
            let total = (tDraw - tStart) * 1000

            interactions.append(InteractionMeasurement(
                callbackId: cbId,
                type: type,
                timestampMs: Date().timeIntervalSince1970 * 1000,
                eventDeliveryMs: eventDelivery,
                composePostMs: composePost,
                framePaintMs: framePaint,
                totalMs: total
            ))

            if logRealtime {
                print(String(format: "INTERACTION cb=%d type=%@ event_delivery=%.2fms compose_post=%.2fms frame_paint=%.2fms TOTAL=%.2fms",
                             cbId, type, eventDelivery, composePost, framePaint, total))
            }
        }
    }

    // MARK: - Capture window

    func startCaptureWindow() {
        lock.lock(); defer { lock.unlock() }
        captureWindowFrames.removeAll()
        captureWindowActive = true
        captureWindowStartTime = CACurrentMediaTime()
    }

    func stopCaptureWindow() {
        lock.lock(); defer { lock.unlock() }
        captureWindowActive = false
    }

    /// Called from FrameTracker per frame; appends if capture-window mode.
    func recordFrameInterval(_ ms: Double) {
        guard captureWindowActive else { return }
        lock.lock(); defer { lock.unlock() }
        captureWindowFrames.append(ms)
    }

    /// Snapshot for export — copies under lock so the consumer can
    /// serialize without holding the lock.
    func snapshot() -> InteractionSnapshot {
        lock.lock(); defer { lock.unlock() }
        return InteractionSnapshot(
            interactions: interactions,
            captureWindowFrames: captureWindowFrames
        )
    }
}

// MARK: - Data types

struct InteractionMeasurement {
    let callbackId: Int
    let type: String
    let timestampMs: Double
    let eventDeliveryMs: Double
    let composePostMs: Double
    let framePaintMs: Double
    let totalMs: Double
}

struct InteractionSnapshot {
    let interactions: [InteractionMeasurement]
    let captureWindowFrames: [Double]
}
