package com.nativephp.mobile.ui.nativerender

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.tween
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsetsSides
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.only
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.IconButton
import androidx.compose.material3.LargeTopAppBar
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.ScaffoldDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshots.SnapshotStateList
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import com.nativephp.mobile.ui.MaterialIcon

/**
 * Compose port of iOS's `NativeRootStackRenderer`. Renders the
 * `native_root_stack` sentinel via Material 3 `Scaffold` + `TopAppBar`
 * with a path-driven AnimatedContent for push / pop transitions.
 *
 * `BackHandler` intercepts system back / predictive-back gesture,
 * shrinking the path and firing `sendSystemBackEvent` so the PHP
 * runloop pops to match.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NativeRootStackRenderer(node: NativeUINode, modifier: Modifier = Modifier) {
    val currentUri = node.props.getString("current_uri", "")
    val coordinator = NavigationCoordinator

    // Sync-write the cache so destinations always render the freshest
    // tree on this very recomposition. The path mutation (push / pop)
    // is deferred to LaunchedEffect since mutating SnapshotState during
    // composition is forbidden.
    if (currentUri.isNotEmpty()) {
        coordinator.cache(currentUri, node)
    }
    LaunchedEffect(node) {
        if (currentUri.isNotEmpty()) {
            coordinator.receive(currentUri, node)
        }
    }

    val rootUri by coordinator.rootUri
    val path: SnapshotStateList<String> = coordinator.path

    // Effective top-of-stack URI. Falls back to currentUri on the very
    // first publish before the coordinator has seeded its root.
    val activeUri = path.lastOrNull() ?: rootUri ?: currentUri
    val isRoot = path.isEmpty()

    // Resolve the active level's content via the cache; fall back to
    // the live `node` only when the cache hasn't been populated yet
    // (initial publish, before LaunchedEffect's receive ran).
    val activeNode = coordinator.rootNodeCache[activeUri] ?: node

    val title = activeNode.props.getString("title", "")
    val subtitle = activeNode.props.getString("subtitle", "")
    val showBack = activeNode.props.getBool("back")
    val reservesNavigationBarSlot = NativeRootHostRegistry.reservesNavigationBarSlot(activeNode)
    val bgArgb = activeNode.props.getColor("background_color", 0)
    val textArgb = activeNode.props.getColor("text_color", 0)
    val hasExplicitBg = bgArgb != 0
    val hasExplicitText = textArgb != 0
    // Per-layout / per-bar chrome font (font_name prop), resolved through the
    // plugin seam. Null → inherit the ambient (possibly app-default-themed)
    // typography unchanged.
    val chromeFontFamily = com.nativephp.mobile.ui.NativeUIThemeProvider
        .resolveChromeFontFamily(activeNode.props.getString("font_name", ""))

    // Filter children for actions and the screen content body.
    val actions = activeNode.children.filter { it.type == "top_bar_action" }
    // Custom principal-slot content (logo / titleView) — replaces the
    // string title when present.
    val titleNode = activeNode.children.firstOrNull { it.type == "top_bar_title" }
    val screenContent = activeNode.children.firstOrNull {
        it.type != "top_bar_action" && it.type != "top_bar_title" &&
            it.type != "bottom_bar" && !NativeRootHostRegistry.consumes(it.type)
    }
    // Bottom-pinned content (inline `<native:bottom-bar>` in the screen blade
    // or the layout's `bottomBar()`). Rendered in the Scaffold `bottomBar`
    // slot below; the root `.imePadding()` (NativeUIRenderer) then pushes the
    // whole Scaffold up when the keyboard appears, so the bar rides above it
    // and the content region shrinks — the Android analogue of iOS's
    // `.safeAreaInset(.bottom)`.
    val bottomBarNode = activeNode.children.firstOrNull { it.type == "bottom_bar" }

    // Inline search field config (NavBar::searchBar() — Apple HIG /
    // Expo pattern). When set, replaces the title slot with a search
    // text field; query changes flow back via TEXT_CHANGE.
    val searchPlaceholder = activeNode.props.getString("search_placeholder", "")
    val searchOnQueryCb = activeNode.props.getCallbackId("search_on_query")
    val searchDebounceMs = activeNode.props.getInt("search_debounce_ms", 300)
    val hasSearch = searchPlaceholder.isNotEmpty()

    // Per-screen nav-bar opt-out (`$hidesNavBar` /
    // `navigationOptions()->hidden()`) — suppresses the TopAppBar for
    // this level only; push / pop and the path-driven AnimatedContent
    // keep working, and the Scaffold padding follows automatically.
    val hideNavBar = activeNode.props.getBool("hide_nav_bar")

    // System back / predictive-back: shrink path if pushed, otherwise
    // forward to PHP so it pops the underlying stack (e.g. back to
    // wherever the user came from before entering native chrome).
    BackHandler(enabled = true) {
        if (path.isNotEmpty()) {
            // removeAt, NOT Kotlin's removeLast(): compiled against SDK 35 the
            // latter binds to Java 21's List.removeLast(), absent on Android 14-.
            path.removeAt(path.lastIndex)
            coordinator.onPathChange(path.toList())
        } else {
            NativeElementBridge.sendSystemBackEvent()
        }
    }

    val hasTitle = title.isNotEmpty() || subtitle.isNotEmpty()
    val barContainerColor = if (hasExplicitBg) argbToComposeColor(bgArgb)
        else MaterialTheme.colorScheme.surface

    // `display_mode` mirrors iOS's `navigationBarTitleDisplayMode`. `large`
    // gets Material's `LargeTopAppBar`: a big left-aligned title that
    // collapses to the small bar as the screen content scrolls under it —
    // the Android analogue of iOS's large-title behavior. Other modes use
    // the standard small bar.
    val displayMode = activeNode.props.getString("display_mode", "")
    val useLargeTitle = displayMode == "large" && hasTitle

    // `scroll_behavior` (NavBar::scrollBehavior()) selects the Material 3
    // TopAppBarScrollBehavior. Empty preserves the legacy default: large
    // titles collapse, everything else is pinned. The search field lives
    // in the pinned topBar Column below the bar, so `collapse` yields the
    // iOS pattern — big title scrolls away, search stays pinned.
    val scrollMode = activeNode.props.getString("scroll_behavior", "")
        .ifEmpty { if (useLargeTitle) "collapse" else "pinned" }
    val scrollBehavior = when (scrollMode) {
        "enterAlways" -> TopAppBarDefaults.enterAlwaysScrollBehavior()
        "pinned"      -> TopAppBarDefaults.pinnedScrollBehavior()
        else          -> TopAppBarDefaults.exitUntilCollapsedScrollBehavior() // "collapse"
    }
    // Only wire nested scroll when the bar actually reacts; a truly pinned
    // bar should not consume the content's scroll offset.
    val barReactsToScroll = scrollMode != "pinned"

    // Shared bar slots so the small and large variants are identical apart
    // from collapse behavior.
    val titleContent: @Composable () -> Unit = {
        if (titleNode != null) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                titleNode.children.forEach { child -> NodeView(node = child) }
            }
        } else if (hasSearch && !hasTitle) {
            InlineNavSearchField(
                placeholder = searchPlaceholder,
                callbackId = searchOnQueryCb,
                nodeId = activeNode.id,
                debounceMs = searchDebounceMs,
            )
        } else if (subtitle.isNotEmpty()) {
            Column {
                // Large mode lets the bar drive the headline title style;
                // inline mode shrinks it to titleMedium so title + subtitle
                // fit the single-row bar.
                if (useLargeTitle) {
                    Text(title, fontWeight = FontWeight.SemiBold, fontFamily = chromeFontFamily)
                } else {
                    Text(
                        title,
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.SemiBold,
                        fontFamily = chromeFontFamily
                    )
                }
                Text(subtitle, style = MaterialTheme.typography.labelSmall, fontFamily = chromeFontFamily)
            }
        } else {
            Text(title, fontWeight = FontWeight.SemiBold, fontFamily = chromeFontFamily)
        }
    }
    val navIconContent: @Composable () -> Unit = {
        if (showBack) {
            IconButton(onClick = {
                // Manual back chevron always defers to system back —
                // shrinks path if pushed, otherwise tells PHP.
                if (path.isNotEmpty()) {
                    // removeAt, NOT removeLast() — see BackHandler above.
                    path.removeAt(path.lastIndex)
                    coordinator.onPathChange(path.toList())
                } else {
                    NativeElementBridge.sendSystemBackEvent()
                }
            }) {
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
    }
    val barColors = if (hasExplicitBg) {
        val bg = argbToComposeColor(bgArgb)
        val fg = if (hasExplicitText) argbToComposeColor(textArgb) else Color.White
        TopAppBarDefaults.topAppBarColors(
            containerColor = bg,
            scrolledContainerColor = bg,
            titleContentColor = fg,
            navigationIconContentColor = fg,
            actionIconContentColor = fg
        )
    } else {
        TopAppBarDefaults.topAppBarColors()
    }

    Scaffold(
        topBar = topBar@{
          if (hideNavBar) return@topBar
          // iOS renders `searchBar()` BELOW the navigation title. Mirror that:
          // keep the title in the bar and stack the search field underneath.
          // Only when there's no title at all does search take the title slot
          // (avoids an otherwise-empty bar row).
          Column(modifier = Modifier.background(barContainerColor)) {
            if (useLargeTitle) {
                LargeTopAppBar(
                    title = titleContent,
                    navigationIcon = navIconContent,
                    actions = { actions.forEach { action -> TopBarActionView(action) } },
                    colors = barColors,
                    scrollBehavior = scrollBehavior,
                )
            } else {
                TopAppBar(
                    title = titleContent,
                    navigationIcon = navIconContent,
                    actions = { actions.forEach { action -> TopBarActionView(action) } },
                    colors = barColors,
                    scrollBehavior = scrollBehavior,
                )
            }
            // Search field stacked under a titled bar (iOS-parity). When
            // there's no title, the field already lives in the bar's title
            // slot above, so this is skipped.
            if (hasSearch && hasTitle) {
                // M3 "search bar below a title" pattern: a full-width filled
                // pill (https://m3.material.io/components/search/guidelines).
                InlineNavSearchField(
                    placeholder = searchPlaceholder,
                    callbackId = searchOnQueryCb,
                    nodeId = activeNode.id,
                    debounceMs = searchDebounceMs,
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 16.dp, vertical = 8.dp)
                        .clip(RoundedCornerShape(28.dp))
                        .background(MaterialTheme.colorScheme.surfaceVariant)
                        .padding(horizontal = 16.dp, vertical = 12.dp),
                )
            }
          }
        },
        bottomBar = {
            // Render the bar's inner content (its first child). Kept in the
            // Scaffold slot so content insets above it and `imePadding` lifts
            // it over the keyboard.
            bottomBarNode?.children?.firstOrNull()?.let { NodeView(node = it) }
        },
        // With the TopAppBar composed, it consumes the status-bar inset
        // itself. With the bar hidden, Scaffold's default contentWindowInsets
        // would turn that inset into hard top padding — a dead strip under
        // the (transparent) status bar that nothing can draw behind. Drop
        // the top side so hidden-bar screens are genuinely edge-to-edge;
        // content that shouldn't sit under the clock uses the safe-area
        // utilities instead.
        contentWindowInsets = if (hideNavBar) {
            ScaffoldDefaults.contentWindowInsets
                .only(WindowInsetsSides.Horizontal + WindowInsetsSides.Bottom)
        } else {
            ScaffoldDefaults.contentWindowInsets
        },
        modifier = modifier.fillMaxSize()
    ) { padding ->
        // AnimatedContent slides between stack levels. Direction
        // discrimination (push vs pop) would need state we don't have
        // handy here; the symmetric slide is acceptable for now.
        //
        // The Scaffold padding tracks the CURRENT bar, which swaps
        // instantly when activeUri changes. Applying it to the
        // AnimatedContent wrapper re-anchored both sliding levels to the
        // new bar height mid-slide, so a push between levels whose bars
        // differ in height (large title + search vs small bar) read as a
        // diagonal slide. Each level is padded individually instead: the
        // entering level uses the live padding, the exiting level keeps
        // the padding it was last laid out with, and each level's
        // vertical origin stays fixed for the whole slide.
        val levelPaddings = remember { HashMap<String, PaddingValues>() }
        AnimatedContent(
            targetState = activeUri,
            transitionSpec = {
                val intSpec = tween<androidx.compose.ui.unit.IntOffset>(durationMillis = 250)
                slideInHorizontally(intSpec) { it } togetherWith
                    slideOutHorizontally(intSpec) { -it }
            },
            label = "stack-level",
            modifier = Modifier.fillMaxSize()
        ) { uri ->
            // The TopAppBar's nested-scroll connection is attached HERE —
            // directly wrapping the screen content — rather than on the
            // Scaffold modifier. With the connection on the Scaffold, the
            // AnimatedContent transition boundary sat between it and the
            // scrolling list, so the list's scroll deltas never reached the
            // bar and `collapse`/`enterAlways` never fired. Wrapping the
            // content keeps the connection a direct ancestor of the list.
            val scrollModifier = if (barReactsToScroll) {
                Modifier.nestedScroll(scrollBehavior.nestedScrollConnection)
            } else {
                Modifier
            }
            val levelPadding = if (uri == activeUri) {
                levelPaddings[uri] = padding
                padding
            } else {
                levelPaddings[uri] ?: padding
            }
            Box(modifier = scrollModifier.fillMaxSize().padding(levelPadding)) {
                val levelNode = coordinator.rootNodeCache[uri]
                val levelContent = levelNode?.children?.firstOrNull {
                    it.type != "top_bar_action" && it.type != "top_bar_title" &&
                        it.type != "bottom_bar" && !NativeRootHostRegistry.consumes(it.type)
                }
                if (levelContent != null) {
                    NodeView(node = levelContent)
                } else if (uri == currentUri && screenContent != null) {
                    NodeView(node = screenContent)
                } else {
                    Box(modifier = Modifier.fillMaxSize())
                }
            }
        }
    }
}

/**
 * Renders a single trailing toolbar action — plain IconButton when no
 * sub-items, DropdownMenu when `NavAction::items()` produced
 * `top_bar_action` children. Module-internal so the tabs renderer can
 * reuse the same plumbing when the layout folds NavBar actions onto a
 * `native_root_tabs` sentinel.
 */
