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
///
/// Every registration records an owner: `core`, or the package name of the
/// plugin whose nativephp.json declared the component (the generated
/// `registerPluginRenderers()` passes it). A type belongs to whoever registers
/// it first. A registration from another owner is refused and logged, so a
/// plugin can't quietly replace core's chrome or another plugin's renderer.
/// Once startup registration is done (`lockRegistrations()`), new types are
/// refused too. Refusals never crash the app; they only log.
final class SwiftUIRendererRegistry {
    static let shared = SwiftUIRendererRegistry()

    /// Owner for the renderers core registers itself.
    static let coreOwner = "core"
    /// Owner for registrations that don't name one (code generated before
    /// owners existed). They follow the same rules as everyone else.
    static let unknownOwner = "unknown"

    private var renderers: [String: (NativeUINode) -> AnyView] = [:]
    private var owners: [String: String] = [:]
    private var isLocked = false
    private let lock = NSLock()

    /// Internal rather than private so tests can work on a fresh registry.
    /// The app uses `shared`.
    init() {}

    /// Kept for plugin code generated before owners existed. Registers under
    /// `unknownOwner`.
    func register(_ type: String, _ renderer: @escaping (NativeUINode) -> AnyView) {
        register(type, owner: Self.unknownOwner, renderer)
    }

    /// Registers `renderer` for `type` on behalf of `owner`.
    ///
    /// Registering a type again under the same owner keeps the first renderer
    /// and does nothing else. Returns false when the registration was refused:
    /// another owner holds the type, or the registry is locked.
    @discardableResult
    func register(_ type: String, owner: String, _ renderer: @escaping (NativeUINode) -> AnyView) -> Bool {
        lock.lock()
        let refusal: String?
        if let current = owners[type] {
            refusal = current == owner
                ? nil
                : "Refused the renderer for '\(type)' from \(owner): it is already registered by \(current)."
        } else if isLocked {
            refusal = "Refused the renderer for '\(type)' from \(owner): renderers can only be registered during startup."
        } else {
            renderers[type] = renderer
            owners[type] = owner
            refusal = nil
        }
        lock.unlock()

        if let refusal {
            NSLog("[NativePHP] %@", refusal)
            return false
        }
        return true
    }

    /// Refuses every later registration of a new type. Called once core,
    /// generated plugin renderers and plugin init functions have run.
    func lockRegistrations() {
        lock.lock()
        defer { lock.unlock() }
        isLocked = true
    }

    /// Who registered `type`: `core`, a plugin package name, or nil.
    func owner(of type: String) -> String? {
        lock.lock()
        defer { lock.unlock() }
        return owners[type]
    }

    func get(_ type: String) -> ((NativeUINode) -> AnyView)? {
        lock.lock()
        defer { lock.unlock() }
        return renderers[type]
    }
}
