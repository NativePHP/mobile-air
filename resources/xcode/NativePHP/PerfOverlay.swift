import SwiftUI

/// Always-on dev overlay showing live FPS / p99 frame time / jank count.
/// Pinned to the top-trailing corner of the app. Driven by
/// `FrameTracker.shared` — pulls fresh stats ~4Hz.
///
/// Color thresholds match React Native's perf monitor convention:
///   ≥55 fps green (smooth)
///   ≥30 fps yellow (degraded but interactive)
///    <30 fps red (broken)
///
/// Wrap your app's root with `.perfOverlay()` so the badge floats on
/// top of every screen. Toggle the master switch via
/// `FrameTracker.shared.enabled = false` (e.g. for screenshots).
struct PerfOverlay: View {
    @ObservedObject private var tracker = FrameTracker.shared

    var body: some View {
        if tracker.enabled {
            VStack(alignment: .trailing, spacing: 1) {
                Text(String(format: "%.0f fps", tracker.fps))
                    .font(.system(size: 13, weight: .bold, design: .monospaced))
                    .foregroundColor(fpsColor(tracker.fps))
                Text(String(format: "p99 %.1fms · jank %d", tracker.p99Ms, tracker.jankCount))
                    .font(.system(size: 9, design: .monospaced))
                    .foregroundColor(.white.opacity(0.7))
            }
            .padding(.horizontal, 8)
            .padding(.vertical, 4)
            .background(Color.black.opacity(0.72))
            .clipShape(RoundedRectangle(cornerRadius: 6))
            .padding(.top, 50)       // clear the status bar / notch
            .padding(.trailing, 8)
            .allowsHitTesting(false) // overlay is read-only; never blocks taps below
        }
    }

    private func fpsColor(_ fps: Double) -> Color {
        switch fps {
        case 55...: return .green
        case 30...: return .yellow
        default:    return .red
        }
    }
}

extension View {
    /// Pin a `PerfOverlay` to the top-trailing corner of this view.
    /// Toggle off in production via `FrameTracker.shared.enabled = false`.
    func perfOverlay() -> some View {
        self.overlay(alignment: .topTrailing) {
            PerfOverlay()
        }
    }
}
