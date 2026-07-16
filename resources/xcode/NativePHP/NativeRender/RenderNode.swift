import SwiftUI

/// A SwiftUI view that recursively renders a NativeUINode tree. Plugin
/// renderers use this to draw child nodes inside their own chrome.
///
/// Delegates to `NodeView` so that every rendered child gets the same
/// layout / style / gesture modifier stack the root tree gets — in
/// particular, `NodeLayoutModifier` is what applies `paddingTop/Right/
/// Bottom/Left` from PHP's `p-4` etc. Rendering children by calling the
/// plugin renderer directly skips those modifiers, silently dropping the
/// child's padding / size constraints / click handlers.
struct RenderNode: View {
    let node: NativeUINode

    var body: some View {
        NodeView(node: node).equatable()
    }
}

/// Registry for SwiftUI-based node renderers.
/// Plugins register their SwiftUI views here so RenderNode can dispatch to them.
final class SwiftUIRendererRegistry {
    static let shared = SwiftUIRendererRegistry()

    private var renderers: [String: (NativeUINode) -> AnyView] = [:]
    private let lock = NSLock()

    private init() {}

    func register(_ type: String, _ renderer: @escaping (NativeUINode) -> AnyView) {
        lock.lock()
        defer { lock.unlock() }
        renderers[type] = renderer
    }

    func get(_ type: String) -> ((NativeUINode) -> AnyView)? {
        lock.lock()
        defer { lock.unlock() }
        return renderers[type]
    }
}
