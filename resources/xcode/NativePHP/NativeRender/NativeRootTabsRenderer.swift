import SwiftUI

/// Native chrome renderer for the `native_root_tabs` sentinel. Uses
/// SwiftUI's `TabView` — system tab styling, selection
/// animation, and (on iOS 26+) Liquid Glass material on the bar by
/// default.
///
/// **Tab content semantics.** TabView wants every tab view alive at
/// once, but PHP only ever has the active tab's tree in hand. So:
///   - Inactive tabs render `Color.clear` placeholders (zero memory)
///   - Active tab renders the screen content child (the non-tab,
///     non-action child of the sentinel)
///   - The "active" index is read from `bottom_nav_item.active` (the
///     framework's `TabBar::highlight()` already sets exactly one
///     tab's `active` based on the current URI)
///
/// **Selection sync.**
///   - User taps a tab → SwiftUI's `selection` updates → onChange fires
///     `sendPressEvent` on that tab's `onPress` (auto-wired by
///     `BottomNavItem::resolveProps()` to a `replace` navigation toward
///     the tab's URL) → PHP publishes the new tree at the new URL →
///     re-rendered tree has `active = true` on the just-tapped tab →
///     activeTabIdx updates → set `selection` to match (no-op, already
///     there).
///   - PHP-initiated tab swap (e.g. programmatic `replace()`): same
///     publish flow → activeTabIdx changes → set `selection` → SwiftUI
///     animates the swap. We don't fire press (already at activeTabIdx).
///
/// **Three-tier appearance** (matches NavigationStack):
///   - No `background_color` → defaults; on iOS 26+ Liquid Glass.
///   - `background_color` set → `.toolbarBackground(.visible, .tabBar)`
///     with the explicit color → opaque native bar.
///   - Inline `<native:bottom-nav>` blade bypasses entirely.
struct NativeRootTabsRenderer: View {
    let node: NativeUINode

    @State private var selection: Int = 0
    @StateObject private var tabBag = TabCoordinatorBag()

