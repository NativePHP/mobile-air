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
        // Per-layout / per-bar chrome font (font_name prop), resolved through
        // the plugin seam. Applies where WE draw the title (the principal-slot
        // path); system-drawn `.navigationTitle` large titles keep the global
        // appearance font (UIKit exposes no per-screen hook).
        let chromeFontName = NativeChromeFontResolver.postScriptName(
            for: root.props.getString("font_name", default: "")
        )
        let displayModeStr = root.props.getString("display_mode", default: "inline")
        // Per-screen nav-bar opt-out (`$hidesNavBar` /
        // `navigationOptions()->hidden()`), folded onto the sentinel as
        // `hide_nav_bar`. Applied per destination below.
        let hideNavBar = root.props.getBool("hide_nav_bar")
        let actions = root.children.filter { $0.type == "top_bar_action" }
        // Custom principal-slot content (logo / titleView) — replaces the
        // string title when present.
        let titleNode = root.children.first { $0.type == "top_bar_title" }
        // Bottom-pinned content (chat input, search bar, etc.) — extracted
        // out of children so it doesn't render inline; pinned via
        // `.safeAreaInset(.bottom)` below so the keyboard pushes it up.
        let bottomBar = root.children.first { $0.type == "bottom_bar" }
        let screenContent = root.children.first {
            $0.type != "top_bar_action" && $0.type != "bottom_bar"
                && $0.type != "top_bar_title"
                && !NativeRootHostRegistry.shared.consumes($0.type)
        }

        let textColor: Color = textArgb != 0 ? Color(argb: textArgb) : .primary
        let hasExplicitBg = bgArgb != 0

        // `textColor` only reaches chrome WE draw — the back chevron, the
        // principal-slot title, and the trailing actions. System-drawn titles
        // (`.navigationTitle`, which is what `large` / `automatic` display
        // modes use) take their color from the bar's color scheme instead, so
        // an explicit `background_color` could otherwise leave a light bar with
        // a dark-mode white title — invisible. Derive the scheme from the
        // developer's `text_color`, matching NativeRootTabsRenderer.
        let toolbarScheme: ColorScheme? = {
            guard textArgb != 0 else { return nil }
            let r = Double((textArgb >> 16) & 0xFF) / 255.0
            let g = Double((textArgb >>  8) & 0xFF) / 255.0
            let b = Double( textArgb        & 0xFF) / 255.0
            let luminance = 0.299 * r + 0.587 * g + 0.114 * b
            return luminance > 0.5 ? .dark : .light
        }()

        // Map the PHP-side string to SwiftUI's NavigationBarItem.TitleDisplayMode.
        //   `large`     — iOS-native big title, left-aligned, collapses on scroll
        //   `automatic` — iOS picks (large at root, inline after a push)
        //   else        — small centered title (previous default)
        let titleDisplayMode: NavigationBarItem.TitleDisplayMode = {
            // A custom title view lives in the centered `.principal` slot,
            // which only reads right in inline mode (large would stack it
            // above the big string title).
            if titleNode != nil { return .inline }
            switch displayModeStr {
            case "large":     return .large
            case "automatic": return .automatic
            default:          return .inline
            }
        }()

        // Inline-mode bars render the string title via the `.principal`
        // toolbar slot (instead of the system `.navigationTitle`) when a
        // subtitle needs stacking OR a custom chrome font is set — the
        // system-drawn title exposes no per-screen font hook. Visually
        // identical to the system inline title. `large` / `automatic`
        // modes keep the system title (and the app-default font).
        let usesPrincipalTitle = titleNode == nil && titleDisplayMode == .inline
            && (!subtitle.isEmpty || chromeFontName != nil)

        screenView(screenContent)
            // Always set the string title, even when a titleView / principal
            // title owns the visible slot (with a `.principal` toolbar item
            // the system doesn't draw it): the back-chevron long-press
            // history menu and accessibility label this level from
            // `navigationTitle` — blanking it renders those entries as
            // empty glass pills.
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
                // Custom title view (logo / titleView) owns the principal
                // slot when present, replacing the string title entirely.
                if let titleNode {
                    ToolbarItem(placement: .principal) {
                        HStack(spacing: 6) {
                            ForEach(titleNode.children) { child in
                                NodeView(node: child).equatable()
                            }
                        }
                    }
                } else if usesPrincipalTitle {
                    // Render the title (and any subtitle) as a `.principal`
                    // toolbar item ONLY when displayMode is inline. With
                    // `.large` (or `.automatic` at root), the principal slot
                    // duplicates content next to the big title — the user sees
                    // two stacked titles. iOS 18+ exposes
                    // `.navigationSubtitle(...)` which sits with the large
                    // title naturally; until we adopt that path, we just
                    // suppress the principal content for non-inline modes.
                    ToolbarItem(placement: .principal) {
                        VStack(spacing: 0) {
                            Text(title)
                                .font(chromeFontName.map { .custom($0, size: 17).weight(.semibold) } ?? .headline)
                                .foregroundColor(textColor)
                            if !subtitle.isEmpty {
                                Text(subtitle)
                                    .font(chromeFontName.map { .custom($0, size: 12) } ?? .caption)
                                    .foregroundColor(textColor.opacity(0.7))
                            }
                        }
                    }
                }
                ToolbarItemGroup(placement: .topBarTrailing) {
                    ForEach(actions) { action in
                        actionView(action, textColor: textColor)
                    }
                }
            }
            .toolbarColorScheme(toolbarScheme, for: .navigationBar)
            .modifier(HideNavBarModifier(hidden: hideNavBar))
            .modifier(StackBarBackgroundModifier(argb: bgArgb))
            .modifier(StackBottomBarInsetModifier(bottomBar: bottomBar))
            // Inline search field — Apple HIG / Expo pattern. The
            // SearchableNavBarModifier (defined in NativeRootTabsRenderer.swift,
            // file-internal) reads the same `*search*` props that
            // `NavBar::searchBar()` emits in the stack-only path. No-ops
            // when the screen didn't configure a search bar.
            .modifier(SearchableNavBarModifier(
                placeholder: root.props.getString("search_placeholder", default: ""),
                callbackId: Int(root.props.getCallbackId("search_on_query")),
                nodeId: root.id,
                debounceMs: root.props.getInt("search_debounce_ms", default: 300),
                // `collapse`/`pinned` keep the search field pinned while the
                // large title collapses; `enterAlways`/unset let it tuck away.
                alwaysVisible: ["collapse", "pinned"].contains(
                    root.props.getString("scroll_behavior", default: "")
                )
            ))
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
            NodeView(node: node)
                .withGlassContainer()
                // NavigationStack hosts screens on its own container
                // background (systemBackground — white in light mode) and
                // SwiftUI exposes no override hook for it, so a dark app
                // gets a white band in the bottom safe-area inset. When
                // PHP set a window background (`UI.SetBackground`), paint
                // it behind the screen extended through the safe areas.
                // No-op when unset, preserving the stock appearance.
                .modifier(StackScreenBackgroundModifier())
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
                    if item.props.getBool("divider") {
                        // Inline visual separator emitted by `NavAction::divider()`.
                        Divider()
                    } else {
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
                        // SwiftUI's Menu won't propagate `.foregroundStyle(.red)`
                        // applied inside the Button label down to the Label's
                        // systemImage — the icon stays on the menu's accent color
                        // even with `Button(role: .destructive)`. Applying `.tint`
                        // on the Button itself is the path Menu actually honors;
                        // both the text and the symbol then render in the tint.
                        .tint(isDestructive ? .red : nil)
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

    @Environment(\.colorScheme) private var colorScheme

    func body(content: Content) -> some View {
        if let bottomBar, let inner = bottomBar.children.first {
            // `.fixedSize(vertical:)` matters: the inset APIs propose a
            // FINITE height to their content, and a FlexContainer fills any
            // finite proposal (CSS block semantics). Without it a bar whose
            // root has no explicit height inflates to the proposed height —
            // the input floats mid-screen over a giant empty bar, and on the
            // `.safeAreaBar` path the bar's scroll-edge effect then dims the
            // whole content region behind it.
            if #available(iOS 26.0, *) {
                content.safeAreaBar(edge: .bottom) {
                    barContent(inner)
                }
            } else {
                content.safeAreaInset(edge: .bottom, spacing: 0) {
                    barContent(inner)
                }
            }
        } else {
            content
        }
    }

    /// The bar view plus a home-indicator bleed: the inset APIs place the
    /// bar ABOVE the bottom safe area, so the strip beneath it shows the
    /// window background (`systemBackground`) — a black band under a themed
    /// bar in dark mode. Re-paint the bar's own background color extended
    /// through the bottom safe area (the iMessage treatment). Bars without
    /// an explicit background keep `.clear` and are unaffected.
    @ViewBuilder
    private func barContent(_ inner: NativeUINode) -> some View {
        NodeView(node: inner)
            .fixedSize(horizontal: false, vertical: true)
            .frame(maxWidth: .infinity)
            .background(barBackgroundColor(inner).ignoresSafeArea(edges: .bottom))
    }

    /// The bar root's resolved background color for the active appearance —
    /// same resolution order as `NodeStyleModifier.backgroundColor`.
    private func barBackgroundColor(_ node: NativeUINode) -> Color {
        let darkBg = colorScheme == .dark ? node.props.getColor("dark_bg_color", default: 0) : 0
        let argb = darkBg != 0 ? darkBg : (node.style?.bgColor ?? 0)
        return argb != 0 ? Color(argb: argb) : .clear
    }
}

