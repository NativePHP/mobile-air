package com.nativephp.mobile.ui.nativerender

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.tween
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
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
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
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
    val bgArgb = activeNode.props.getColor("background_color", 0)
    val textArgb = activeNode.props.getColor("text_color", 0)
    val hasExplicitBg = bgArgb != 0
    val hasExplicitText = textArgb != 0

    // Filter children for actions and the screen content body.
    val actions = activeNode.children.filter { it.type == "top_bar_action" }
    val screenContent = activeNode.children.firstOrNull {
        it.type != "top_bar_action" && !NativeRootHostRegistry.consumes(it.type)
    }

    // Inline search field config (NavBar::searchBar() — Apple HIG /
    // Expo pattern). When set, replaces the title slot with a search
    // text field; query changes flow back via TEXT_CHANGE.
    val searchPlaceholder = activeNode.props.getString("search_placeholder", "")
    val searchOnQueryCb = activeNode.props.getCallbackId("search_on_query")
    val searchDebounceMs = activeNode.props.getInt("search_debounce_ms", 300)
    val hasSearch = searchPlaceholder.isNotEmpty()

    // System back / predictive-back: shrink path if pushed, otherwise
    // forward to PHP so it pops the underlying stack (e.g. back to
    // wherever the user came from before entering native chrome).
    BackHandler(enabled = true) {
        if (path.isNotEmpty()) {
            path.removeLast()
            coordinator.onPathChange(path.toList())
        } else {
            NativeElementBridge.sendSystemBackEvent()
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    if (hasSearch) {
                        InlineNavSearchField(
                            placeholder = searchPlaceholder,
                            callbackId = searchOnQueryCb,
                            nodeId = activeNode.id,
                            debounceMs = searchDebounceMs,
                        )
                    } else if (subtitle.isNotEmpty()) {
                        Column {
                            Text(
                                title,
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.SemiBold
                            )
                            Text(
                                subtitle,
                                style = MaterialTheme.typography.labelSmall
                            )
                        }
                    } else {
                        Text(title, fontWeight = FontWeight.SemiBold)
                    }
                },
                navigationIcon = {
                    if (showBack) {
                        IconButton(onClick = {
                            // Manual back chevron always defers to system back —
                            // shrinks path if pushed, otherwise tells PHP.
                            if (path.isNotEmpty()) {
                                path.removeLast()
                                coordinator.onPathChange(path.toList())
                            } else {
                                NativeElementBridge.sendSystemBackEvent()
                            }
                        }) {
                            Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                        }
                    }
                },
                actions = {
                    actions.forEach { action ->
                        TopBarActionView(action)
                    }
                },
                colors = if (hasExplicitBg) {
                    val bg = argbToComposeColor(bgArgb)
                    val fg = if (hasExplicitText) argbToComposeColor(textArgb) else Color.White
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
        },
        modifier = modifier.fillMaxSize()
    ) { padding ->
        // AnimatedContent slides between stack levels. Direction
        // discrimination (push vs pop) would need state we don't have
        // handy here; the symmetric slide is acceptable for now.
        AnimatedContent(
            targetState = activeUri,
            transitionSpec = {
                val intSpec = tween<androidx.compose.ui.unit.IntOffset>(durationMillis = 250)
                slideInHorizontally(intSpec) { it } togetherWith
                    slideOutHorizontally(intSpec) { -it }
            },
            label = "stack-level",
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
        ) { uri ->
            val levelNode = coordinator.rootNodeCache[uri]
            val levelContent = levelNode?.children?.firstOrNull {
                it.type != "top_bar_action" && !NativeRootHostRegistry.consumes(it.type)
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
