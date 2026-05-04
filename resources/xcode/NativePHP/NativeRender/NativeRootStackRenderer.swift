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
        let displayModeStr = root.props.getString("display_mode", default: "inline")
        let actions = root.children.filter { $0.type == "top_bar_action" }
        // Bottom-pinned content (chat input, search bar, etc.) — extracted
        // out of children so it doesn't render inline; pinned via
        // `.safeAreaInset(.bottom)` below so the keyboard pushes it up.
        let bottomBar = root.children.first { $0.type == "bottom_bar" }
        let screenContent = root.children.first {
            $0.type != "top_bar_action" && $0.type != "bottom_bar"
        }

        let textColor: Color = textArgb != 0 ? Color(argb: textArgb) : .primary
        let hasExplicitBg = bgArgb != 0

        // Map the PHP-side string to SwiftUI's NavigationBarItem.TitleDisplayMode.
        //   `large`     — iOS-native big title, left-aligned, collapses on scroll
        //   `automatic` — iOS picks (large at root, inline after a push)
        //   else        — small centered title (previous default)
        let titleDisplayMode: NavigationBarItem.TitleDisplayMode = {
            switch displayModeStr {
            case "large":     return .large
            case "automatic": return .automatic
            default:          return .inline
            }
        }()

        screenView(screenContent)
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(titleDisplayMode)
            // iOS 18+ has a first-class `.navigationSubtitle(...)` that sits
            // with the title (next to it for inline, under the large title
            // for large). Use it when the OS supports it AND we're not
            // already rendering subtitle via the principal toolbar item
            // (which only happens for inline mode below).
            .modifier(NavigationSubtitleModifier(
                subtitle: subtitle,
                showsAsPrincipal: titleDisplayMode == .inline
            ))
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
                // Render subtitle as a `.principal` toolbar item ONLY when
                // displayMode is inline. With `.large` (or `.automatic` at
                // root), the principal slot duplicates content next to the
                // big title — the user sees two stacked titles. iOS 18+
                // exposes `.navigationSubtitle(...)` which sits with the
                // large title naturally; until we adopt that path, we just
                // suppress the principal subtitle for non-inline modes.
                if !subtitle.isEmpty && titleDisplayMode == .inline {
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
            .modifier(StackBarBackgroundModifier(argb: bgArgb))
            .modifier(StackBottomBarInsetModifier(bottomBar: bottomBar))
    }

    @ViewBuilder
    private func screenView(_ node: NativeUINode?) -> some View {
        if let node = node {
            // GlassEffectContainer coordinates `.interactive(true)` press
            // animations across glass surfaces in this screen so they
            // crossfade between idle and pressed states cleanly. Without
            // a container, the per-glass-effect animation isn't scoped
            // and the press transition renders as a visible flicker
            // behind the touched element. iOS 26+ only.
            NodeView(node: node).withGlassContainer()
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

/// Pins a `bottom_bar` element above the safe-area-bottom (and above
/// the keyboard when one is presented). Renders nothing if the layout
/// didn't supply a bottom bar for this level. Mirrors the tabs
/// renderer's `BottomBarInsetModifier`: iOS 26 uses `.safeAreaBar`
/// (first-class floating glass bar primitive), pre-26 falls back to
/// `.safeAreaInset(.bottom)`.
private struct StackBottomBarInsetModifier: ViewModifier {
    let bottomBar: NativeUINode?

    func body(content: Content) -> some View {
        if let bottomBar, let inner = bottomBar.children.first {
            if #available(iOS 26.0, *) {
                content.safeAreaBar(edge: .bottom) {
                    NodeView(node: inner)
                }
            } else {
                content.safeAreaInset(edge: .bottom, spacing: 0) {
                    NodeView(node: inner)
                }
            }
        } else {
            content
        }
    }
}

/// Same conditional-bar-background pattern as the tabs renderer: skip
/// `.toolbarBackground` entirely when the layout didn't supply an
/// explicit color, so iOS 26 keeps its adaptive Liquid Glass material
/// on the navigation bar instead of having `.clear` forcibly applied.
private struct StackBarBackgroundModifier: ViewModifier {
    let argb: Int

    func body(content: Content) -> some View {
        if argb != 0 {
            content
                .toolbarBackground(Color(argb: argb), for: .navigationBar)
                .toolbarBackground(.visible, for: .navigationBar)
        } else {
            content
        }
    }
}

/// Conditionally applies iOS 26+ `.navigationSubtitle(...)` so the
/// subtitle sits with the title in the system bar — the right place for
/// it when the title display mode is `.large`. Skipped when the inline
/// path already renders the subtitle via a `.principal` `ToolbarItem`
/// (so we don't double-render it).
///
/// `.navigationSubtitle` was added in iOS 26 (alongside the toolbar
/// title-display-mode work). Pre-iOS-26 the subtitle is silently dropped
/// in `.large`/`.automatic` modes — fall back to `displayMode('inline')`
/// to keep the subtitle visible on older OSes.
private struct NavigationSubtitleModifier: ViewModifier {
    let subtitle: String
    let showsAsPrincipal: Bool

    func body(content: Content) -> some View {
        if subtitle.isEmpty || showsAsPrincipal {
            content
        } else if #available(iOS 26.0, *) {
            content.navigationSubtitle(subtitle)
        } else {
            content
        }
    }
}
