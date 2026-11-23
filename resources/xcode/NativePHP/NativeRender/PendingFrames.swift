import Foundation

/// Frames PHP published that the shadow thread hasn't parsed yet, oldest
/// first.
///
/// Every frame is parsed, in order. Under subtree memo a frame's REUSE
/// markers point at nodes of the frame before it, so a frame skipped by the
/// reader leaves the next one splicing in nodes it never built: rows land
/// under the wrong parent, or old content stays.
///
/// Each frame holds its own copy of PHP's buffers (see
/// `NativeElementBridge.postTreeUpdate`), so it can wait here for as long as
/// it takes. When `capacity` frames are already waiting, they are dropped
/// instead, and `take()` reports it with the next frame.
final class PendingFrames<Frame> {
    private let capacity: Int
    private let lock = NSLock()
    private var frames: [Frame] = []
    private var dropped = false

    init(capacity: Int) {
        self.capacity = capacity
    }

    func add(_ frame: Frame) {
        lock.lock()
        defer { lock.unlock() }

        if frames.count >= capacity {
            frames.removeAll()
            dropped = true
        }
        frames.append(frame)
    }

    /// The oldest waiting frame, and whether frames were dropped before it.
    func take() -> (frame: Frame, droppedBefore: Bool)? {
        lock.lock()
        defer { lock.unlock() }

        guard !frames.isEmpty else { return nil }
        let droppedBefore = dropped
        dropped = false

        return (frames.removeFirst(), droppedBefore)
    }

    var isEmpty: Bool {
        lock.lock()
        defer { lock.unlock() }

        return frames.isEmpty
    }

    func clear() {
        lock.lock()
        defer { lock.unlock() }

        frames.removeAll()
        dropped = false
    }
}
