import SwiftUI

/// A SwiftUI view that recursively renders a NativeUINode tree.
/// Plugin renderers (e.g. ComposeUI) use this to render child nodes.
/// Dispatches to SwiftUI renderers registered via SwiftUIRendererRegistry,
/// falling back to a generic VStack container for unknown types.
struct RenderNode: View {
    let node: NativeUINode

    var body: some View {
        if let renderer = SwiftUIRendererRegistry.shared.get(node.type) {
            renderer(node)
        } else {
            // Generic container fallback: render children vertically
            VStack(spacing: 0) {
                ForEach(node.children) { child in
                    RenderNode(node: child)
                }
            }
        }
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