@Composable
internal fun TopBarActionView(action: NativeUINode) {
    val icon = action.props.getString("icon", "more_vert")
    val subItems = action.children.filter { it.type == "top_bar_action" }

    if (subItems.isEmpty()) {
        IconButton(onClick = {
            if (action.onPress != 0) {
                NativeElementBridge.sendPressEvent(action.onPress, action.id)
            }
        }) {
            MaterialIcon(name = icon, contentDescription = action.props.getString("label", ""))
        }
    } else {
        var expanded by remember { mutableStateOf(false) }
        IconButton(onClick = { expanded = true }) {
            MaterialIcon(name = icon, contentDescription = action.props.getString("label", ""))
        }
        DropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            subItems.forEach { item ->
                if (item.props.getBool("divider")) {
                    // Inline visual separator emitted by `NavAction::divider()`.
                    HorizontalDivider()
                    return@forEach
                }
                val itemLabel = item.props.getString("label", "")
                val itemIcon = item.props.getString("icon", "")
                val isDestructive = item.props.getBool("destructive")
                val destructiveColor = MaterialTheme.colorScheme.error
                DropdownMenuItem(
                    text = {
                        Text(
                            itemLabel,
                            color = if (isDestructive) destructiveColor else Color.Unspecified
                        )
                    },
                    leadingIcon = if (itemIcon.isNotEmpty()) {
                        {
                            // `destructive()` should tint the whole row red — text +
                            // icon — to mirror SwiftUI's `Button(role: .destructive)`
                            // which colors both. Using the bare default leaves the
                            // icon at LocalContentColor while the text reads red,
                            // which scans as a styling glitch.
                            if (isDestructive) {
                                MaterialIcon(
                                    name = itemIcon,
                                    contentDescription = itemLabel,
                                    tint = destructiveColor,
                                )
                            } else {
                                MaterialIcon(name = itemIcon, contentDescription = itemLabel)
                            }
                        }
                    } else null,
                    onClick = {
                        expanded = false
                        if (item.onPress != 0) {
                            NativeElementBridge.sendPressEvent(item.onPress, item.id)
                        }
                    }
                )
            }
        }
    }
}
