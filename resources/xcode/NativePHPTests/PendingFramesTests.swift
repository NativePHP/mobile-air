import Testing
@testable import NativePHP

struct PendingFramesTests {

    struct Frame: Equatable {
        let n: Int
        var detached = false
    }

    func drain(_ frames: PendingFrames<Frame>) -> [(frame: Frame, droppedBefore: Bool)] {
        var taken: [(frame: Frame, droppedBefore: Bool)] = []
        while let next = frames.take() { taken.append(next) }
        return taken
    }

    @Test func everyFrameIsTakenInTheOrderItWasPublished() {
        let frames = PendingFrames<Frame>(capacity: 8) { Frame(n: $0.n, detached: true) }

        frames.add(Frame(n: 1))
        frames.add(Frame(n: 2))
        frames.add(Frame(n: 3))

        #expect(drain(frames).map(\.frame.n) == [1, 2, 3])
        #expect(frames.isEmpty)
    }

    /// The publish after next writes over frame 1's buffer, frame 2's is
    /// still the producer's to keep.
    @Test func aQueuedFrameIsDetachedWhenTheNextOneArrives() {
        let frames = PendingFrames<Frame>(capacity: 8) { Frame(n: $0.n, detached: true) }

        frames.add(Frame(n: 1))
        frames.add(Frame(n: 2))

        #expect(drain(frames).map(\.frame.detached) == [true, false])
    }

    @Test func framesOverCapacityAreDroppedAndReportedWithTheNextOne() {
        let frames = PendingFrames<Frame>(capacity: 2) { $0 }

        frames.add(Frame(n: 1))
        frames.add(Frame(n: 2))
        frames.add(Frame(n: 3))
        frames.add(Frame(n: 4))

        let taken = drain(frames)
        #expect(taken.map(\.frame.n) == [3, 4])
        #expect(taken.map(\.droppedBefore) == [true, false])
    }
}
