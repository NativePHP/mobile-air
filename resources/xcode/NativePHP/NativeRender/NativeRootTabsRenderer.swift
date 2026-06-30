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

    // String-id-based selection rather than Int index — the tabs list
    // can change between publishes (e.g. when a search-role tab is
    // omitted because the active screen doesn't define searchItems()),
    // and an Int index can fall out of range during reconciliation,
    // which silently breaks the TabView's rendering. The string id is
    // stable across publishes for the same tab.
    @State private var selection: String = ""
    @StateObject private var tabBag = TabCoordinatorBag()

    var body: some View {
        let tabs = node.children.filter { $0.type == "bottom_nav_item" }
        let accessory = node.children.first { $0.type == "tab_accessory" }
        let currentUri = node.props.getString("current_uri", default: "")
        // Determine which tab "owns" the current URI via longest URL
        // prefix match against tab URLs. This is what lets a pushed
        // sub-route (e.g. `/syncup-native/chat/123` under a
        // `/syncup-native` Chats tab) render INSIDE the Chats tab's
        // NavigationStack rather than full-screen-replacing the layout.
        // Falls back to `BottomNavItem.active` (TabBar::highlight set
        // the same way) so non-prefix tab layouts still pick correctly.
        let owningIdx = owningTabIndex(for: currentUri, tabs: tabs)
            ?? (tabs.firstIndex { $0.props.getBool("active") } ?? 0)
        let owningId = owningIdx < tabs.count
            ? tabs[owningIdx].props.getString("id", default: "")
            : ""

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

        // `TabBar::labelVisibility('labeled'|'selected'|'unlabeled')`.
        // Defaults to "labeled" — matches Apple's TabView default.
        //   - "unlabeled" → Instagram pattern (icons only). We pass an
        //     empty title to `Tab(_:systemImage:value:)` AND configure
        //     `UITabBarAppearance` to suppress the title slot so the
        //     icon centers vertically in the bar.
        //   - "selected" → only the currently-active tab shows its label
        //     (matches the legacy custom `NativeBottomNav` behavior).
        //   - "labeled" → always show labels (default).
        let labelVisibility = node.props.getString("label_visibility", default: "labeled")

        // Folded NavBar config — present when the layout supplies both
        // bars. Tabs render an inner NavigationStack with toolbar even
        // when this is empty (so per-tab pushes still get a back chevron).
        let navBack = node.props.getBool("nav_back")
        let navTitleText = node.props.getString("nav_title", default: "")
        let navBgArgb = node.props.getColor("nav_background_color", default: 0)
        let navTextArgb = node.props.getColor("nav_text_color", default: 0)
        let hasNavBar = navBack || !navTitleText.isEmpty

        // Search-tab plumbing: iOS-side stable `NativeSearchTabRoot`
        // view renders inside the search tab when the user hasn't yet
        // navigated to the search route. Required for iOS 26's
        // `Tab(role: .search)` floating capsule's tap-to-activate —
        // confirmed via minimal SwiftUI reproducer that real `List` +
        // internal `.searchable` is what makes single-tap fire. On
        // activation (isPresented → true), the stable view fires the
        // search tab's auto-wired press → PHP `replace`s to the search
        // route → the PerTabContent path then renders the PHP tree
        // (which has its own `.searchable` via NavBarOptions::searchBar).
        let searchPlaceholder = node.props.getString("nav_search_placeholder", default: "")
        let searchMode = node.props.getString("nav_search_mode", default: "static")
        let searchDebounceMs = node.props.getInt("nav_search_debounce_ms", default: 250)
        let searchOnQueryCb = Int(node.props.getCallbackId("nav_search_on_query"))
        // Each search item is a `search_item` child node of the tabs
        // root (parallel to `bottom_nav_item`). PHP couldn't carry the
        // mixed string/object/element item shapes through the prop
        // wire format, so they ride the existing tree path instead.
        let searchItemNodes = node.children.filter { $0.type == "search_item" }
        let searchTabNode = tabs.first { $0.props.getBool("search") }
        let searchTabPressId = Int(searchTabNode?.onPress ?? 0)
        let searchTabNodeId = searchTabNode?.id ?? 0

        // Explicit `return` switches the body out of @ViewBuilder mode so
        // the side-effect `if !currentUri.isEmpty { … }` block above is a
        // plain statement (not a "view" with a `()` result) — same pattern
        // as `NativeRootStackRenderer.body`.
        // Pre-extract stable string ids alongside each tab — `NativeUINode.id`
        // is a per-publish FlatBuffer index that regenerates each tree, so
        // it isn't a stable ForEach identity across publishes. The PHP-side
        // slugified id (e.g. "home", "profile") is stable.
        let regularTabEntries: [(id: String, idx: Int, tab: NativeUINode)] = tabs
            .enumerated()
            .compactMap { idx, tab in
                tab.props.getBool("search")
                    ? nil
                    : (id: tab.props.getString("id", default: "tab_\(idx)"), idx: idx, tab: tab)
            }
        // Search-role tab is handled OUTSIDE the ForEach via a separate
        // conditional `if` — Apple's documented pattern for conditional
        // search tabs (`if selectedTab == 0 || selectedTab == 3`).
        // Putting it inside a ForEach with the regular tabs causes the
        // entire bar to drop out when the tab appears/disappears.
        let searchTabIdx = tabs.firstIndex { $0.props.getBool("search") }
        let searchTabIdString: String? = searchTabIdx.map { idx in
            tabs[idx].props.getString("id", default: "tab_\(idx)")
        }
        // Show the search tab when any of:
        //   - dynamic mode (results come on demand after the user
        //     types — the tab must be tappable even with zero items
        //     since that's the pre-typing state)
        //   - the active screen has static items injected
        //   - the user is currently on the search tab (sticky — keeps
        //     the tab from yanking itself out the instant they tap it)
        let shouldShowSearchTab = searchMode == "dynamic"
            || !searchItemNodes.isEmpty
            || selection == searchTabIdString

        return TabView(selection: $selection) {
            // Regular (non-search) tabs via ForEach. Keyed off the
            // stable PHP-side id so SwiftUI preserves identity across
            // publishes (was previously keyed off enumerated offset,
            // which shifted when tab counts changed and mangled
            // TabView's internal state).
            ForEach(regularTabEntries, id: \.id) { entry in
                let tab = entry.tab
                let idx = entry.idx
                let rawLabel = tab.props.getString("label", default: "")
                let icon = tab.props.getString("icon", default: "circle")
                let tabId = entry.id
                let tabRootUri = tab.props.getString("url", default: "")
                let coord = tabBag.coordinator(forIdx: idx, rootUri: tabRootUri)

                // Per-tab label visibility resolution — passing an
                // empty string keeps the system Tab styling intact
                // (the closure-`label:` form would opt out of Liquid
                // Glass entirely). The empty title space is suppressed
                // by the `UITabBarAppearance` modifier applied below.
                //
                // No outline → fill swap: iOS 26 Liquid Glass tab bars
                // auto-promote every SF symbol to `.fill` regardless
                // of what we pass, and the selection indicator is the
                // pill background (not the icon variant). Trying to
                // fight the system here is pointless — Apple's own
                // apps (Music, Mail, Photos) all show filled icons in
                // every tab state on iOS 26.
                let label: String = {
                    switch labelVisibility {
                    case "unlabeled":
                        return ""
                    case "selected":
                        return tabId == selection ? rawLabel : ""
                    default:
                        return rawLabel
                    }
                }()

                Tab(label, systemImage: getIconForName(icon), value: tabId) {
                    PerTabContent(
                        coordinator: coord,
                        hasNavBar: hasNavBar,
                        fallbackTitle: navTitleText,
                        fallbackShowBack: navBack,
                        fallbackTextArgb: navTextArgb,
                        fallbackBgArgb: navBgArgb
                    )

                }
                .badge(badgeFor(tab))
            }

            // Conditional search tab — Apple's "if inside TabView" pattern.
            // Declaring it outside ForEach (with a static `if`) keeps the
            // TabView declarative structure stable enough for SwiftUI to
            // diff add/remove correctly. Inside a ForEach this broke and
            // dropped the entire bar.
            if shouldShowSearchTab, let searchIdx = searchTabIdx, let searchId = searchTabIdString {
                let searchTab = tabs[searchIdx]
                let searchLabel = searchTab.props.getString("label", default: "Search")
                let searchIcon = searchTab.props.getString("icon", default: "magnifyingglass")
                Tab(searchLabel, systemImage: getIconForName(searchIcon), value: searchId, role: .search) {
                    NavigationStack {
                        NativeSearchTabRoot(
                            placeholder: searchPlaceholder,
                            itemNodes: searchItemNodes,
                            mode: searchMode,
                            onQueryCallbackId: searchOnQueryCb,
                            debounceMs: searchDebounceMs
                        )
                    }
                }
                .badge(badgeFor(searchTab))
            }
        }
        .tint(activeColor)
        .modifier(ExplicitBarBackgroundModifier(
            argb: bgArgb,
            placement: .tabBar
        ))
        .preferredColorScheme(isDark ? .dark : nil)
        .modifier(TabBarLabelVisibilityModifier(mode: labelVisibility))
        .modifier(TabBarAccessoryModifier(accessory: accessory))
        .onAppear {
            selection = owningId
        }
        .onChange(of: owningId) { newId in
            // PHP-driven owning-tab change (publish landed under a
            // different tab's URL prefix) — sync our SwiftUI selection.
            if selection != newId {
                selection = newId
            }
        }
        .onChange(of: selection) { newId in
            // User tapped a tab — fire its press handler so PHP runs
            // the BottomNavItem-auto-wired `replace` navigation. We
            // don't fire when selection already matches the owning tab
            // (PHP-driven sync path).
            guard newId != owningId else { return }
            guard let tab = tabs.first(where: { $0.props.getString("id", default: "") == newId }) else { return }
            if tab.onPress != 0 {
                NativeElementBridge.sendPressEvent(tab.onPress, nodeId: tab.id)
            }
            // Action-only tab (no URL, no press handler) — the press
            // fires something off-screen instead of navigating, so PHP
            // won't republish to switch the owning tab. Snap selection
            // back to the actual owning tab so the visible "selected"
            // indicator doesn't get stuck. Search-role tabs also have
            // `on_press == 0` (they're iOS-owned, no PHP destination)
            // but should keep selection on themselves while the user
            // interacts with the search UI — we skip snap-back for them.
            let url = tab.props.getString("url", default: "")
            let isSearch = tab.props.getBool("search")
            if url.isEmpty && !isSearch {
                DispatchQueue.main.async {
                    selection = owningId
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
            .id(coordinator.rootUri)
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
        // Per-screen explicit hide signal (PHP-side `$hidesTabBar` /
        // `tabBarOptions()->hidden()`). Unions with the `!isRoot`
        // path-depth signal in `HideTabBarOnPushModifier` so either can
        // request hiding — useful for tab-root screens that want the bar
        // hidden anyway, which path-depth alone can't express.
        let forceHideTabBar = root.props.getBool("hide_tab_bar")
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
                && !NativeRootHostRegistry.shared.consumes($0.type)
        }

        // Inline search field — Apple HIG pattern. iOS attaches
        // `.searchable` to the destination's nav bar when the screen's
        // `NavBarOptions::searchBar(...)` set `nav_search_*` props on
        // the folded chrome. `placeholder.isEmpty` no-ops the modifier
        // so non-search screens render unchanged.
        let searchPlaceholder = root.props.getString("nav_search_placeholder", default: "")
        let searchOnQueryCb = Int(root.props.getCallbackId("nav_search_on_query"))
        let searchDebounceMs = root.props.getInt("nav_search_debounce_ms", default: 300)

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
            .modifier(SearchableNavBarModifier(
                placeholder: searchPlaceholder,
                callbackId: searchOnQueryCb,
                nodeId: root.id,
                debounceMs: searchDebounceMs
            ))
            // Hide the parent TabView's tab bar on any pushed level
            // FIRST — matches Apple's iMessage / Music / Mail pattern
            // where pushed levels (chat detail, now-playing) get the
            // full screen. Crucially, this changes the available safe
            // area, so any subsequent `safeAreaInset(.bottom)` (the
            // bottom-bar modifier below) pins to the correct edge.
            // Inverting the order leaves the bar latched to where the
            // tab bar *used to be* — visually mid-screen.
            .modifier(HideTabBarOnPushModifier(isRoot: isRoot, forceHide: forceHideTabBar))
            .modifier(BottomBarInsetModifier(bottomBar: bottomBar))
    }

    @ViewBuilder
    private func levelContent(for cached: NativeUINode?, screenContent: NativeUINode? = nil) -> some View {
        let content: NativeUINode? = screenContent ?? cached?.children.first {
            $0.type != "bottom_nav_item"
                && $0.type != "top_bar_action"
                && $0.type != "tab_accessory"
                && $0.type != "bottom_bar"
                && !NativeRootHostRegistry.shared.consumes($0.type)
        }
        if let content {
            NodeView(node: content)
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
                    if item.props.getBool("divider") {
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
                        // See NativeRootStackRenderer.swift for the rationale —
                        // .tint on the Button is what SwiftUI's Menu actually
                        // routes to the Label's systemImage in destructive rows.
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
///
/// Two signals can request hiding:
///   - `!isRoot` — the per-tab `NavigationStack` has pushed levels, so
///     this is a detail screen (iOS-native path-depth signal).
///   - `forceHide` — the screen explicitly requested it via PHP-side
///     `$hidesTabBar` / `tabBarOptions()->hidden()`, folded onto the
///     chrome sentinel as `hide_tab_bar`. Lets the dev override the
///     default for cases where path-depth alone doesn't capture intent.
///
/// Bar hides if EITHER is true.
private struct HideTabBarOnPushModifier: ViewModifier {
    let isRoot: Bool
    let forceHide: Bool

    func body(content: Content) -> some View {
        if isRoot && !forceHide {
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

/// Applies `UITabBarAppearance` tweaks based on the
/// `TabBar::labelVisibility(...)` PHP-side setting. For "unlabeled"
/// tabs (Instagram pattern) we zero out the title font and push the
/// position off-screen so the icon centers vertically in the bar slot
/// without the title strip taking layout space.
///
/// SwiftUI's new iOS 18+ `Tab(_:systemImage:value:)` initializer
/// requires a `String` title (no overload for "icon only"), so we pass
/// an empty title from the renderer and let the appearance config below
/// suppress the slot itself.
///
/// `.labeled` is the default — no appearance changes; SwiftUI handles
/// the standard outline→fill on-select / Liquid Glass progressive-fill
/// styling on its own.
///
/// `.selected` is handled at the title-resolution site (empty title for
/// non-selected tabs) — no appearance tweak needed.
private struct TabBarLabelVisibilityModifier: ViewModifier {
    let mode: String

    func body(content: Content) -> some View {
        content.onAppear {
            applyAppearance()
        }
        .onChange(of: mode) { _ in
            applyAppearance()
        }
    }

    private func applyAppearance() {
        if #available(iOS 26.0, *) {
            // iOS 26 Liquid Glass tab bars own their own appearance —
            // setting ANY UITabBarAppearance (even a default-init one)
            // forces the bar into the legacy opaque rendering path,
            // which breaks both the glass material and
            // .tabBarMinimizeBehavior. The empty-string label passed to
            // Tab(_:systemImage:value:) is sufficient on Liquid Glass;
            // the bar auto-centers the icon without title spacing.
            return
        }

        guard mode == "unlabeled" else { return }

        let appearance = UITabBarAppearance()
        appearance.configureWithDefaultBackground()

        let clear: [NSAttributedString.Key: Any] = [.foregroundColor: UIColor.clear]
        let offscreen = UIOffset(horizontal: 0, vertical: 1000)

        for itemAppearance in [
            appearance.stackedLayoutAppearance,
            appearance.inlineLayoutAppearance,
            appearance.compactInlineLayoutAppearance,
        ] {
            itemAppearance.normal.titleTextAttributes = clear
            itemAppearance.selected.titleTextAttributes = clear
            itemAppearance.normal.titlePositionAdjustment = offscreen
            itemAppearance.selected.titlePositionAdjustment = offscreen
        }

        UITabBar.appearance().standardAppearance = appearance
        if #available(iOS 15.0, *) {
            UITabBar.appearance().scrollEdgeAppearance = appearance
        }
    }
}

private struct TabBarAccessoryModifier: ViewModifier {
    let accessory: NativeUINode?

    func body(content: Content) -> some View {
        if #available(iOS 26.0, *) {
            if let inner = accessory?.children.first {
                content
                    .tabViewBottomAccessory {
                        NodeView(node: inner)
                    }
            } else {
                content
            }
        } else {
            content
        }
    }
}

/// Attaches `.searchable` to a destination view when the chrome
/// sentinel carries `nav_search_*` props (PHP-side
/// `NavBarOptions::searchBar(...)`). Local `@State` holds the field
/// text; a debounced effect forwards changes to PHP via the existing
/// TEXT_CHANGE event type. iOS gets free Liquid Glass + scroll-collapse
/// + keyboard handling for the search field.
///
/// `placeholder.isEmpty` no-ops the modifier so screens without a
/// configured search bar render unchanged.
struct SearchableNavBarModifier: ViewModifier {
    let placeholder: String
    let callbackId: Int
    let nodeId: Int
    let debounceMs: Int
    /// When true, pin the search field so it stays visible as content
    /// scrolls (`.navigationBarDrawer(displayMode: .always)`) — the
    /// counterpart to Android's `collapse`/`pinned` scroll behaviors,
    /// where the large title collapses but search remains. When false
    /// (default), use `.automatic` so the field tucks under the large
    /// title until pulled down.
    var alwaysVisible: Bool = false

    @State private var text: String = ""
    @State private var debounceTask: Task<Void, Never>? = nil

    private var placement: SearchFieldPlacement {
        alwaysVisible ? .navigationBarDrawer(displayMode: .always) : .automatic
    }

    func body(content: Content) -> some View {
        if placeholder.isEmpty {
            content
        } else {
            content
                .searchable(text: $text, placement: placement, prompt: placeholder)
                .onChange(of: text) { _, newValue in
                    guard callbackId != 0 else { return }
                    debounceTask?.cancel()
                    if debounceMs <= 0 {
                        NativeElementBridge.sendTextChangeEvent(callbackId, nodeId: nodeId, text: newValue)
                        return
                    }
                    debounceTask = Task { @MainActor in
                        try? await Task.sleep(nanoseconds: UInt64(debounceMs) * 1_000_000)
                        if Task.isCancelled { return }
                        NativeElementBridge.sendTextChangeEvent(callbackId, nodeId: nodeId, text: newValue)
                    }
                }
        }
    }
}

/// iOS-side root for the `Tab(role: .search)` floating Liquid Glass
/// capsule. Mirrors the minimal SwiftUI reproducer (PeopleListView)
/// exactly: a `List` with `.searchable` attached internally. PHP
/// supplies the search corpus via `Tab::search(items: [...])`; iOS
/// filters locally against the user's query for snappy per-keystroke
/// response without a PHP round-trip.
///
/// iOS 26 single-tap on the floating Liquid Glass capsule activates
/// the `.searchable` reliably with this shape (real `List` content +
/// internal `.searchable`).
/// iOS-side root for the `Tab(role: .search)` floating Liquid Glass
/// capsule. Mirrors the minimal SwiftUI reproducer's shape (List +
/// internal `.searchable`) which is what iOS 26 needs for reliable
/// single-tap capsule activation.
///
/// Search items arrive as `search_item` child nodes on the tabs root
/// (PHP can't carry mixed shapes through a prop, so items ride the
/// regular tree wire). Each item's `kind` prop drives the row UI:
///
///   - `kind = "string"`  → `Text(value)` row, no tap.
///   - `kind = "object"`  → standard row with title/subtitle/leading/trailing.
///                          Tap fires the node's `on_press` (URL nav or method).
///   - `kind = "element"` → `NodeView(node: child)` for the user-provided
///                          subtree; tap handled by its own `->onPress(...)`.
///
/// Filter mode forks on the `mode` prop:
///   - `"static"`  → iOS filters locally against `query`.
///   - `"dynamic"` → iOS fires debounced TEXT_CHANGE; PHP returns new
///                   items via `search_item` children on next publish.
private struct NativeSearchTabRoot: View {
    let placeholder: String
    let itemNodes: [NativeUINode]
    let mode: String
    let onQueryCallbackId: Int
    let debounceMs: Int

    @State private var query: String = ""
    @State private var debounceTask: Task<Void, Never>? = nil

    /// Plain-text representation of an item for client-side filtering.
    /// Element-kind items aren't filtered (no generic text extraction)
    /// — they always pass through.
    private func searchableText(for item: NativeUINode) -> String? {
        let kind = item.props.getString("kind", default: "")
        switch kind {
        case "string":
            return item.props.getString("value", default: "")
        case "object":
            let title = item.props.getString("title", default: "")
            let subtitle = item.props.getString("subtitle", default: "")
            return subtitle.isEmpty ? title : "\(title) \(subtitle)"
        default:
            return nil
        }
    }

    private var displayed: [NativeUINode] {
        if mode == "dynamic" {
            return itemNodes
        }
        if query.isEmpty {
            return itemNodes
        }
        let q = query
        return itemNodes.filter { item in
            guard let text = searchableText(for: item) else { return true }
            return text.localizedCaseInsensitiveContains(q)
        }
    }

    var body: some View {
        // List + overlay rather than putting the empty-state inside the
        // List. A `Label` row inside a List visually resembles a search
        // field — confusing alongside the real `.searchable` at the
        // bottom. `ContentUnavailableView` centers the icon above the
        // text (Apple's canonical empty state, see Photos / Music /
        // Mail) which reads unambiguously as a placeholder.
        List(displayed) { item in
            rowView(for: item)
        }
        .overlay {
            if itemNodes.isEmpty {
                if mode == "dynamic" {
                    if query.isEmpty {
                        ContentUnavailableView(
                            "Type to search",
                            systemImage: "magnifyingglass"
                        )
                    } else {
                        ContentUnavailableView.search(text: query)
                    }
                } else {
                    ContentUnavailableView(
                        "Nothing to search here",
                        systemImage: "magnifyingglass",
                        description: Text("This screen hasn't declared `searchItems()` or `onSearchQuery()`.")
                    )
                }
            }
        }
        .searchable(text: $query, prompt: placeholder.isEmpty ? "Search" : placeholder)
        .onChange(of: query) { _, newValue in
            guard mode == "dynamic", onQueryCallbackId != 0 else { return }
            debounceTask?.cancel()
            if debounceMs <= 0 {
                NativeElementBridge.sendTextChangeEvent(onQueryCallbackId, nodeId: 0, text: newValue)
                return
            }
            debounceTask = Task { @MainActor in
                try? await Task.sleep(nanoseconds: UInt64(debounceMs) * 1_000_000)
                if Task.isCancelled { return }
                NativeElementBridge.sendTextChangeEvent(onQueryCallbackId, nodeId: 0, text: newValue)
            }
        }
        .navigationTitle("Search")
        .navigationBarTitleDisplayMode(.inline)
    }

    @ViewBuilder
    private func rowView(for item: NativeUINode) -> some View {
        let kind = item.props.getString("kind", default: "")
        switch kind {
        case "string":
            Text(item.props.getString("value", default: ""))
        case "object":
            ObjectResultRow(
                title: item.props.getString("title", default: ""),
                subtitle: emptyToNil(item.props.getString("subtitle", default: "")),
                leading: emptyToNil(item.props.getString("leading", default: "")),
                trailing: emptyToNil(item.props.getString("trailing", default: "")),
                tapCallback: Int(item.onPress),
                tapNodeId: item.id
            )
        case "element":
            // The user-provided Element comes through as the first
            // child of the `search_item` wrapper. Defer to NodeView so
            // any onPress / styling on the element works as normal.
            if let inner = item.children.first {
                NodeView(node: inner)
            }
        default:
            // Forward-compat: ignore unknown item kinds rather than crash.
            EmptyView()
        }
    }

    private func emptyToNil(_ s: String) -> String? { s.isEmpty ? nil : s }
}

/// Standard search-result row for object-kind items. Apple's
/// People-List style: optional leading SF Symbol, title + optional
/// subtitle, optional trailing chevron, tappable surface.
private struct ObjectResultRow: View {
    let title: String
    let subtitle: String?
    let leading: String?
    let trailing: String?
    let tapCallback: Int
    let tapNodeId: Int

    var body: some View {
        Button {
            guard tapCallback != 0 else { return }
            NativeElementBridge.sendPressEvent(tapCallback, nodeId: tapNodeId)
        } label: {
            HStack(spacing: 12) {
                // Route icon names through `getIconForName` so cross-
                // platform names like `article` (a Material icon, not
                // an SF Symbol) get mapped to their SF equivalent.
                // Using the raw name with `Image(systemName:)` for an
                // unknown symbol leaves a 24pt empty gap on the left —
                // that's what the visible padding was.
                if let leading, !leading.isEmpty {
                    Image(systemName: getIconForName(leading))
                        .foregroundStyle(.secondary)
                        .frame(width: 24)
                }
                VStack(alignment: .leading, spacing: 2) {
                    Text(title)
                        .foregroundStyle(.primary)
                    if let subtitle, !subtitle.isEmpty {
                        Text(subtitle)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                }
                Spacer(minLength: 0)
                if let trailing, !trailing.isEmpty {
                    Image(systemName: getIconForName(trailing))
                        .foregroundStyle(.tertiary)
                }
            }
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(tapCallback == 0)
    }
}
