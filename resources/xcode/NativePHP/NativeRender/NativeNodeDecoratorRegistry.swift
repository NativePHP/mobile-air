import SwiftUI

/// Ordered, opt-in view decorators supplied by native plugins.
final class NativeNodeDecoratorRegistry {
    static let shared = NativeNodeDecoratorRegistry()
    typealias Decorator = (_ node: NativeUINode, _ content: AnyView) -> AnyView

    private var decorators: [String: Decorator] = [:]
    private var order: [String] = []
    private var pipeline: Decorator?
    private let lock = NSLock()

    var currentPipeline: Decorator? {
        lock.lock(); defer { lock.unlock() }
        return pipeline
    }

    private init() {}

    func register(_ name: String, decorator: @escaping Decorator) {
        lock.lock(); defer { lock.unlock() }
        if decorators[name] == nil { order.append(name) }
        decorators[name] = decorator
        rebuildPipelineLocked()
    }

    func unregister(_ name: String) {
        lock.lock(); defer { lock.unlock() }
        decorators.removeValue(forKey: name)
        order.removeAll { $0 == name }
        rebuildPipelineLocked()
    }

    private func rebuildPipelineLocked() {
        let snapshot = order.compactMap { decorators[$0] }
        guard !snapshot.isEmpty else {
            pipeline = nil
            return
        }

        pipeline = { node, content in
            snapshot.reduce(content) { view, decorator in decorator(node, view) }
        }
    }
}