    var body: some View {
        let tabs = node.children.filter { $0.type == "bottom_nav_item" }
        let accessory = node.children.first { $0.type == "tab_accessory" }
        let currentUri = node.props.getString("current_uri", default: "")
        let minimizeOnScroll = node.props.getBool("minimize_on_scroll")

        // Determine which tab "owns" the current URI via longest URL
        // prefix match against tab URLs. This is what lets a pushed
        // sub-route (e.g. `/syncup-native/chat/123` under a
        // `/syncup-native` Chats tab) render INSIDE the Chats tab's
        // NavigationStack rather than full-screen-replacing the layout.
        // Falls back to `BottomNavItem.active` (TabBar::highlight set
        // the same way) so non-prefix tab layouts still pick correctly.
        let owningIdx = owningTabIndex(for: currentUri, tabs: tabs)
            ?? (tabs.firstIndex { $0.props.getBool("active") } ?? 0)

        // Sync the owning tab's coordinator with this publish — cache
        // synchronously so the destination resolver always sees the
        // freshest tree, then defer the path mutation (push / pop) via
        // async since `path` is @Published.
        if !currentUri.isEmpty, owningIdx < tabs.count {
            let owningRootUri = tabs[owningIdx].props.getString("url", default: "")
            let coord = tabBag.coordinator(forIdx: owningIdx, rootUri: owningRootUri)
            coord.cache(uri: currentUri, node: node)
            DispatchQueue.main.async {
                coord.receive(uri: currentUri, rootNode: node)
            }
        }

        let activeArgb = node.props.getColor("active_color", default: 0)
        let activeColor: Color = activeArgb != 0 ? Color(argb: activeArgb) : .accentColor
        let bgArgb = node.props.getColor("background_color", default: 0)
        let isDark = node.props.getBool("dark")

        // Folded NavBar config — present when the layout supplies both
        // bars. Tabs render an inner NavigationStack with toolbar even
        // when this is empty (so per-tab pushes still get a back chevron).
        let navBack = node.props.getBool("nav_back")
        let navTitleText = node.props.getString("nav_title", default: "")
        let navBgArgb = node.props.getColor("nav_background_color", default: 0)
        let navTextArgb = node.props.getColor("nav_text_color", default: 0)
        let hasNavBar = navBack || !navTitleText.isEmpty

        // Explicit `return` switches the body out of @ViewBuilder mode so
        // the side-effect `if !currentUri.isEmpty { … }` block above is a
        // plain statement (not a "view" with a `()` result) — same pattern
        // as `NativeRootStackRenderer.body`.
        return TabView(selection: $selection) {
            // Key the ForEach off the enumerated `offset` (integer
            // position) rather than the SwiftUI element id — element
            // ids regenerate on every PHP publish, which would make
            // ForEach treat every tab as brand-new on tab switch.
            // SwiftUI would then rebuild every Tab + its inner
            // NavigationStack from scratch each publish, producing a
            // visible flicker (toolbar styles applied a frame late)
            // and recreating animation state mid-transition. Tab
            // positions are stable across publishes for a layout's
            // tabBar(), so the offset is a reliable identity.
            ForEach(Array(tabs.enumerated()), id: \.offset) { idx, tab in
                let label = tab.props.getString("label", default: "")
                let icon = tab.props.getString("icon", default: "circle")
                let isSearch = tab.props.getBool("search")

                // `Tab(role: .search)` is the iOS 18+ API; on iOS 26 the
                // role triggers the floating Liquid Glass capsule beside
                // the main bar (Apple's Photos / Music pattern). Pre-26
                // it's a semantic no-op visually but kept for consistency.
                let tabRootUri = tab.props.getString("url", default: "")
                let coord = tabBag.coordinator(forIdx: idx, rootUri: tabRootUri)

                Tab(value: idx, role: isSearch ? .search : nil) {
                    PerTabContent(
                        coordinator: coord,
                        hasNavBar: hasNavBar,
                        fallbackTitle: navTitleText,
                        fallbackShowBack: navBack,
                        fallbackTextArgb: navTextArgb,
                        fallbackBgArgb: navBgArgb
                    )
                } label: {
                    Label(label, systemImage: getIconForName(icon))
                }
                .badge(badgeFor(tab))
                    // ↑ `.badge(Text?)` — nil means "no badge". Passing
                    //   an empty string here would render as a presence
                    //   dot on the new `Tab(value:role:)` API.
            }
        }
        .tint(activeColor)
        .modifier(ExplicitBarBackgroundModifier(
            argb: bgArgb,
            placement: .tabBar
        ))
        .preferredColorScheme(isDark ? .dark : nil)
        .modifier(TabBarLiquidGlassModifier(
            accessory: accessory,
            minimizeOnScroll: minimizeOnScroll
        ))
        .onAppear {
            selection = owningIdx
        }
        .onChange(of: owningIdx) { newIdx in
            // PHP-driven owning-tab change (publish landed under a
            // different tab's URL prefix) — sync our SwiftUI selection.
            if selection != newIdx {
                selection = newIdx
            }
        }
        .onChange(of: selection) { newIdx in
            // User tapped a tab — fire its press handler so PHP runs
            // the BottomNavItem-auto-wired `replace` navigation. We
            // don't fire when selection already matches the owning tab
            // (PHP-driven sync path).
            guard newIdx != owningIdx, newIdx < tabs.count else { return }
            let tab = tabs[newIdx]
            if tab.onPress != 0 {
                NativeElementBridge.sendPressEvent(tab.onPress, nodeId: tab.id)
            }
            // Action-only tab (no URL) — the press fires something
            // off-screen (e.g. a bottom sheet) instead of navigating,
            // so PHP won't republish to switch the owning tab. Snap
            // selection back to the actual owning tab so the visible
            // "selected" indicator doesn't get stuck.
            let url = tab.props.getString("url", default: "")
            if url.isEmpty {
                DispatchQueue.main.async {
                    selection = owningIdx
                }
            }
        }
    }

