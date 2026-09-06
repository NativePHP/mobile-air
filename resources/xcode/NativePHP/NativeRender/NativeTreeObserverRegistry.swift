import Synchronization
import os

/// Opt-in registry for accepted native tree publications.
final class NativeTreeObserverRegistry {
    static let shared = NativeTreeObserverRegistry()

    struct Publication {
        /// Process-local ID used to deduplicate replay and live delivery.
        let id: UInt64
        let tree: NativeUITree
    }

    struct Subscription: Hashable { fileprivate let id: Int }

    private let lock = OSAllocatedUnfairLock()
    private var sequence = 0
    private var observers: [Int: (Publication) -> Void] = [:]
    private let hasObservers = Atomic<Bool>(false)

    // Retained for late-subscriber replay until replaced or process exit.
    private let latestLock = OSAllocatedUnfairLock()
    private var latestPublication: Publication?

    private init() {}

    func register(_ observer: @escaping (Publication) -> Void) -> Subscription {
        lock.lock()
        sequence &+= 1
        let subscription = Subscription(id: sequence)
        observers[sequence] = observer
        hasObservers.store(true, ordering: .releasing)
        lock.unlock()
        latestLock.lock()
        let replay = latestPublication
        latestLock.unlock()
        if let replay { observer(replay) }
        return subscription
    }

    func unregister(_ subscription: Subscription) {
        lock.lock(); defer { lock.unlock() }
        observers.removeValue(forKey: subscription.id)
        hasObservers.store(!observers.isEmpty, ordering: .releasing)
    }

    func publish(_ publication: Publication) {
        latestLock.lock()
        latestPublication = publication
        latestLock.unlock()
        guard hasObservers.load(ordering: .acquiring) else { return }
        lock.lock()
        let current = Array(observers.values)
        lock.unlock()
        for observer in current { observer(publication) }
    }
}