/// Same conditional-bar-background pattern as the tabs renderer: skip
/// `.toolbarBackground` entirely when the layout didn't supply an
/// explicit color, so iOS 26 keeps its adaptive Liquid Glass material
/// on the navigation bar instead of having `.clear` forcibly applied.
///
/// The visibility request is dropped on iOS 26. Under Liquid Glass, forcing
/// the navigation bar background visible doesn't just recolor the bar — it
/// drops the large-title row entirely, leaving the toolbar items behind on
/// an empty bar. Verified on a device: both spellings do it, the deprecated
/// `.toolbarBackground(.visible, for:)` and the renamed
/// `.toolbarBackgroundVisibility(_:for:)`, so this is the request itself and
/// not the API name. The style overload alone already makes the bar opaque
/// there, so the explicit visibility call buys nothing. Pre-26 still needs
/// the pair — without it the color is applied to a hidden bar and never
/// shows.
private struct StackBarBackgroundModifier: ViewModifier {
    let argb: Int

    func body(content: Content) -> some View {
        if argb != 0 {
            if #available(iOS 18.0, *) {
                // `containerBackground(for: .navigation)` themes the WHOLE
                // navigation surface — the scroll edge, the expanded
                // large-title region, and the status-bar area — while bar
                // visibility stays automatic. Without it the bar color only
                // renders after scrolling (toolbarBackground alone does not
                // paint at the scroll edge, exactly where a large title
                // lives), and forcing `.visible` paints the band but
                // suppresses the large title at the scroll edge under
                // Liquid Glass. toolbarBackground still tints the collapsed
                // bar after scrolling.
                content
                    .toolbarBackground(Color(argb: argb), for: .navigationBar)
                    .containerBackground(Color(argb: argb), for: .navigation)
            } else {
                content
                    .toolbarBackground(Color(argb: argb), for: .navigationBar)
                    .toolbarBackground(.visible, for: .navigationBar)
            }
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


/// Backgrounds a stack-hosted screen with the PHP-set window background
/// (`UI.SetBackground`), extended through the safe areas. NavigationStack
/// draws its own `systemBackground` container behind screen content with
/// no SwiftUI override hook — without this, a dark app shows a white band
/// in the bottom safe-area inset on every stack screen. No-op when no
/// override is set, preserving the stock appearance.
private struct StackScreenBackgroundModifier: ViewModifier {
    @ObservedObject private var windowBackground = WindowBackgroundState.shared

    func body(content: Content) -> some View {
        if let color = windowBackground.color {
            content.background(color.ignoresSafeArea())
        } else {
            content
        }
    }
}
