package com.nativephp.mobile.ui.nativerender

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsetsSides
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.only
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarDefaults
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.ScaffoldDefaults
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.setValue
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.saveable.rememberSaveableStateHolder
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.nativephp.mobile.ui.MaterialIcon
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Compose port of iOS's `NativeRootTabsRenderer`. Renders the
 * `native_root_tabs` sentinel via Material 3 `Scaffold` +
 * `NavigationBar`, with an optional inner `TopAppBar` when the layout
 * supplies both bars.
 *
 * iOS 26-only modifiers (`Tab(role: .search)` floating Liquid Glass
 * capsule, `tabBarMinimizeBehavior`, `tabViewBottomAccessory`) have no
 * exact Android equivalent. The search-role tab renders as a regular
 * tab with a magnifying-glass icon; tapping it swaps the content area
 * to an `AndroidSearchTabRoot` that mirrors iOS's `NativeSearchTabRoot`
 * — same three-kind item dispatch (string / object / element), same
 * static / dynamic filter modes.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NativeRootTabsRenderer(node: NativeUINode, modifier: Modifier = Modifier) {
    val tabs = node.children.filter { it.type == "bottom_nav_item" }
    val accessory = node.children.firstOrNull { it.type == "tab_accessory" }
    // NavBar actions folded onto the tabs sentinel by `wrapWithNativeChrome`
    // when both bars are present — render trailing on the top bar.
    val actions = node.children.filter { it.type == "top_bar_action" }
    // Custom principal-slot content (logo / titleView) — replaces the
    // string title when present.
    val titleNode = node.children.firstOrNull { it.type == "top_bar_title" }
    // Search items live as `search_item` children on the tabs root —
    // PHP can't carry the mixed string/object/element shapes through a
    // prop, so they ride the tree wire format instead.
    val searchItemNodes = node.children.filter { it.type == "search_item" }
    val screenContent = node.children.firstOrNull {
        it.type != "bottom_nav_item"
            && it.type != "top_bar_action"
            && it.type != "tab_accessory"
            && it.type != "search_item"
            && it.type != "top_bar_title"
            && it.type != "bottom_bar"
            && !NativeRootHostRegistry.consumes(it.type)
    }
    // Bottom-pinned content (inline `<native:bottom-bar>` or layout
    // `bottomBar()`). Rendered below the screen content and above the tab
    // bar; the root `.imePadding()` lifts it over the keyboard — the Android
    // analogue of iOS's `.safeAreaInset(.bottom)`.
    val bottomBarNode = node.children.firstOrNull { it.type == "bottom_bar" }

    // Activeness flows from `BottomNavItem.active` (TabBar::highlight() set it).
    val activeTabIdx = tabs.indexOfFirst { it.props.getBool("active") }.coerceAtLeast(0)

    // Stable per-tab identity for the in-flight-navigation trackers below —
    // FlatBuffer node ids regenerate every publish, so use the PHP-side slug.
    val tabIds = tabs.mapIndexed { idx, tab ->
        tab.props.getString("id", "").ifEmpty { "tab_$idx" }
    }
    val activeTabId = tabIds.getOrElse(activeTabIdx) { "" }

    // Local selection mirrors PHP's active flag, but also responds to taps so
    // the bar UI updates instantly while we wait for PHP to republish.
    var selection by remember { mutableIntStateOf(activeTabIdx) }

    // Rapid re-tap trackers, mirroring iOS's NativeRootTabsRenderer (see
    // "Rapid re-taps" in its header comment). Tab navigations aren't
    // cancellable PHP-side, so a tap sequence Home → Contacts (slow render) →
    // Home used to settle on Contacts: the Home re-tap was swallowed
    // (selection == active tab) and the late Contacts publish then yanked
    // `selection` back. `pendingTabId` is the user's latest tap while its
    // `replace` navigation is in flight; tabs it displaced go in
    // `supersededTabIds`, whose late publishes must not move `selection`.
    // (The content pane still keys off `activeTabIdx`, so it can show the
    // superseded screen for the beat until the re-fired press republishes —
    // Android keeps no per-tab tree cache to hold the selected tab's content.)
    var pendingTabId by remember { mutableStateOf<String?>(null) }
    val supersededTabIds = remember { mutableSetOf<String>() }

    // Scope for the native scroll-to-top animation a bottom-tab
    // reselect launches — cancelled automatically when this renderer
    // leaves composition, which also cancels an in-flight scroll.
    val scope = rememberCoroutineScope()

    // True while a reselect scroll-to-top is animating. Re-taps during
    // the scroll coalesce into the running scroll+refresh — one scroll,
    // one callback (see decideBottomTabTap).
    var scrollInFlight by remember { mutableStateOf(false) }

    // Ownership for the in-flight reselect scroll. `scrollJob` cancels
    // the animation the moment a cross-tab tap navigates away, and the
    // generation keeps a CANCELLED job's `finally` from clearing the
    // flag of a scroll a later tap started — only the newest
    // generation may reset `scrollInFlight`.
    var scrollJob by remember { mutableStateOf<Job?>(null) }
    var scrollGeneration by remember { mutableIntStateOf(0) }

    // Latest-tree mirrors for the post-scroll reselect gate. FlatBuffer
    // nodes are per-publish snapshots: the `tab` / `activeTabIdx` values
    // an onClick captures belong to the tree that was on screen WHEN the
    // user tapped. The scroll-then-refresh coroutine must instead verify
    // against — and fire into — the tree that is live AFTER the scroll
    // settles, so keep the newest tabs/ids where the coroutine can read
    // them (SideEffect runs after every successful composition; nothing
    // in composition reads these back, so no recomposition loop).
    var latestTabs by remember { mutableStateOf(tabs) }
    var latestTabIds by remember { mutableStateOf(tabIds) }
    var latestActiveTabId by remember { mutableStateOf(activeTabId) }
    SideEffect {
        latestTabs = tabs
        latestTabIds = tabIds
        latestActiveTabId = activeTabId
    }

    LaunchedEffect(activeTabIdx) {
        val pending = pendingTabId
        if (pending != null && activeTabId != pending) {
            val pendingTab = tabs.getOrNull(tabIds.indexOf(pending))
            if (supersededTabIds.contains(activeTabId) && pendingTab != null && pendingTab.onPress != 0) {
                // Stale publish: a navigation the user superseded by tapping
                // again finished late. Hold `selection` where the user put it
                // and re-fire the pending tab's press — the tap-time press
                // carried the previous tree's callback id, which the component
                // that just published doesn't own, so PHP dropped it. This
                // tree's id is live.
                NativeElementBridge.sendPressEvent(pendingTab.onPress, pendingTab.id)
                return@LaunchedEffect
            }
            // Unrelated navigation won the race (programmatic replace,
            // mount() redirect, or the pending tab left the layout) — accept
            // the publish as authoritative.
        }
        pendingTabId = null
        supersededTabIds.clear()
        if (selection != activeTabIdx) selection = activeTabIdx
    }

    val activeColorArgb = node.props.getColor("active_color", 0)
    val textColorArgb = node.props.getColor("text_color", 0)
    val bgArgb = node.props.getColor("background_color", 0)

    // Per-screen explicit signal from PHP (`$hidesTabBar` shortcut or
    // `tabBarOptions()->hidden()` builder, folded onto the sentinel).
    val hideTabBar = node.props.getBool("hide_tab_bar")

    // Per-screen nav-bar opt-out (`$hidesNavBar` /
    // `navigationOptions()->hidden()`) — suppresses the TopAppBar for
    // this screen only; the Scaffold padding follows automatically.
    val hideNavBar = node.props.getBool("hide_nav_bar")

    // Active screen URI — used as part of the inner AnimatedContent's
    // key so within-tab navigation (chats list → chat detail) animates
    // even when the tab index doesn't change.
    val currentUri = node.props.getString("current_uri", "")

    // Folded NavBar config — present when the layout supplied both bars.
    val navBack = node.props.getBool("nav_back")
    val navTitle = node.props.getString("nav_title", "")
    val navSubtitle = node.props.getString("nav_subtitle", "")
    val navBgArgb = node.props.getColor("nav_background_color", 0)
    val navTextArgb = node.props.getColor("nav_text_color", 0)

    // a bar carrying only actions must still render.
    val hasNavActions = node.children.any { it.type == "top_bar_action" }
    val hasNavBar = navBack || navTitle.isNotEmpty() || titleNode != null || hasNavActions
    val reservesNavigationBarSlot = NativeRootHostRegistry.reservesNavigationBarSlot(node)

    // Per-layout / per-bar chrome fonts, resolved through the plugin seam.
    // `font_name` = the tab bar's font; `nav_font_name` = the folded nav
    // bar's font (falls back to the tab bar's). Null → inherit the ambient
    // typography unchanged.
    val chromeFontFamily = com.nativephp.mobile.ui.NativeUIThemeProvider
        .resolveChromeFontFamily(node.props.getString("font_name", ""))
    val navChromeFontFamily = com.nativephp.mobile.ui.NativeUIThemeProvider
        .resolveChromeFontFamily(node.props.getString("nav_font_name", ""))
        ?: chromeFontFamily

    // Search-tab config — search-role tab plus its items live as children.
    // Show the search-role tab when any of:
    //   - dynamic mode (results come on demand; tab must be tappable
    //     even with zero items since that's the pre-typing state)
    //   - the active screen declared static items
    //   - the user is currently on the search tab (sticky — keeps the
    //     tab from yanking itself out the instant they tap it)
    val searchTabIdx = tabs.indexOfFirst { it.props.getBool("search") }
    val searchPlaceholder = node.props.getString("nav_search_placeholder", "")
    val searchMode = node.props.getString("nav_search_mode", "static")
    val searchDebounceMs = node.props.getInt("nav_search_debounce_ms", 250)
    val searchOnQueryCb = node.props.getCallbackId("nav_search_on_query")

    val shouldShowSearchTab = searchMode == "dynamic"
        || searchItemNodes.isNotEmpty()
        || (searchTabIdx >= 0 && selection == searchTabIdx)

    val visibleTabEntries = tabs.mapIndexedNotNull { idx, tab ->
        if (idx == searchTabIdx && !shouldShowSearchTab) null else idx to tab
    }

    val presentationIdentifier = node.props.getString("tab_presentation_id", "")
    val presentationConfigurationJson = node.props.getString("tab_presentation_configuration", "")
    val requestsCustomPresentation = presentationIdentifier.isNotEmpty() && presentationIdentifier != "system"
    val presentationConfiguration = remember(requestsCustomPresentation, presentationConfigurationJson) {
        presentationConfigurationJson.takeIf { requestsCustomPresentation && it.isNotEmpty() }
            ?.let(NativeTabPresentationConfiguration::parse)
    }
    val customPresenter = NativeTabPresentationRegistry.resolve(
        identifier = presentationIdentifier,
        configuration = presentationConfiguration,
    )

    LaunchedEffect(presentationIdentifier, presentationConfigurationJson, customPresenter) {
        if (requestsCustomPresentation && customPresenter == null) {
            val reason = if (presentationConfiguration == null) "invalid configuration" else "not registered"
            android.util.Log.w(
                "NativePHP",
                "Tab presenter '$presentationIdentifier' $reason; using the system tab bar.",
            )
        }
    }

    val activateTabAt: (Int) -> Unit = activate@{ actualIdx ->
        if (actualIdx < 0 || actualIdx >= tabs.size) return@activate
        val tab = tabs[actualIdx]
        val isSearchTab = tab.props.getBool("search")
        // Local selection updates instantly so
        // the ripple / selection indicator
        // responds; for the search tab, no PHP
        // navigation fires (it's an iOS-/Android-
        // side overlay). For regular tabs, the
        // BottomNavItem-auto-wired `replace`
        // press handler fires here. A tap back
        // to the active tab normally needs no
        // press — except while another tab's
        // navigation is in flight: PHP is
        // already navigating away and must be
        // told to come back. A re-tap of the
        // settled active tab dispatches the
        // tab's optional `on_reselect` callback
        // (Tab::onReselect) instead — one tap:
        // at exact top it fires immediately,
        // scrolled down it scrolls to top first
        // and fires once the scroll completes
        // (see decideBottomTabTap and
        // shouldFireReselectAfterScroll).
        selection = actualIdx
        val reselectCbId = tab.props.getCallbackId("on_reselect")
        val scrollTargetKey = tab.props.getString("reselect_scroll_target", "")
        val scrollTarget =
            if (scrollTargetKey.isEmpty()) null
            else NativeScrollTargetRegistry.get(scrollTargetKey)
        when (decideBottomTabTap(
            actualIdx = actualIdx,
            activeTabIdx = activeTabIdx,
            hasPendingNavigation = pendingTabId != null,
            isSearchTab = isSearchTab,
            urlPressId = tab.onPress,
            reselectCbId = reselectCbId,
            scrollTargetKey = scrollTargetKey,
            scrollTarget = scrollTarget,
            scrollInFlight = scrollInFlight,
        )) {
            BottomTabTapAction.NavigateUrl -> {
                if (tab.props.getString("url", "").isNotEmpty()) {
                    val tappedId = tabIds.getOrElse(actualIdx) { "" }
                    pendingTabId?.let { prev ->
                        if (prev != tappedId) supersededTabIds.add(prev)
                    }
                    pendingTabId = tappedId
                }
                // Navigation away abandons the feed:
                // stop animating its scroll now
                // rather than letting it run out
                // under the incoming screen.
                scrollJob?.cancel()
                NativeElementBridge.sendPressEvent(tab.onPress, tab.id)
            }
            BottomTabTapAction.FireReselectCallback ->
                // Same PRESS transport as the URL
                // press; the callback id alone
                // routes to the reselect handler.
                NativeElementBridge.sendPressEvent(reselectCbId, tab.id)
            BottomTabTapAction.ScrollThenReselect -> {
                val target = scrollTarget
                val tappedTabId = tabIds.getOrElse(actualIdx) { "" }
                if (target != null) {
                    scrollInFlight = true
                    // This scroll owns the flag until
                    // a newer scroll bumps the
                    // generation — a cancelled job's
                    // finally then leaves the flag
                    // (and any newer scroll) alone.
                    scrollGeneration++
                    val generation = scrollGeneration
                    scrollJob = scope.launch {
                        try {
                            target.scrollToTop()
                            // One-tap contract: refresh
                            // after the scroll settles —
                            // but only if the world still
                            // matches the tap. A canceled
                            // scroll never gets here; a
                            // tab switch, a navigation
                            // still in flight
                            // (pendingTabId, read LIVE —
                            // the published tree lags the
                            // press), a same-key entry
                            // replacement, or a tree that
                            // switched target keys must
                            // not refresh the wrong or an
                            // abandoned view. The fire
                            // uses ids re-read from the
                            // CURRENT tree, never the
                            // tap-time capture.
                            val freshTab = latestTabs.getOrNull(
                                latestTabIds.indexOf(tappedTabId),
                            )
                            val freshCbId = freshTab?.props?.getCallbackId("on_reselect") ?: 0
                            if (shouldFireReselectAfterScroll(
                                    activeTabStillTapped = tappedTabId.isNotEmpty()
                                        && latestActiveTabId == tappedTabId
                                        && selection == actualIdx,
                                    noPendingNavigation = pendingTabId == null,
                                    scrollEntryUnchanged = NativeScrollTargetRegistry.get(scrollTargetKey) === target,
                                    tabStillDeclaresTarget = freshTab?.props?.getString("reselect_scroll_target", "") == scrollTargetKey,
                                    freshReselectCallbackId = freshCbId,
                                )
                            ) {
                                NativeElementBridge.sendPressEvent(freshCbId, freshTab?.id ?: tab.id)
                            }
                        } finally {
                            if (scrollGeneration == generation) {
                                scrollInFlight = false
                            }
                        }
                    }
                }
            }
            BottomTabTapAction.Noop -> {}
        }

    }

    val descriptorEntries = if (customPresenter != null) visibleTabEntries.map { (actualIdx, tab) ->
        val label = tab.props.getString("label", "")
        val url = tab.props.getString("url", "")
        actualIdx to NativeTabDescriptor(
            id = nativeTabPresentationIdentifier(tabIds.getOrElse(actualIdx) { "tab_$actualIdx" }, actualIdx),
            label = label,
            accessibilityLabel = label,
            icon = tab.props.getString("icon", "circle"),
            badge = tab.props.getString("badge", "").ifEmpty { null },
            showsNews = tab.props.getBool("news"),
            role = when {
                tab.props.getBool("search") -> NativeTabRole.SEARCH
                url.isEmpty() -> NativeTabRole.ACTION
                else -> NativeTabRole.NAVIGATION
            },
        )
    } else emptyList()
    val tabDescriptors = descriptorEntries.map { it.second }
    val descriptorIndexByIdentifier = descriptorEntries.associate { (index, descriptor) ->
        descriptor.id to index
    }
    val activatePresentedTab: (String) -> Unit = { identifier ->
        descriptorIndexByIdentifier[identifier]?.let(activateTabAt)
    }

    val isOnSearchTab = searchTabIdx >= 0 && selection == searchTabIdx

    // A navigation changed the active URI (e.g. the user tapped a search
    // result, which `navigate`s to its route). If we're parked on the search
    // tab, snap back to the active tab so the pushed screen is revealed and the
    // search UI dismisses. The `LaunchedEffect(activeTabIdx)` sync above is not
    // enough: when the result's route is owned by the same tab that was active
    // when the search ran (e.g. searching the docs from the Docs tab, then
    // opening a docs page), `activeTabIdx` never changes, so that effect never
    // fires — and the user is stranded on the search tab, which now shows an
    // empty result set because the new publish carries no search items.
    LaunchedEffect(currentUri) {
        if (searchTabIdx >= 0 && selection == searchTabIdx && selection != activeTabIdx) {
            selection = activeTabIdx
        }
    }

    // System back: defer to PHP (the tabs root has nowhere to pop within Compose).
    BackHandler(enabled = true) {
        NativeElementBridge.sendSystemBackEvent()
    }

    Scaffold(
        // A persistent background layer (map behind the app) needs the
        // content canvas transparent; otherwise keep the themed surface.
        containerColor = if (LocalBackgroundLayerPresent.current) {
            androidx.compose.ui.graphics.Color.Transparent
        } else {
            MaterialTheme.colorScheme.background
        },
        topBar = {
            if (hasNavBar && !hideNavBar && !isOnSearchTab) {
                TopAppBar(
                    title = {
                        if (titleNode != null) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                titleNode.children.forEach { child -> NodeView(node = child) }
                            }
                        } else if (navSubtitle.isNotEmpty()) {
                            Column {
                                Text(navTitle, fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.titleMedium, fontFamily = navChromeFontFamily)
                                Text(navSubtitle, style = MaterialTheme.typography.labelSmall, fontFamily = navChromeFontFamily)
                            }
                        } else {
                            Text(navTitle, fontWeight = FontWeight.SemiBold, fontFamily = navChromeFontFamily)
                        }
                    },
                    navigationIcon = {
                        if (navBack) {
                            IconButton(onClick = { NativeElementBridge.sendSystemBackEvent() }) {
                                // Font glyphs don't auto-mirror like AutoMirrored ImageVectors; flip for RTL.
                                val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
                                MaterialIcon(
                                    name = "arrow_back",
                                    contentDescription = "Back",
                                    modifier = if (rtl) Modifier.scale(scaleX = -1f, scaleY = 1f) else Modifier
                                )
                            }
                        } else if (reservesNavigationBarSlot) {
                            Spacer(modifier = Modifier.width(48.dp))
                        }
                    },
                    actions = {
                        actions.forEach { action -> TopBarActionView(action) }
                    },
                    colors = if (navBgArgb != 0) {
                        val bg = argbToComposeColor(navBgArgb)
                        val fg = if (navTextArgb != 0) argbToComposeColor(navTextArgb) else Color.White
                        TopAppBarDefaults.topAppBarColors(
                            containerColor = bg,
                            titleContentColor = fg,
                            navigationIconContentColor = fg,
                            actionIconContentColor = fg
                        )
                    } else {
                        TopAppBarDefaults.topAppBarColors()
                    }
                )
            }
        },
        bottomBar = bottomBar@{
            // Mirror iOS's `HideTabBarOnPushModifier`: pushed-detail
            // screens hide the tab strip entirely (iMessage / Music /
            // Mail pattern). Driven by the `hide_tab_bar` wire prop set
            // by the screen via `$hidesTabBar` or
            // `tabBarOptions()->hidden()`.
            if (hideTabBar) return@bottomBar

            Column {
                // Persistent accessory pinned above the NavigationBar
                // (Apple Music / Spotify / YouTube Music MiniPlayer
                // pattern). Best-effort idiomatic M3 — tonal Surface at
                // `surfaceContainerHigh` with rounded top corners.
                accessory?.children?.firstOrNull()?.let { acc ->
                    Surface(
                        color = MaterialTheme.colorScheme.surfaceContainerHigh,
                        shape = RoundedCornerShape(topStart = 16.dp, topEnd = 16.dp),
                        tonalElevation = 3.dp,
                        shadowElevation = 4.dp,
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        NodeView(node = acc)
                    }
                }

                if (customPresenter != null && presentationConfiguration != null) {
                    customPresenter(
                        NativeTabPresentationContext(
                            tabs = tabDescriptors,
                            selectedTabIdentifier = nativeTabPresentationIdentifier(
                                tabIds.getOrElse(selection) { activeTabId },
                                selection,
                            ),
                            configuration = presentationConfiguration,
                            isHidden = false,
                            activation = activatePresentedTab,
                        ),
                    )
                } else NavigationBar(
                    containerColor = if (bgArgb != 0)
                        argbToComposeColor(bgArgb)
                    else
                        NavigationBarDefaults.containerColor
                ) {
                    visibleTabEntries.forEach { (actualIdx, tab) ->
                        val label = tab.props.getString("label", "")
                        val icon = tab.props.getString("icon", "circle")
                        val badge = tab.props.getString("badge", "")
                        val news = tab.props.getBool("news")
                        NavigationBarItem(
                            selected = actualIdx == selection,
                            onClick = { activateTabAt(actualIdx) },
                            icon = {
                                if (badge.isNotEmpty() || news) {
                                    BadgedBox(badge = {
                                        Badge {
                                            if (badge.isNotEmpty()) Text(badge, fontFamily = chromeFontFamily)
                                        }
                                    }) {
                                        MaterialIcon(name = icon, contentDescription = label)
                                    }
                                } else {
                                    MaterialIcon(name = icon, contentDescription = label)
                                }
                            },
                            label = { Text(label, fontFamily = chromeFontFamily) },
                            // `active_color` (TabBar::activeColor) tints the selected
                            // tab; `text_color` (TabBar::textColor) tints the inactive
                            // icons + labels. Layer both onto the M3 defaults via copy()
                            // so anything PHP doesn't supply keeps the themed default.
                            colors = run {
                                var c = NavigationBarItemDefaults.colors()
                                if (activeColorArgb != 0) {
                                    val active = argbToComposeColor(activeColorArgb)
                                    c = c.copy(
                                        selectedIconColor = active,
                                        selectedTextColor = active,
                                        selectedIndicatorColor = active.copy(alpha = 0.16f),
                                    )
                                }
                                if (textColorArgb != 0) {
                                    val inactive = argbToComposeColor(textColorArgb)
                                    c = c.copy(
                                        unselectedIconColor = inactive,
                                        unselectedTextColor = inactive,
                                    )
                                }
                                c
                            }
                        )
                    }
                }
            }
        },
        // With the TopAppBar hidden per-screen, Scaffold's default
        // contentWindowInsets would turn the status-bar inset into hard top
        // padding — a dead strip nothing can draw behind. Drop the top side
        // so hidden-bar screens are genuinely edge-to-edge (mirrors
        // NativeRootStackRenderer). Layouts with no nav bar at all keep the
        // default insets — that's long-standing behavior for bar-less tabs.
        // `!isOnSearchTab`: the search tab swaps the whole content area for
        // the search UI WITHOUT a new publish, so hideNavBar still reflects
        // the underlying screen (e.g. a hidden-bar home). Dropping the top
        // inset there put the search field behind the status bar / camera
        // cutout, where it can't be tapped — the search UI always needs it.
        contentWindowInsets = if (hasNavBar && hideNavBar && !isOnSearchTab) {
            ScaffoldDefaults.contentWindowInsets
                .only(WindowInsetsSides.Horizontal + WindowInsetsSides.Bottom)
        } else {
            ScaffoldDefaults.contentWindowInsets
        },
        modifier = modifier.fillMaxSize()
    ) { padding ->
        // The search-role tab swaps the entire content area to the
        // Android equivalent of iOS's NativeSearchTabRoot — a search
        // TextField above a results list, with `search_item` children
        // dispatched by kind. Other tabs render their PHP-published
        // content normally.
        if (isOnSearchTab) {
            AndroidSearchTabRoot(
                placeholder = searchPlaceholder,
                itemNodes = searchItemNodes,
                mode = searchMode,
                onQueryCallbackId = searchOnQueryCb,
                debounceMs = searchDebounceMs,
                modifier = Modifier.fillMaxSize().padding(padding),
            )
            return@Scaffold
        }

        // Animate tab switch (cross-fade) vs within-tab push (PHP-
        // signaled transition). iOS gets within-tab transitions for
        // free via per-tab NavigationStack; Compose lacks that, so we
        // drive both off `pendingTransition` and the (tabIdx, uri) key.
        val pendingTransition by NativeUIBridge.pendingTransition

        // Per-mount saveable panes. PHP's `screen_tokens` (bottom-up,
        // top screen last) is the AUTHORITATIVE liveness list; tokens
        // leaving it are exactly the mounts that died (pop/replace/
        // unwind) and their saved state is dropped, while buried live
        // mounts keep theirs — so a popTo back to the feed restores the
        // SAME feed's scroll state (ListState/PagerState are saveable
        // internally and scope to the provider key). The ledger itself
        // is rememberSaveable: after a process restart Android hands
        // back the old bundles, the fresh process's all-new random
        // tokens evict them on the first frame, and nothing stale is
        // ever restored into an unrelated screen.
        val screenTokens = node.props.getStringList("screen_tokens")
        val saveableHolder = rememberSaveableStateHolder()
        val savedTokenLedger = rememberSaveable { mutableStateOf(ArrayList<String>()) }
        SideEffect {
            if (screenTokens.isNotEmpty()) {
                ScreenTokens.evicted(savedTokenLedger.value, screenTokens).forEach { saveableHolder.removeState(it) }
                savedTokenLedger.value = ArrayList(screenTokens)
            }
        }

        // Pane identity is the TOP screen token — screen-MOUNT identity,
        // never URI/node id (per-frame wire ids regenerate; a fresh
        // REPLACE of the same URI must not alias the previous mount's
        // pane or its saved state). Old frames without tokens keep the
        // legacy (tab|uri) key and skip saveable scoping entirely. The
        // tab index rides along as transition metadata only.
        val topToken = screenTokens.lastOrNull()
        val targetKey = topToken ?: "$activeTabIdx|$currentUri"

        // Context cancel at the screen-ownership boundary: the VISIBLE
        // mount changed (top token) — a row push, a programmatic
        // replace, a deep link, not just a bottom cross-tab tap. A
        // feed reselect scroll still animating when a child pushes
        // (and the user returns before it ends) must not complete and
        // fire the old callback: after A→B→A the gate's world checks
        // legitimately read "same A" again, and `pendingTabId` only
        // covers BOTTOM tab navigation. The generation guard keeps the
        // cancelled job's finally from touching a newer scroll.
        LaunchedEffect(topToken) {
            scrollJob?.cancel()
        }

        // Scaffold padding follows the current screen's bars (nav bar
        // presence, hidden tab bar), which swap instantly on navigation.
        // Padding the AnimatedContent wrapper re-anchored both sliding
        // screens to the new bar heights mid-transition, turning a
        // horizontal push into a diagonal slide whenever the two screens'
        // bars differ. Pad each screen individually instead: entering
        // uses the live padding, exiting keeps the padding it was last
        // laid out with.
        val screenPaddings = remember { HashMap<String, PaddingValues>() }
        // Pin each pane's nodes to its key, mirroring NativeUIContent's
        // treesByKey: the exiting pane recomposes when a new publish lands,
        // and reading the live `screenContent` there made the old screen
        // snap to the destination's content before the slide/fade ran —
        // the "new page flashes, then the transition kicks in" stutter.
        val paneNodes = remember { HashMap<String, Pair<NativeUINode?, NativeUINode?>>() }
        AnimatedContent(
            targetState = TabPaneIdentity(key = targetKey, tabIdx = activeTabIdx),
            transitionSpec = {
                // Cross-tab fade vs within-tab slide, decided by the tab
                // metadata — never by the pane identity (a token), which
                // changes on every mount.
                if (initialState.tabIdx != targetState.tabIdx) {
                    fadeIn(tween(180)) togetherWith fadeOut(tween(180))
                } else {
                    transitionFor(pendingTransition)
                }
            },
            label = "tab-content",
            modifier = Modifier.fillMaxSize()
        ) { pane ->
            val key = pane.key
            DisposableEffect(key) {
                onDispose {
                    paneNodes.remove(key)
                    screenPaddings.remove(key)
                }
            }
            val screenPadding = if (key == targetKey) {
                screenPaddings[key] = padding
                padding
            } else {
                screenPaddings[key] ?: padding
            }
            val (paneScreen, paneBottomBar) = if (key == targetKey) {
                Pair(screenContent, bottomBarNode).also { paneNodes[key] = it }
            } else {
                paneNodes[key] ?: Pair(screenContent, bottomBarNode)
            }
            // Saveable scope per pane identity — the key each pane ENTERED
            // with (a token, or the legacy key on old frames where the
            // scope is inert). Exiting panes therefore keep their own
            // token, never the live top token.
            val paneBody: @Composable () -> Unit = {
                Box(modifier = Modifier.fillMaxSize().screenBackground(paneScreen).padding(screenPadding)) {
                    if (paneBottomBar != null) {
                        // Screen content fills the space above the pinned bar; the
                        // bar sits at the bottom. Root `imePadding` shrinks this
                        // column when the keyboard opens so the bar rides above it.
                        Column(modifier = Modifier.fillMaxSize()) {
                            Box(modifier = Modifier.weight(1f).fillMaxWidth()) {
                                if (paneScreen != null) NodeView(node = paneScreen)
                            }
                            paneBottomBar.children.firstOrNull()?.let { NodeView(node = it) }
                        }
                    } else if (paneScreen != null) {
                        NodeView(node = paneScreen)
                    } else {
                        Box(modifier = Modifier.fillMaxSize())
                    }
                }
            }
            if (screenTokens.isNotEmpty()) {
                saveableHolder.SaveableStateProvider(key) { paneBody() }
            } else {
                paneBody()
            }
        }
    }
}

