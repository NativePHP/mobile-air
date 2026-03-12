import UIKit
import SwiftUI

/// Protocol for leaf node renderers.
/// Each type (text, button, image, etc.) implements create + update.
protocol NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView
    func updateView(_ view: UIView, node: NativeUINode)
}

/// Thread-safe registry mapping type strings to UIView renderers.
final class NativeRendererRegistry {
    static let shared = NativeRendererRegistry()

    private var renderers: [String: NativeViewRenderer] = [:]
    private let lock = NSLock()

    private init() {}

    func register(_ type: String, renderer: NativeViewRenderer) {
        lock.lock()
        defer { lock.unlock() }
        renderers[type] = renderer
    }

    /// Legacy closure-based registration for plugins still using SwiftUI.
    /// Wraps the SwiftUI view in a UIHostingController.
    func register(_ type: String, _ swiftUIRenderer: @escaping (NativeUINode) -> AnyView) {
        lock.lock()
        defer { lock.unlock() }
        renderers[type] = SwiftUIViewRenderer(render: swiftUIRenderer)
    }

    func get(_ type: String) -> NativeViewRenderer? {
        lock.lock()
        defer { lock.unlock() }
        return renderers[type]
    }

    /// Register all built-in primitive renderers.
    func registerBuiltins() {
        register("text", renderer: TextViewRenderer())
        register("image", renderer: ImageViewRenderer())
        register("button", renderer: ButtonViewRenderer())
        register("text_input", renderer: TextInputViewRenderer())
        register("toggle", renderer: ToggleViewRenderer())
        register("icon", renderer: IconViewRenderer())
        register("activity_indicator", renderer: ActivityIndicatorViewRenderer())
        register("spacer", renderer: SpacerViewRenderer())
        register("divider", renderer: DividerViewRenderer())
        register("rect", renderer: RectViewRenderer())
        register("circle", renderer: CircleViewRenderer())
        register("line", renderer: LineViewRenderer())

        // Navigation chrome
        register("top_bar", renderer: TopBarViewRenderer())
        register("top_bar_action", renderer: EmptyViewRenderer())
        register("bottom_nav", renderer: BottomNavViewRenderer())
        register("bottom_nav_item", renderer: EmptyViewRenderer())
        register("side_nav", renderer: SideNavViewRenderer())
        register("side_nav_item", renderer: EmptyViewRenderer())
        register("side_nav_group", renderer: EmptyViewRenderer())
        register("side_nav_header", renderer: EmptyViewRenderer())
    }
}

/// Renderer that produces an empty (zero-size) view.
struct EmptyViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let v = UIView()
        v.isHidden = true
        return v
    }
    func updateView(_ view: UIView, node: NativeUINode) {}
}

/// Bridges legacy SwiftUI renderers (from plugins) into the UIView system.
/// Creates a UIHostingController to embed the SwiftUI view.
private struct SwiftUIViewRenderer: NativeViewRenderer {
    let render: (NativeUINode) -> AnyView

    func createView(node: NativeUINode) -> UIView {
        let hostVC = UIHostingController(rootView: render(node))
        hostVC.view.backgroundColor = .clear
        return hostVC.view
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        // Find the hosting controller and update its root view
        // The view is the UIHostingController's view — we can't easily update it
        // without the controller reference. For now, recreate is fine for plugins.
    }
}
