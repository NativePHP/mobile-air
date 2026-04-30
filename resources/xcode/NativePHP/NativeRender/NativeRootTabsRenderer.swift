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

    var body: some View {
        let tabs = node.children.filter { $0.type == "bottom_nav_item" }
        let accessory = node.children.first { $0.type == "tab_accessory" }
        // The screen content is whatever isn't a tab item, action, or
        // accessory. (NavBar actions, when both bars exist, are folded
        // into the tree; we currently ignore them here.)
        let screenContent = node.children.first {
            $0.type != "bottom_nav_item"
                && $0.type != "top_bar_action"
                && $0.type != "tab_accessory"
        }
        let minimizeOnScroll = node.props.getBool("minimize_on_scroll")

        // Activeness flows from `BottomNavItem.active` (TabBar::highlight()
        // picked the matching one based on current URI).
        let activeTabIdx = tabs.firstIndex { $0.props.getBool("active") } ?? 0

        let activeArgb = node.props.getColor("active_color", default: 0)
        let activeColor: Color = activeArgb != 0 ? Color(argb: activeArgb) : .accentColor
        let bgArgb = node.props.getColor("background_color", default: 0)
        let isDark = node.props.getBool("dark")
        let hasExplicitBg = bgArgb != 0

        // Folded NavBar config (set when the layout supplies both bars).
        // Used to render a top toolbar inside each tab's NavigationStack.
        let navBack = node.props.getBool("nav_back")
        let navTitleText = node.props.getString("nav_title", default: "")
        let navTextArgb = node.props.getColor("nav_text_color", default: 0)
        let navBgArgb = node.props.getColor("nav_background_color", default: 0)
        let hasNavBar = navBack || !navTitleText.isEmpty

        TabView(selection: $selection) {
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
                Tab(value: idx, role: isSearch ? .search : nil) {
                    tabContentWrapped(
                        idx: idx,
                        activeIdx: activeTabIdx,
                        screenContent: screenContent,
                        hasNavBar: hasNavBar,
                        title: navTitleText,
                        showBack: navBack,
                        textArgb: navTextArgb,
                        bgArgb: navBgArgb
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
            selection = activeTabIdx
        }
        .onChange(of: activeTabIdx) { newIdx in
            // PHP swapped the active tab — sync our SwiftUI selection.
            if selection != newIdx {
                selection = newIdx
            }
        }
        .onChange(of: selection) { newIdx in
            // User tapped a tab — fire its press handler so PHP runs
            // the BottomNavItem-auto-wired `replace` navigation.
            // We don't fire when selection matches activeTabIdx (the
            // PHP-driven sync path).
            guard newIdx != activeTabIdx, newIdx < tabs.count else { return }
            let tab = tabs[newIdx]
            if tab.onPress != 0 {
                NativeElementBridge.sendPressEvent(tab.onPress, nodeId: tab.id)
            }
        }
    }

    @ViewBuilder
    private func tabContent(idx: Int, activeIdx: Int, screenContent: NativeUINode?) -> some View {
        if idx == activeIdx, let content = screenContent {
            NodeView(node: content)
        } else {
            Color.clear
        }
    }

    /// When the layout supplies both a NavBar and TabBar, each tab is
    /// hosted inside its own `NavigationStack` so the folded `nav_*`
    /// props can render as a real iOS toolbar (title + back chevron).
    /// Without the wrapper, there's no top bar at all and the user has
    /// no way to leave the tabs root.
    @ViewBuilder
    private func tabContentWrapped(
        idx: Int,
        activeIdx: Int,
        screenContent: NativeUINode?,
        hasNavBar: Bool,
        title: String,
        showBack: Bool,
        textArgb: Int,
        bgArgb: Int
    ) -> some View {
        if hasNavBar {
            NavigationStack {
                tabRoot(
                    idx: idx,
                    activeIdx: activeIdx,
                    screenContent: screenContent,
                    title: title,
                    showBack: showBack,
                    textArgb: textArgb,
                    bgArgb: bgArgb
                )
            }
        } else {
            tabContent(idx: idx, activeIdx: activeIdx, screenContent: screenContent)
        }
    }

    /// Inner of `tabContentWrapped` — broken out to keep each expression
    /// small enough for the SwiftUI type-checker. The chained toolbar /
    /// navigationTitle / toolbarBackground modifiers blow past the
    /// "unable to type-check in reasonable time" threshold when inlined
    /// alongside the conditional `NavigationStack` branch above.
    @ViewBuilder
    private func tabRoot(
        idx: Int,
        activeIdx: Int,
        screenContent: NativeUINode?,
        title: String,
        showBack: Bool,
        textArgb: Int,
        bgArgb: Int
    ) -> some View {
        let textColor: Color = textArgb != 0 ? Color(argb: textArgb) : .primary
        let hasExplicitBg = bgArgb != 0
        let bgColor: Color = hasExplicitBg ? Color(argb: bgArgb) : .clear
        let bgVisibility: Visibility = hasExplicitBg ? .visible : .automatic

        // Pick a toolbar color scheme so the system can resolve title +
        // chevron colors consistently on first frame (avoids the
        // black→white→black flicker on tab switch when the bar has a
        // dark explicit background). Heuristic: if the dev supplied a
        // light text color, the bar is meant to be dark → `.dark`
        // scheme renders light controls. Else leave it nil so the
        // system decides.
        let toolbarScheme: ColorScheme? = {
            guard textArgb != 0 else { return nil }
            let r = Double((textArgb >> 16) & 0xFF) / 255.0
            let g = Double((textArgb >>  8) & 0xFF) / 255.0
            let b = Double( textArgb        & 0xFF) / 255.0
            let luminance = 0.299 * r + 0.587 * g + 0.114 * b
            return luminance > 0.5 ? .dark : .light
        }()

        tabContent(idx: idx, activeIdx: activeIdx, screenContent: screenContent)
            // Cross-fade the content area whenever the active tab
            // changes — `tabContent`'s ViewBuilder already swaps between
            // `NodeView` (active) and `Color.clear` (inactive), so an
            // animation on `activeIdx` is enough to make SwiftUI's
            // default opacity transition fire on appear / disappear.
            .animation(.easeInOut(duration: 0.18), value: activeIdx)
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Explicit `id:` on each ToolbarItem keeps SwiftUI's
                // identity stable across publishes — without it,
                // toolbar items are matched structurally per body run,
                // and a tab-switch publish that re-evaluates the body
                // can briefly drop the back-chevron's tinted Liquid
                // Glass capsule before re-applying it.
                ToolbarItem(id: "back", placement: .topBarLeading) {
                    if showBack {
                        Button {
                            NativeElementBridge.sendSystemBackEvent()
                        } label: {
                            Image(systemName: "chevron.backward")
                                .font(.system(size: 17, weight: .semibold))
                        }
                    }
                }
            }
            // Use a toolbar color scheme to keep the title + chevron
            // colors stable from the first frame — without this, the
            // title falls back to the system default and visibly
            // flickers black→white on tab switch when the bar has a
            // dark explicit background. (`toolbarForegroundStyle` is
            // unavailable on iOS, so we fall back to `colorScheme`.)
            .toolbarColorScheme(toolbarScheme, for: .navigationBar)
            .modifier(ExplicitBarBackgroundModifier(
                argb: bgArgb,
                placement: .navigationBar
            ))
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