/**
 * AnimatedContent state for a tab pane: the pane's identity [key] (the
 * per-mount screen token when the frame carries one, else the legacy
 * tab|uri key) plus the tab index as transition metadata. The identity
 * must stay a single opaque string — SaveableStateProvider keys and the
 * pane caches all hang off it.
 */
private data class TabPaneIdentity(val key: String, val tabIdx: Int)

/**
 * Compose mirror of iOS's `NativeSearchTabRoot`. Replaces the active
 * screen's content with a search experience when the search-role tab
 * is selected: a top search field plus a list of `search_item` nodes
 * dispatched by `kind` prop:
 *
 *   - `kind = "string"`  → single `Text(value)` row, no tap.
 *   - `kind = "object"`  → title + optional subtitle/leading/trailing,
 *                          tap fires the node's `on_press`.
 *   - `kind = "element"` → `NodeView(child)` for the user-provided
 *                          subtree; tap handled by its own `onPress`.
 *
 * `mode = "static"` filters locally against the typed query (string/
 * object items only — element items always pass through). `mode =
 * "dynamic"` fires a debounced TEXT_CHANGE to PHP's `onSearchQuery($q)`
 * which republishes new `search_item` children.
 */
@Composable
private fun AndroidSearchTabRoot(
    placeholder: String,
    itemNodes: List<NativeUINode>,
    mode: String,
    onQueryCallbackId: Int,
    debounceMs: Int,
    modifier: Modifier = Modifier,
) {
    var query by remember { mutableStateOf("") }
    var hasInteracted by remember { mutableStateOf(false) }

    // Dynamic mode: debounced TEXT_CHANGE → PHP → new items on next
    // publish. Static mode skips this entirely; iOS-side filtering
    // happens below.
    if (mode == "dynamic" && onQueryCallbackId != 0) {
        LaunchedEffect(query) {
            if (!hasInteracted) {
                hasInteracted = true
                return@LaunchedEffect
            }
            if (debounceMs > 0) delay(debounceMs.toLong())
            NativeUIBridge.sendTextChangeEvent(onQueryCallbackId, 0, query)
        }
    }

    val displayed = if (mode == "dynamic" || query.isEmpty()) {
        itemNodes
    } else {
        itemNodes.filter { item ->
            val text = androidSearchableText(item) ?: return@filter true
            text.contains(query, ignoreCase = true)
        }
    }

    Column(modifier = modifier) {
        SearchHeaderField(
            placeholder = placeholder,
            value = query,
            onValueChange = { query = it },
        )
        HorizontalDivider()

        if (itemNodes.isEmpty()) {
            val (icon, headline, body) = when {
                mode == "dynamic" && query.isEmpty() ->
                    Triple("search", "Type to search", "")
                mode == "dynamic" ->
                    Triple("search_off", "No results", "Nothing matched \"$query\".")
                else ->
                    Triple(
                        "search",
                        "Nothing to search here",
                        "This screen hasn't declared searchItems() or onSearchQuery().",
                    )
            }
            Column(
                modifier = Modifier.fillMaxSize().padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Spacer(modifier = Modifier.height(64.dp))
                MaterialIcon(
                    name = icon,
                    contentDescription = null,
                    size = 44.dp,
                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(modifier = Modifier.height(12.dp))
                Text(
                    headline,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    style = MaterialTheme.typography.titleMedium,
                )
                if (body.isNotEmpty()) {
                    Spacer(modifier = Modifier.height(4.dp))
                    Text(
                        body,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
            }
        } else {
            LazyColumn(modifier = Modifier.fillMaxSize()) {
                items(displayed, key = { it.id }) { item ->
                    SearchItemRow(item)
                    HorizontalDivider()
                }
            }
        }
    }
}

/**
 * Top-of-screen search field for `AndroidSearchTabRoot`. Standalone
 * row with a magnifying-glass leading icon, a `BasicTextField`, and a
 * placeholder when empty.
 */
@Composable
private fun SearchHeaderField(
    placeholder: String,
    value: String,
    onValueChange: (String) -> Unit,
) {
    Surface(
        color = MaterialTheme.colorScheme.surface,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp),
        ) {
            MaterialIcon(
                name = "search",
                contentDescription = null,
                size = 22.dp,
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(modifier = Modifier.width(12.dp))
            BasicTextField(
                value = value,
                onValueChange = onValueChange,
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                textStyle = TextStyle(
                    fontSize = 17.sp,
                    color = MaterialTheme.colorScheme.onSurface,
                ),
                cursorBrush = SolidColor(MaterialTheme.colorScheme.primary),
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                keyboardActions = KeyboardActions(onSearch = { /* IME submits via debounce */ }),
                decorationBox = { inner ->
                    if (value.isEmpty() && placeholder.isNotEmpty()) {
                        Text(
                            placeholder,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            fontSize = 17.sp,
                        )
                    }
                    inner()
                },
            )
        }
    }
}

/**
 * One row in the search results list. Dispatches by `kind`:
 *   - "string"  → plain `Text`
 *   - "object"  → standard List-style row (leading / title+subtitle / trailing)
 *   - "element" → `NodeView` over the first child subtree
 */
@Composable
private fun SearchItemRow(item: NativeUINode) {
    when (item.props.getString("kind", "")) {
        "string" -> {
            Text(
                item.props.getString("value", ""),
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 14.dp),
            )
        }
        "object" -> {
            ObjectResultRow(item)
        }
        "element" -> {
            item.children.firstOrNull()?.let { NodeView(node = it) }
        }
        else -> {
            // Forward-compat: ignore unknown kinds rather than crash.
        }
    }
}

/**
 * Standard search-result row for object-kind items. Optional leading
 * Material icon (icon name from PHP), title + optional subtitle,
 * optional trailing icon, tappable surface (`on_press` fires the
 * registered callback — typically a `__navigate(...)` for url-form
 * items or a regular method callback for method-form items).
 */
@Composable
private fun ObjectResultRow(item: NativeUINode) {
    val title = item.props.getString("title", "")
    val subtitle = item.props.getString("subtitle", "")
    val leading = item.props.getString("leading", "")
    val trailing = item.props.getString("trailing", "")
    val tapCallback = item.onPress

    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = Modifier
            .fillMaxWidth()
            .background(MaterialTheme.colorScheme.surface)
            .clickable(enabled = tapCallback != 0) {
                NativeElementBridge.sendPressEvent(tapCallback, item.id)
            }
            .padding(horizontal = 16.dp, vertical = 12.dp),
    ) {
            if (leading.isNotEmpty()) {
                MaterialIcon(
                    name = leading,
                    contentDescription = null,
                    size = 24.dp,
                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(modifier = Modifier.width(16.dp))
            }
            Column(modifier = Modifier.weight(1f)) {
                Text(title, color = MaterialTheme.colorScheme.onSurface)
                if (subtitle.isNotEmpty()) {
                    Text(
                        subtitle,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        if (trailing.isNotEmpty()) {
            Spacer(modifier = Modifier.width(8.dp))
            MaterialIcon(
                name = trailing,
                contentDescription = null,
                size = 18.dp,
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

/** Extracted plain-text view of an item for static-mode client filter. */
private fun androidSearchableText(item: NativeUINode): String? {
    return when (item.props.getString("kind", "")) {
        "string" -> item.props.getString("value", "")
        "object" -> {
            val title = item.props.getString("title", "")
            val subtitle = item.props.getString("subtitle", "")
            if (subtitle.isEmpty()) title else "$title $subtitle"
        }
        else -> null
    }
}

/**
 * Inline search field placed in a `TopAppBar` title slot when a
 * stack-only chrome screen carries `search_placeholder` /
 * `search_on_query` props (set via `NavBarOptions::searchBar()`).
 * Used by `NativeRootStackRenderer` for the legacy in-NavBar search
 * pattern; the new tab-based search experience uses
 * `AndroidSearchTabRoot` instead.
 *
 * Debounce semantics mirror iOS's `SearchableNavBarModifier`: changes
 * are coalesced over `debounceMs` ms before firing the bridge event.
 * `LaunchedEffect(text)` cancels the in-flight delay on the next
 * keystroke.
 */
@Composable
internal fun InlineNavSearchField(
    placeholder: String,
    callbackId: Int,
    nodeId: Int,
    debounceMs: Int,
    modifier: Modifier = Modifier,
) {
    var text by remember { mutableStateOf("") }
    var hasInteracted by remember { mutableStateOf(false) }

    LaunchedEffect(text) {
        if (!hasInteracted) {
            hasInteracted = true
            return@LaunchedEffect
        }
        if (callbackId == 0) return@LaunchedEffect
        if (debounceMs > 0) {
            delay(debounceMs.toLong())
        }
        NativeUIBridge.sendTextChangeEvent(callbackId, nodeId, text)
    }

    Row(modifier = modifier, verticalAlignment = Alignment.CenterVertically) {
        MaterialIcon(
            name = "search",
            contentDescription = null,
            size = 20.dp,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        BasicTextField(
            value = text,
            onValueChange = { text = it },
            modifier = Modifier
                .padding(start = 8.dp)
                .fillMaxWidth(),
            singleLine = true,
            textStyle = TextStyle(
                fontSize = 16.sp,
                color = MaterialTheme.colorScheme.onSurface,
            ),
            cursorBrush = SolidColor(MaterialTheme.colorScheme.primary),
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
            keyboardActions = KeyboardActions(onSearch = { /* IME submits via debounce */ }),
            decorationBox = { inner ->
                if (text.isEmpty() && placeholder.isNotEmpty()) {
                    Text(
                        placeholder,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        fontSize = 16.sp,
                    )
                }
                inner()
            },
        )
    }
}
