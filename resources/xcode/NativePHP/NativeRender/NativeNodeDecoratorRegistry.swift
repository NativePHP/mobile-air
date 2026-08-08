import SwiftUI

/// Ordered, opt-in view decorators supplied by native plugins.
final class NativeNodeDecoratorRegistry {
    static let shared = NativeNodeDecoratorRegistry()
    typealias Decorator = (_ node: NativeUINode, _ content: AnyView) -> AnyView

    private var decorators: [String: Decorator] = [:]
    private var order: [String] = []
    private(set) var currentPipeline: Decorator?

    private init() {}

    func register(_ name: String, decorator: @escaping Decorator) {
        precondition(Thread.isMainThread, "Native node decorators must be registered on the main thread")
        if decorators[name] == nil { order.append(name) }
        decorators[name] = decorator
        rebuildPipeline()
    }

    func unregister(_ name: String) {
        precondition(Thread.isMainThread, "Native node decorators must be unregistered on the main thread")
        decorators.removeValue(forKey: name)
        order.removeAll { $0 == name }
        rebuildPipeline()
    }

    private func rebuildPipeline() {
        let snapshot = order.compactMap { decorators[$0] }
        guard !snapshot.isEmpty else {
            currentPipeline = nil
            return
        }

        currentPipeline = { node, content in
            snapshot.reduce(content) { view, decorator in decorator(node, view) }
        }
    }
}
