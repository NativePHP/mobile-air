import SwiftUI

/// The latest tree PHP published for one URI on a navigation stack, for the
/// view of that stack level to observe.
///
/// A pushed level is drawn by its `NavigationStack`'s `navigationDestination`
/// closure. When PHP republishes the top screen while its push is still
/// animating (an `AsyncTask` finishing a few milliseconds after `mount()`),
/// the new tree reaches the coordinator's cache and the renderer's `body`
/// runs again, but the pushed destination keeps the tree it was created with.
/// Nothing redraws it afterwards, because PHP doesn't republish an unchanged
/// frame. Each pushed level's view observes its own `StackLevel`, so the
/// coordinator's publish redraws that level directly.
final class StackLevel: ObservableObject {
    @Published fileprivate(set) var node: NativeUINode?
}

/// The `StackLevel`s of one navigation stack, keyed by URI.
///
/// `level(for:)` may be called from a view's `body`: it only creates the
/// object. `publish(_:for:)` writes a `@Published` property, so call it
/// outside view updates (the coordinators call it from `receive()`, which
/// the renderers defer to the next main-queue turn).
final class StackLevels {
    private var levels: [String: StackLevel] = [:]

    func level(for uri: String) -> StackLevel {
        if let level = levels[uri] {
            return level
        }
        let level = StackLevel()
        levels[uri] = level
        return level
    }

    func publish(_ node: NativeUINode, for uri: String) {
        guard let level = levels[uri], level.node !== node else { return }
        level.node = node
    }

    /// Drop the levels of URIs no longer on the stack.
    func retain(_ live: Set<String>) {
        for uri in levels.keys where !live.contains(uri) {
            levels.removeValue(forKey: uri)
        }
    }

    func removeAll() {
        levels = [:]
    }
}

/// One pushed stack level: redraws its content whenever its `StackLevel`
/// publishes. The content reads the coordinator's cache, which already holds
/// the tree by the time the level publishes.
struct StackLevelView<Content: View>: View {
    @ObservedObject var level: StackLevel
    @ViewBuilder let content: () -> Content

    var body: some View {
        content()
    }
}
