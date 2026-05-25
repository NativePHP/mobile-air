import SwiftUI
import QuartzCore

/// UI-thread frame instrumentation via `CADisplayLink`. Records the
/// interval between display refreshes and derives FPS / p99 / jank
/// statistics over a rolling window. Parallels Android's
/// `PerformanceTracker`.
///
/// Why this exists: SwiftUI doesn't expose a clean per-frame FPS hook,
/// and the on-device "render server" model means main-thread CPU
/// activity isn't the whole picture either. CADisplayLink is the
/// canonical iOS API for synchronizing with the display refresh — its
/// `timestamp` jitter IS the frame-drop signal.
///
/// Stats are published at ~4Hz (every 250ms) to drive the
/// `PerfOverlay` view. Recording every frame in the publisher would
/// itself cost render budget — the overlay only needs a snapshot.
///
/// Disable entirely by setting `FrameTracker.shared.enabled = false`.
final class FrameTracker: ObservableObject {
    static let shared = FrameTracker()

    /// Master toggle. When false, the display link is paused and no
    /// stats are computed. Default: off — PHP-side config opts in via
    /// `Perf.SetFpsOverlayEnabled` at boot (config key
    /// `nativephp.fps_overlay`, env `NATIVEPHP_FPS_OVERLAY`).
    @Published var enabled: Bool = false {
        didSet { applyEnabledState() }
    }

    // Published stats — drive the overlay.
    @Published private(set) var fps: Double = 0
    @Published private(set) var p99Ms: Double = 0
    @Published private(set) var jankCount: Int = 0
    @Published private(set) var frameCount: Int = 0

    /// Frame interval in milliseconds. Ring buffer; size sets the
    /// rolling window for FPS / p99 / jank. 120 entries ≈ 2 seconds
    /// at 60Hz, 1 second at 120Hz — long enough to smooth instantaneous
    /// noise but short enough to reflect current state.
    private let bufferSize = 120
    private var intervals: [Double] = []
    private var bufferHead = 0

    /// Threshold for "jank" in milliseconds. 16.67ms is the 60Hz frame
    /// budget; anything slower drops a frame at 60Hz. ProMotion (120Hz)
    /// uses 8.33ms internally for its own jank count, but the public
    /// stat sticks to 16.67ms so the number means the same thing across
    /// devices ("frames slower than 60Hz refresh").
    private let jankThresholdMs: Double = 16.67

    private var displayLink: CADisplayLink?
    private var lastTimestamp: CFTimeInterval = 0
    private var publishTimer: Timer?

    /// Protects `intervals` and `bufferHead` against concurrent access
    /// between the CADisplayLink callback (main thread) and external
    /// readers like `Perf.Export` (PHP/bridge thread). Without this,
    /// `intervalsSnapshot()` racing with `onFrame()` is undefined
    /// behavior and crashes the app a few seconds into a benchmark.
    private let intervalsLock = NSLock()

    private init() {
        applyEnabledState()
    }

    // MARK: - Lifecycle

    private func applyEnabledState() {
        if enabled {
            start()
        } else {
            stop()
        }
    }

    private func start() {
        guard displayLink == nil else { return }
        let link = CADisplayLink(target: self, selector: #selector(onFrame))
        link.add(to: .main, forMode: .common)
        displayLink = link
        lastTimestamp = 0

        // Publish stats at 4Hz — keeps the overlay readable without
        // re-rendering it every frame (which would itself drop frames).
        publishTimer = Timer.scheduledTimer(withTimeInterval: 0.25, repeats: true) { [weak self] _ in
            self?.publish()
        }
    }

    private func stop() {
        displayLink?.invalidate()
        displayLink = nil
        publishTimer?.invalidate()
        publishTimer = nil
        intervalsLock.lock()
        intervals.removeAll()
        bufferHead = 0
        intervalsLock.unlock()
    }

    // MARK: - Frame callback

    @objc private func onFrame(_ link: CADisplayLink) {
        if lastTimestamp == 0 {
            lastTimestamp = link.timestamp
            // Even the very first tick after a publish should close out
            // in-flight interactions (otherwise their T3 waits a full
            // extra frame).
            InteractionTracker.shared.onFrameDrawn(timestamp: link.timestamp)
            return
        }
        let deltaSeconds = link.timestamp - lastTimestamp
        lastTimestamp = link.timestamp
        let deltaMs = deltaSeconds * 1000.0

        // Ring buffer write — locked because `Perf.Export` may read
        // concurrently from the bridge thread.
        intervalsLock.lock()
        if intervals.count < bufferSize {
            intervals.append(deltaMs)
        } else {
            intervals[bufferHead] = deltaMs
            bufferHead = (bufferHead + 1) % bufferSize
        }
        intervalsLock.unlock()

        // Fan out to the interaction tracker — completes any in-flight
        // interaction whose post-to-main timestamp predates this tick,
        // and captures the interval if a benchmark capture window is
        // active.
        InteractionTracker.shared.onFrameDrawn(timestamp: link.timestamp)
        InteractionTracker.shared.recordFrameInterval(deltaMs)
    }

    /// Read-only access to the current ring buffer of frame intervals
    /// (ms). Used by `Perf.Export` to ship raw frame data alongside the
    /// summary stats. Locked because the caller (PHP bridge thread) and
    /// the writer (CADisplayLink callback on main) race otherwise — a
    /// Swift Array concurrent read/write is undefined behavior and the
    /// crash we were seeing at the end of every benchmark.
    func intervalsSnapshot() -> [Double] {
        intervalsLock.lock()
        let copy = intervals
        intervalsLock.unlock()
        return copy
    }

    // MARK: - Stats publishing

    private func publish() {
        // Snapshot under lock so concurrent CADisplayLink ticks don't
        // mutate while we sort / iterate.
        intervalsLock.lock()
        let copy = intervals
        intervalsLock.unlock()

        guard !copy.isEmpty else { return }

        let sorted = copy.sorted()
        let avg = copy.reduce(0, +) / Double(copy.count)
        let p99Index = max(0, Int(Double(sorted.count) * 0.99) - 1)
        let p99 = sorted[p99Index]
        let jank = copy.filter { $0 > jankThresholdMs }.count

        // Publish on main thread (CADisplayLink target is main; timer
        // is also main thread — but @Published triggers SwiftUI
        // updates which must run on main).
        fps = avg > 0 ? 1000.0 / avg : 0
        p99Ms = p99
        jankCount = jank
        frameCount = copy.count
    }
}