    /// Longest URL prefix match against tab URLs. A pushed sub-route
    /// (e.g. `/syncup-native/chat/123`) returns the index of the tab
    /// whose URL is the longest prefix of `currentUri`. Returns nil if
    /// no tab claims the URI; the caller should fall back to PHP's
    /// `BottomNavItem.active` flag.
    private func owningTabIndex(for currentUri: String, tabs: [NativeUINode]) -> Int? {
        guard !currentUri.isEmpty else { return nil }
        var bestIdx: Int? = nil
        var bestLen: Int = -1
        for (idx, tab) in tabs.enumerated() {
            let tabUrl = tab.props.getString("url", default: "")
            guard !tabUrl.isEmpty else { continue }
            let isMatch = (currentUri == tabUrl) || currentUri.hasPrefix(tabUrl + "/")
            if isMatch && tabUrl.count > bestLen {
                bestIdx = idx
                bestLen = tabUrl.count
            }
        }
        return bestIdx
    }

    /// On the new `Tab(value:role:)` API, `.badge` accepts a `Text?` — nil
    /// means "no badge". Passing an empty string would render an empty
    /// red presence bubble (the iOS 18+ behavior), so explicit-text vs.
    /// nil is the only correct distinction.
    ///   - `badge` prop set → show that string
    ///   - `news` flag set → show a bullet (renders as a red dot)
    ///   - neither → return nil so no badge is drawn at all
    private func badgeFor(_ tab: NativeUINode) -> Text? {
        let badge = tab.props.getString("badge", default: "")
        if !badge.isEmpty { return Text(badge) }
        if tab.props.getBool("news") { return Text("•") }
        return nil
    }
}

/// One tab's content area — hosts its own NavigationStack bound to the
/// per-tab coordinator's path. Each pushed level inside the tab reads
/// its toolbar config (title, back, actions, colors) from its own
/// cached node, so a chat detail (pushed) gets its own title + actions
/// independently of the tab root's toolbar.
///
/// `fallback*` props come from the layout's folded NavBar — used only
/// before the tab's coordinator has cached anything (cold-start frame
/// for an inactive tab) so the inner NavigationStack still renders
/// something with a sensible toolbar.
private struct PerTabContent: View {
    @ObservedObject var coordinator: PerTabNavigationCoordinator
    let hasNavBar: Bool
    let fallbackTitle: String
    let fallbackShowBack: Bool
    let fallbackTextArgb: Int
    let fallbackBgArgb: Int

    var body: some View {
        if hasNavBar {
            NavigationStack(path: $coordinator.path) {
                levelView(uri: coordinator.rootUri, isRoot: true)
                    .navigationDestination(for: String.self) { uri in
                        levelView(uri: uri, isRoot: false)
                    }
            }
            .onChange(of: coordinator.path) { newPath in
                coordinator.onPathChange(newPath: newPath)
            }
        } else {
            levelContent(for: coordinator.rootNodeCache[coordinator.rootUri])
        }
    }

    @ViewBuilder
    private func levelView(uri: String, isRoot: Bool) -> some View {
        if let cached = coordinator.rootNodeCache[uri] {
            renderLevel(cached, isRoot: isRoot)
        } else {
            Color.clear
                .navigationTitle(fallbackTitle)
                .navigationBarTitleDisplayMode(.inline)
                .modifier(TabsToolbarModifier(
                    showBack: fallbackShowBack && isRoot,
                    title: fallbackTitle,
                    actions: [],
                    textArgb: fallbackTextArgb,
                    bgArgb: fallbackBgArgb
                ))
        }
    }

