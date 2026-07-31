import SwiftUI

/// Registry for plugin-provided **root host wrappers** — chrome that needs to
/// wrap the *entire* rendered screen tree (side drawers, global overlays,
/// snackbar hosts, …) rather than render in place as a node.
///
/// This is the native half of the framework's chrome seam. Core stays
/// chrome-agnostic: it never names "drawer" (or any specific chrome). A plugin
/// registers a host from its init function (which runs before the first tree
/// render — see `registerPluginBridgeFunctions()`), and core folds every
/// registered host around the root in `NativeTreeRenderer`.
///
/// A host typically pairs with a **sentinel element** that a PHP chrome
/// contributor appends to the published tree (e.g. `native_drawer`). The host
/// declares the sentinel type it `consumes` so the root renderers can keep that
/// marker out of the visible screen content; the host pulls the matching child
/// out of `root.children` itself and renders nothing when it's absent.
///
/// Example (in a plugin's init function):
/// ```swift
/// NativeRootHostRegistry.shared.register("native-ui.drawer", consumes: "native_drawer") { root, content in
///     let node = root.children.first { $0.type == "native_drawer" }
///     return AnyView(NativeDrawerHost(drawerNode: node) { content })
/// }
/// ```
final class NativeRootHostRegistry {
    static let shared = NativeRootHostRegistry()

    /// A host wraps the rendered tree: given the published root node (so it can
    /// find its own sentinel child) and the content rendered so far, it returns
    /// a new view wrapping that content.
    ///
    /// Not `public`: `NativeUINode` is an internal type, and plugin Swift is
    /// compiled into this same module, so `internal` access is all that's
    /// needed (a `public` surface exposing an internal type won't compile).
    typealias Host = (_ root: NativeUINode, _ content: AnyView) -> AnyView

    private struct Entry {
        let name: String
        let host: Host
    }

    private var entries: [Entry] = []
    private var consumed: Set<String> = []
    private let lock = NSLock()

    private init() {}

    /// Register a root host. `consumes` is the sentinel element type this host
    /// pulls out of the tree (if any), so the root renderers exclude it from
    /// screen content. Hosts are applied in registration order — the first
    /// registered ends up innermost (closest to the content).
    func register(_ name: String, consumes: String? = nil, host: @escaping Host) {
        lock.lock(); defer { lock.unlock() }
        if let consumes { consumed.insert(consumes) }
        entries.append(Entry(name: name, host: host))
    }

    /// Whether some registered host consumes this element type (so it should be
    /// kept out of the visible screen content by the root renderers).
    func consumes(_ type: String) -> Bool {
        lock.lock(); defer { lock.unlock() }
        return consumed.contains(type)
    }

    /// Fold every registered host around `content`. A transparent pass-through
    /// (returns `content` unchanged) when no hosts are registered, so trees that
    /// use no plugin chrome pay nothing — preserving the minimal-wrapping
    /// guarantee the native-chrome tab/stack renderers rely on.
    func wrap(root: NativeUINode, content: AnyView) -> AnyView {
        lock.lock(); let hosts = entries; lock.unlock()
        var view = content
        for entry in hosts {
            view = entry.host(root, view)
        }
        return view
    }
}
