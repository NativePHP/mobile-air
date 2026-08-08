import Foundation

/// Opt-in observation seam for the decoded native tree.
final class NativeTreeObserverRegistry {
    static let shared = NativeTreeObserverRegistry()

    struct Publication {
        let id: UInt64
        let tree: NativeUITree
    }

    struct Subscription: Hashable { fileprivate let id: Int }

    private let lock = NSLock()
    private var sequence = 0
    private var observers: [Int: (Publication) -> Void] = [:]
    private var latestPublication: Publication?

    private init() {}

    func observe(_ observer: @escaping (Publication) -> Void) -> Subscription {
        lock.lock()
        sequence &+= 1
        let subscription = Subscription(id: sequence)
        observers[sequence] = observer
        let replay = latestPublication
        lock.unlock()
        if let replay { observer(replay) }
        return subscription
    }

    func unsubscribe(_ subscription: Subscription) {
        lock.lock(); defer { lock.unlock() }
        observers.removeValue(forKey: subscription.id)
    }

    func publish(_ publication: Publication) {
        lock.lock()
        latestPublication = publication
        guard !observers.isEmpty else { lock.unlock(); return }
        let current = Array(observers.values)
        lock.unlock()
        for observer in current { observer(publication) }
    }
}