    @ViewBuilder
    private func renderLevel(_ root: NativeUINode, isRoot: Bool) -> some View {
        let title = root.props.getString("nav_title", default: fallbackTitle)
        // Manual back chevron only shows at the tab's root level — at
        // that level there's no NavigationStack history to pop, so the
        // chevron fires `sendSystemBackEvent` to leave the tabs entirely
        // (back to the launcher / wherever the user came from).
        // Pushed levels get NavigationStack's automatic system chevron
        // which pops the path natively.
        let layoutShowBack = root.props.getBool("nav_back") || fallbackShowBack
        let manualBack = layoutShowBack && isRoot
        let textArgb = root.props.getColor("nav_text_color", default: fallbackTextArgb)
        let bgArgb = root.props.getColor("nav_background_color", default: fallbackBgArgb)
        let actions = root.children.filter { $0.type == "top_bar_action" }
        // Bottom-pinned content (chat input, search bar, etc.) — extracted
        // out of children so it doesn't render inline; the actual content
        // is the BottomBar wrapper's first child.
        let bottomBar = root.children.first { $0.type == "bottom_bar" }
        let screenContent = root.children.first {
            $0.type != "bottom_nav_item"
                && $0.type != "top_bar_action"
                && $0.type != "tab_accessory"
                && $0.type != "bottom_bar"
        }

        levelContent(for: root, screenContent: screenContent)
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .modifier(TabsToolbarModifier(
                showBack: manualBack,
                title: title,
                actions: actions,
                textArgb: textArgb,
                bgArgb: bgArgb
            ))
            // Hide the parent TabView's tab bar on any pushed level
            // FIRST — matches Apple's iMessage / Music / Mail pattern
            // where pushed levels (chat detail, now-playing) get the
            // full screen. Crucially, this changes the available safe
            // area, so any subsequent `safeAreaInset(.bottom)` (the
            // bottom-bar modifier below) pins to the correct edge.
            // Inverting the order leaves the bar latched to where the
            // tab bar *used to be* — visually mid-screen.
            .modifier(HideTabBarOnPushModifier(isRoot: isRoot))
            .modifier(BottomBarInsetModifier(bottomBar: bottomBar))
    }

    @ViewBuilder
    private func levelContent(for cached: NativeUINode?, screenContent: NativeUINode? = nil) -> some View {
        let content: NativeUINode? = screenContent ?? cached?.children.first {
            $0.type != "bottom_nav_item"
                && $0.type != "top_bar_action"
                && $0.type != "tab_accessory"
                && $0.type != "bottom_bar"
        }
        if let content {
            // GlassEffectContainer scopes :interactive press animations.
            // See NativeRootStackRenderer.screenView for the full rationale.
            NodeView(node: content).withGlassContainer()
        } else {
            Color.clear
        }
    }
}

/// The per-level toolbar (back chevron + actions) plus background /
/// color-scheme modifiers, factored out so the SwiftUI type-checker
/// doesn't time out chasing the chained modifiers in renderLevel.
private struct TabsToolbarModifier: ViewModifier {
    let showBack: Bool
    let title: String
    let actions: [NativeUINode]
    let textArgb: Int
    let bgArgb: Int

    func body(content: Content) -> some View {
        let textColor: Color = textArgb != 0 ? Color(argb: textArgb) : .primary
        let toolbarScheme: ColorScheme? = {
            guard textArgb != 0 else { return nil }
            let r = Double((textArgb >> 16) & 0xFF) / 255.0
            let g = Double((textArgb >>  8) & 0xFF) / 255.0
            let b = Double( textArgb        & 0xFF) / 255.0
            let luminance = 0.299 * r + 0.587 * g + 0.114 * b
            return luminance > 0.5 ? .dark : .light
        }()

        content
            .toolbar {
                ToolbarItem(id: "back", placement: .topBarLeading) {
                    if showBack {
                        Button {
                            NativeElementBridge.sendSystemBackEvent()
                        } label: {
                            Image(systemName: "chevron.backward")
                                .font(.system(size: 17, weight: .semibold))
                                .foregroundColor(textColor)
                        }
                    }
                }
                ToolbarItemGroup(placement: .topBarTrailing) {
                    ForEach(actions) { action in
                        TabsActionView(action: action, textColor: textColor)
                    }
                }
            }
            .toolbarColorScheme(toolbarScheme, for: .navigationBar)
            .modifier(ExplicitBarBackgroundModifier(
                argb: bgArgb,
                placement: .navigationBar
            ))
    }
}

/// Single trailing toolbar action — plain Button when no sub-items,
/// pull-down Menu when `NavAction::items()` produced sub-actions.
private struct TabsActionView: View {
    let action: NativeUINode
    let textColor: Color

