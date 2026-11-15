import Foundation

/// Frames PHP published that the shadow thread hasn't parsed yet, oldest
/// first.
///
/// Every frame is parsed, in order. Under subtree memo a frame's REUSE
/// markers point at nodes of the frame before it, so a frame skipped by the
/// reader leaves the next one splicing in nodes it never built: rows land
/// under the wrong parent, or old content stays.
///
/// A zero-copy frame reads the producer's A/B buffer in place, and the
/// publish after next writes over it. So when a frame is queued, the one
/// queued before it is detached (copied out) while its buffer is still
/// intact. When `capacity` frames are already waiting, they are dropped
/// instead, and `take()` reports it with the next frame.
final class PendingFrames<Frame> {
    private let capacity: Int
    private let detach: (Frame) -> Frame
    private let lock = NSLock()
    private var frames: [Frame] = []
    private var dropped = false

    init(capacity: Int, detach: @escaping (Frame) -> Frame) {
        self.capacity = capacity
        self.detach = detach
    }

    func add(_ frame: Frame) {
        lock.lock()
        defer { lock.unlock() }

        if frames.count >= capacity {
            frames.removeAll()
            dropped = true
        } else if let last = frames.popLast() {
            frames.append(detach(last))
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
