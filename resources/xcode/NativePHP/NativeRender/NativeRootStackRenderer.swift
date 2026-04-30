import SwiftUI

/// Native chrome renderer for the `native_root_stack` sentinel. Uses
/// SwiftUI's `NavigationStack` with a `NavigationCoordinator` that
/// bridges the runtime path between PHP's router and SwiftUI's path
/// binding.
///
/// **Path sync model.** PHP's router is the source of truth. Every
/// publish at a `native_root_stack` root flows through `coordinator.receive(uri:rootNode:)`,
/// which decides push / pop / no-op based on whether the URI is already
/// in the path. The path-bound `NavigationStack` then animates the
/// transition. User-initiated swipe-pops are caught by `.onChange` of
/// `coordinator.path` and fire `sendSystemBackEvent` back to PHP so the
/// runloop pops its own stack to match.
///
/// **Per-URI cache.** Each level of the stack is rendered from the
/// coordinator's `rootNodeCache` keyed on URI. This is what makes
/// edge-swipe-back work — during the system pop animation, both the
/// from- and to-screens need real content; the cache provides it.
///
/// **Three-tier appearance** (matches the locked-in contract):
///   - No `background_color` set → defaults; iOS 26+ applies Liquid Glass
///   - `background_color` set → opaque native bar with developer's color
///   - inline `<native:top-bar>` blade bypasses native chrome entirely
struct NativeRootStackRenderer: View {
    let node: NativeUINode

    @ObservedObject private var coordinator = NavigationCoordinator.shared

    var body: some View {
        let currentUri = node.props.getString("current_uri", default: "")

        // Write cache synchronously so destinations always render from
        // the freshest tree on this very render pass. The path mutation
        // (push / pop) stays deferred via async since `path` is
        // @Published and can't be mutated during body.
        if !currentUri.isEmpty {
            coordinator.cache(uri: currentUri, node: node)
            DispatchQueue.main.async {
                coordinator.receive(uri: currentUri, rootNode: node)
            }
        }

        return NavigationStack(path: $coordinator.path) {
            // The `NavigationStack` root is the bottommost stack URI
            // (`coordinator.rootUri`) — pushed levels live in `path` and
            // are rendered via `.navigationDestination(for:)`. On the
            // very first publish, before the coordinator has seeded its
            // root, fall back to `currentUri`.
            let rootUri = coordinator.rootUri ?? currentUri
            destination(uri: rootUri, isRoot: true)
                .navigationDestination(for: String.self) { uri in
                    destination(uri: uri, isRoot: false)
                }
        }
        .onChange(of: coordinator.path) { newPath in
            coordinator.onPathChange(newPath: newPath)
        }
    }

    /// Resolve a URI to its renderable content from the cache. Cache is
    /// kept fresh by the synchronous `coordinator.cache(...)` write at
    /// the top of `body`, so this never reads a stale tree. Reading from
    /// cache (rather than from the live `node`) also keeps mid-animation
    /// destinations stable: when PHP republishes during a swipe-back,
    /// `currentUri` shifts but the popping destination keeps rendering
    /// from its own cache entry until the animation finishes.
    @ViewBuilder
    private func destination(uri: String, isRoot: Bool) -> some View {
        if let cached = coordinator.rootNodeCache[uri] {
            renderRoot(cached, isRoot: isRoot)
        } else {
            Color.clear
        }
    }

    /// Render the screen-content child of a `native_root_stack` plus the
    /// toolbar / title configured on it. Each push level has its own
    /// independent toolbar config (read from its own cached root).
    @ViewBuilder
    private func renderRoot(_ root: NativeUINode, isRoot: Bool) -> some View {
        let title = root.props.getString("title", default: "")
        let subtitle = root.props.getString("subtitle", default: "")
        let showBack = root.props.getBool("back")
        let bgArgb = root.props.getColor("background_color", default: 0)
        let textArgb = root.props.getColor("text_color", default: 0)
        let actions = root.children.filter { $0.type == "top_bar_action" }
        let screenContent = root.children.first { $0.type != "top_bar_action" }

        let textColor: Color = textArgb != 0 ? Color(argb: textArgb) : .primary
        let hasExplicitBg = bgArgb != 0

        screenView(screenContent)
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Manual back chevron only at the root level — pushed
                // levels get the system back chevron from NavigationStack
                // itself, which fires the path binding shrink (caught by
                // onChange and forwarded to PHP). Showing both would
                // duplicate the chevron at every pushed level.
                if showBack && isRoot {
                    ToolbarItem(placement: .topBarLeading) {
                        Button {
                            NativeElementBridge.sendSystemBackEvent()
                        } label: {
                            Image(systemName: "chevron.backward")
                                .font(.system(size: 17, weight: .semibold))
                                .foregroundColor(textColor)
                        }
                    }
                }
                if !subtitle.isEmpty {
                    ToolbarItem(placement: .principal) {
                        VStack(spacing: 0) {
                            Text(title)
                                .font(.headline)
                                .foregroundColor(textColor)
                            Text(subtitle)
                                .font(.caption)
                                .foregroundColor(textColor.opacity(0.7))
                        }
                    }
                }
                ToolbarItemGroup(placement: .topBarTrailing) {
                    ForEach(actions) { action in
                        actionView(action, textColor: textColor)
                    }
                }
            }
            .toolbarBackground(
                hasExplicitBg ? Color(argb: bgArgb) : .clear,
                for: .navigationBar
            )
            .toolbarBackground(
                hasExplicitBg ? .visible : .automatic,
                for: .navigationBar
            )
    }

    @ViewBuilder
    private func screenView(_ node: NativeUINode?) -> some View {
        if let node = node {
            NodeView(node: node)
        } else {
            Color.clear
        }
    }

    /// Renders one trailing action — plain Button when the action has
    /// no sub-items, SwiftUI `Menu` of sub-items when `NavAction.items()`
    /// was set on the PHP side (which puts sub-actions in
    /// `action.children`).
    @ViewBuilder
    private func actionView(_ action: NativeUINode, textColor: Color) -> some View {
        let icon = action.props.getString("icon", default: "ellipsis")
        let subItems = action.children.filter { $0.type == "top_bar_action" }

        if subItems.isEmpty {
            Button {
                if action.onPress != 0 {
                    NativeElementBridge.sendPressEvent(action.onPress, nodeId: action.id)
                }
            } label: {
                Image(systemName: getIconForName(icon))
                    .font(.system(size: 17, weight: .semibold))
                    .foregroundColor(textColor)
            }
        } else {
            Menu {
                ForEach(subItems) { item in
                    let itemLabel = item.props.getString("label", default: "")
                    let itemIcon = item.props.getString("icon", default: "")
                    let isDestructive = item.props.getBool("destructive")
                    Button(role: isDestructive ? .destructive : nil) {
                        if item.onPress != 0 {
                            NativeElementBridge.sendPressEvent(item.onPress, nodeId: item.id)
                        }
                    } label: {
                        if !itemIcon.isEmpty {
                            Label(itemLabel, systemImage: getIconForName(itemIcon))
                        } else {
                            Text(itemLabel)
                        }
                    }
                }
            } label: {
                Image(systemName: getIconForName(icon))
                    .font(.system(size: 17, weight: .semibold))
                    .foregroundColor(textColor)
            }
        }
    }
}