    var body: some View {
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
/// didn't supply a bottom bar for this level.
///
/// Uses `.safeAreaInset(.bottom)` — the battle-tested primitive that
/// reserves space above the safe-area-bottom and renders the bar inside
/// it. Keyboard avoidance is automatic. Bar content is responsible for
/// its own visible chrome (bg color or glass class).
private struct BottomBarInsetModifier: ViewModifier {
    let bottomBar: NativeUINode?

    func body(content: Content) -> some View {
        // Always use `.safeAreaInset(.bottom)` — the battle-tested
        // primitive for pinning content above the safe-area bottom.
        //
        // Earlier this path branched to `.safeAreaBar(.bottom)` on iOS 26+
        // for the floating-glass treatment iMessage uses. That implementation
        // mis-pins on the iOS 26 simulator (bar latches mid-screen) and adds
        // a wide glass plate behind the bar that visually washes content
        // above it. Both symptoms cleared by reverting to `.safeAreaInset`.
        // Re-enable `.safeAreaBar` later if Apple's implementation
        // stabilises on real hardware AND we want the floating treatment.
        if let bottomBar, let inner = bottomBar.children.first {
            content.safeAreaInset(edge: .bottom, spacing: 0) {
                NodeView(node: inner)
            }
        } else {
            content
        }
    }
}

/// Hides the enclosing TabView's tab bar on pushed levels (mirrors
/// Apple's iMessage / Music / Mail pattern). Tab root level keeps the
/// bar visible so the user can still switch tabs from there.
///
/// `.toolbar(.hidden, for: .tabBar)` hides the bar visually but doesn't
/// release its reserved safe-area space — that's a documented SwiftUI
/// behavior. We also `.ignoresSafeArea(.container, edges: .bottom)` so
/// the screen content actually fills the released vertical space.
/// Device-level safe areas (home indicator) are NOT in the `.container`
/// region, so they stay intact and our content still pins above the
/// home indicator correctly.
private struct HideTabBarOnPushModifier: ViewModifier {
    let isRoot: Bool

    func body(content: Content) -> some View {
        if isRoot {
            content
        } else {
            content
                .toolbar(.hidden, for: .tabBar)
                .ignoresSafeArea(.container, edges: .bottom)
        }
    }
}

/// Conditionally applies `.toolbarBackground` only when the layout
/// supplied an explicit color. Applying `.toolbarBackground(.clear, ...)`
/// to a default-styled bar disables iOS 26 Liquid Glass, which manifests
/// as the bar visibly going white during tab transitions and
/// republishes. Skipping the modifier entirely lets the system keep its
/// adaptive material.
private struct ExplicitBarBackgroundModifier: ViewModifier {
    let argb: Int
    let placement: ToolbarPlacement

    func body(content: Content) -> some View {
        if argb != 0 {
            content
                .toolbarBackground(Color(argb: argb), for: placement)
                .toolbarBackground(.visible, for: placement)
        } else {
            content
        }
    }
}

/// Encapsulates the iOS 26-only `.tabViewBottomAccessory(...)` and
/// `.tabBarMinimizeBehavior(...)` modifiers. Wrapping them in a single
/// `ViewModifier` keeps the `#available` branch confined to one place
/// and gives the renderer a no-op fallback on iOS 18-25.
private struct TabBarLiquidGlassModifier: ViewModifier {
    let accessory: NativeUINode?
    let minimizeOnScroll: Bool

    func body(content: Content) -> some View {
        if #available(iOS 26.0, *) {
            // `.tabViewBottomAccessory` always renders the slot — even when
            // its closure is empty — so an empty pill appears above the tab
            // bar. Only attach the modifier when the layout actually
            // supplied an accessory; otherwise just apply the minimize
            // behavior on its own.
            if let inner = accessory?.children.first {
                content
                    .tabBarMinimizeBehavior(minimizeOnScroll ? .onScrollDown : .never)
                    .tabViewBottomAccessory {
                        NodeView(node: inner)
                    }
            } else {
                content
                    .tabBarMinimizeBehavior(minimizeOnScroll ? .onScrollDown : .never)
            }
        } else {
            content
        }
    }
}
