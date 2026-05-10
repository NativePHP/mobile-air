package com.nativephp.mobile.ui.nativerender

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.unit.dp
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarDefaults
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Surface
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import com.nativephp.mobile.ui.MaterialIcon

/**
 * Compose port of iOS's `NativeRootTabsRenderer`. Renders the
 * `native_root_tabs` sentinel via Material 3 `Scaffold` +
 * `NavigationBar`, with an optional inner `TopAppBar` when the layout
 * supplies both bars.
 *
 * Search role and iOS 26-only modifiers (`tabViewBottomAccessory`,
 * `tabBarMinimizeBehavior`) have no exact Android equivalent; the
 * accessory renders inline above the NavigationBar, and search-flagged
 * tabs render as regular tabs.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NativeRootTabsRenderer(node: NativeUINode, modifier: Modifier = Modifier) {
    val tabs = node.children.filter { it.type == "bottom_nav_item" }
    val accessory = node.children.firstOrNull { it.type == "tab_accessory" }
    // NavBar actions folded onto the tabs sentinel by `wrapWithNativeChrome`
    // when both bars are present — render trailing on the top bar.
    val actions = node.children.filter { it.type == "top_bar_action" }
    val screenContent = node.children.firstOrNull {
        it.type != "bottom_nav_item"
            && it.type != "top_bar_action"
            && it.type != "tab_accessory"
    }

    // Activeness flows from `BottomNavItem.active` (TabBar::highlight() set it).
    val activeTabIdx = tabs.indexOfFirst { it.props.getBool("active") }.coerceAtLeast(0)

    // Local selection mirrors PHP's active flag, but also responds to taps so
    // the bar UI updates instantly while we wait for PHP to republish.
    var selection by remember { mutableIntStateOf(activeTabIdx) }
    LaunchedEffect(activeTabIdx) {
        if (selection != activeTabIdx) selection = activeTabIdx
    }

    val activeColorArgb = node.props.getColor("active_color", 0)
    val bgArgb = node.props.getColor("background_color", 0)
    val textArgb = node.props.getColor("text_color", 0)

    // Per-screen explicit signal from PHP (`$hidesTabBar` shortcut or
    // `tabBarOptions()->hidden()` builder, folded into the chrome
    // sentinel by `wrapWithNativeChrome`). Replaces the earlier
    // URI-match / `nav_back` heuristics — those couldn't distinguish a
    // tab root with a back chevron from a pushed-detail screen.
    val hideTabBar = node.props.getBool("hide_tab_bar")

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
    val hasNavBar = navBack || navTitle.isNotEmpty()

    // System back: defer to PHP (the tabs root has nowhere to pop within Compose).
    BackHandler(enabled = true) {
        NativeElementBridge.sendSystemBackEvent()
    }

    Scaffold(
        topBar = {
            if (hasNavBar) {
                TopAppBar(
                    title = {
                        if (navSubtitle.isNotEmpty()) {
                            Column {
                                Text(navTitle, fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.titleMedium)
                                Text(navSubtitle, style = MaterialTheme.typography.labelSmall)
                            }
                        } else {
                            Text(navTitle, fontWeight = FontWeight.SemiBold)
                        }
                    },
                    navigationIcon = {
                        if (navBack) {
                            IconButton(onClick = { NativeElementBridge.sendSystemBackEvent() }) {
                                Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                            }
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
                // pattern). Compose / M3 has no native equivalent of
                // iOS 26's `.safeAreaBar(.bottom)` Liquid Glass capsule
                // — there's no built-in floating-bar primitive that
                // sits ABOVE the navigation bar, and Modifier.blur on
                // Android blurs the surface itself rather than the
                // content beneath it (so a true "backdrop blur" glass
                // material isn't directly available).
                //
                // Best-effort idiomatic replacement: a tonal Surface at
                // `surfaceContainerHigh` (one tonal step above the
                // NavigationBar's container so the two read as
                // separate layers) with rounded top corners + a small
                // shadow elevation. Matches what real-world Android
                // music apps ship.
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

                NavigationBar(
                    containerColor = if (bgArgb != 0)
                        argbToComposeColor(bgArgb)
                    else
                        NavigationBarDefaults.containerColor
                ) {
                    tabs.forEachIndexed { idx, tab ->
                        val label = tab.props.getString("label", "")
                        val icon = tab.props.getString("icon", "circle")
                        val badge = tab.props.getString("badge", "")
                        val news = tab.props.getBool("news")

                        NavigationBarItem(
                            selected = idx == selection,
                            onClick = {
                                // Local selection updates instantly so the
                                // ripple / selection indicator responds; the
                                // press handler fires the PHP-side
                                // BottomNavItem-auto-wired `replace` nav (or
                                // a Tab::press() override on action tabs).
                                if (idx != activeTabIdx) {
                                    selection = idx
                                    if (tab.onPress != 0) {
                                        NativeElementBridge.sendPressEvent(tab.onPress, tab.id)
                                    }
                                }
                            },
                            icon = {
                                if (badge.isNotEmpty() || news) {
                                    BadgedBox(badge = {
                                        Badge {
                                            if (badge.isNotEmpty()) Text(badge)
                                        }
                                    }) {
                                        MaterialIcon(name = icon, contentDescription = label)
                                    }
                                } else {
                                    MaterialIcon(name = icon, contentDescription = label)
                                }
                            },
                            label = { Text(label) },
                            colors = if (activeColorArgb != 0) {
                                val active = argbToComposeColor(activeColorArgb)
                                NavigationBarItemDefaults.colors(
                                    selectedIconColor = active,
                                    selectedTextColor = active,
                                    indicatorColor = active.copy(alpha = 0.16f)
                                )
                            } else {
                                NavigationBarItemDefaults.colors()
                            }
                        )
                    }
                }
            }
        },
        modifier = modifier.fillMaxSize()
    ) { padding ->
        // Animate two distinct kinds of swap inside the tabs scaffold:
        //
        //   1. Tab switch (active tab index changes) — keep the soft
        //      cross-fade. PHP signals these via `BottomNavItem` with
        //      transition='none' because the renderer is supposed to
        //      handle the visual; honor that contract here.
        //
        //   2. Within-tab push / pop (active tab same, URI changed) —
        //      use the PHP-signaled transition (slide_from_right by
        //      default for navigate, slide_from_left for back). iOS gets
        //      this for free from per-tab NavigationStack; Android lacks
        //      that, so we drive it explicitly off the same
        //      `pendingTransition` that the root NativeUIContent reads.
        //      The bridge suppresses the *root* screenKey bump for
        //      native-chrome continuation publishes (NativeElementBridge
        //      ~line 261) so the outer animation stays out of our way;
        //      this inner one fills the gap.
        val pendingTransition by NativeUIBridge.pendingTransition
        val targetKey = "$activeTabIdx|$currentUri"

        AnimatedContent(
            targetState = targetKey,
            transitionSpec = {
                val initialIdx = initialState.substringBefore('|')
                val targetIdx = targetState.substringBefore('|')
                if (initialIdx != targetIdx) {
                    fadeIn(tween(180)) togetherWith fadeOut(tween(180))
                } else {
                    transitionFor(pendingTransition)
                }
            },
            label = "tab-content",
            modifier = Modifier.fillMaxSize().padding(padding)
        ) { _ ->
            if (screenContent != null) {
                NodeView(node = screenContent)
            } else {
                Box(modifier = Modifier.fillMaxSize())
            }
        }
    }
}
