package com.nativephp.mobile.ui.nativerender

import android.content.Intent
import android.net.Uri
import android.util.Log
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import com.nativephp.mobile.ui.MaterialIcon
import com.nativephp.mobile.ui.NativeUIState
import kotlinx.coroutines.launch

private const val TAG = "NativeNavRenderers"

// ── Top Bar ──────────────────────────────────────────────

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun RenderTopBar(node: NativeUINode, modifier: Modifier) {
    val props = node.props
    val title = props.getString("title", "")
    val subtitle = props.getString("subtitle")
    val showNavIcon = props.getBool("show_navigation_icon", true)
    val bgColorStr = props.getString("background_color")
    val textColorStr = props.getString("text_color")
    val scope = rememberCoroutineScope()
    val context = LocalContext.current

    val bgColor = parseHexColor(bgColorStr)
    val textColor = parseHexColor(textColorStr)

    // Collect top_bar_action children
    val actions = node.children.filter { it.type == "top_bar_action" }
    val visibleActions = actions.take(3)
    val overflowActions = actions.drop(3)
    var showOverflowMenu by remember { mutableStateOf(false) }

    TopAppBar(
        modifier = modifier.windowInsetsPadding(WindowInsets.statusBars),
        title = {
            Column {
                Text(
                    text = title,
                    color = textColor ?: MaterialTheme.colorScheme.onSurface
                )
                if (subtitle.isNotEmpty()) {
                    Text(
                        text = subtitle,
                        style = MaterialTheme.typography.bodySmall,
                        color = (textColor ?: MaterialTheme.colorScheme.onSurface).copy(alpha = 0.7f)
                    )
                }
            }
        },
        navigationIcon = {
            if (showNavIcon) {
                IconButton(onClick = {
                    Log.d(TAG, "Navigation icon clicked — opening drawer")
                    scope.launch {
                        NativeUIState.drawerState?.open()
                    }
                }) {
                    MaterialIcon(
                        name = "menu",
                        contentDescription = "Menu",
                        tint = textColor ?: MaterialTheme.colorScheme.onSurface
                    )
                }
            }
        },
        actions = {
            visibleActions.forEach { action ->
                val aProps = action.props
                val icon = aProps.getString("icon", "more_vert")
                val label = aProps.getString("label")
                val url = aProps.getString("url")

                IconButton(onClick = {
                    if (url.isNotEmpty()) {
                        if (isExternalUrl(url)) {
                            try {
                                context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                            } catch (e: Exception) {
                                Log.e(TAG, "Failed to open URL: $url", e)
                            }
                        } else {
                            // Navigate via press callback if available
                            if (action.onPress != 0) {
                                NativeUIBridge.sendPressEvent(action.onPress, action.id)
                            }
                        }
                    }
                }) {
                    MaterialIcon(
                        name = icon,
                        contentDescription = label.ifEmpty { null },
                        tint = textColor ?: MaterialTheme.colorScheme.onSurface
                    )
                }
            }

            if (overflowActions.isNotEmpty()) {
                IconButton(onClick = { showOverflowMenu = true }) {
                    MaterialIcon(
                        name = "more_vert",
                        contentDescription = "More options",
                        tint = textColor ?: MaterialTheme.colorScheme.onSurface
                    )
                }
                DropdownMenu(
                    expanded = showOverflowMenu,
                    onDismissRequest = { showOverflowMenu = false }
                ) {
                    overflowActions.forEach { action ->
                        val aProps = action.props
                        DropdownMenuItem(
                            text = { Text(aProps.getString("label", aProps.getString("id"))) },
                            onClick = {
                                showOverflowMenu = false
                                if (action.onPress != 0) {
                                    NativeUIBridge.sendPressEvent(action.onPress, action.id)
                                }
                            },
                            leadingIcon = {
                                MaterialIcon(
                                    name = aProps.getString("icon", "circle"),
                                    contentDescription = aProps.getString("label")
                                )
                            }
                        )
                    }
                }
            }
        },
        colors = TopAppBarDefaults.topAppBarColors(
            containerColor = bgColor ?: MaterialTheme.colorScheme.surface,
            titleContentColor = textColor ?: MaterialTheme.colorScheme.onSurface,
            navigationIconContentColor = textColor ?: MaterialTheme.colorScheme.onSurface
        )
    )
}

// ── Side Nav ─────────────────────────────────────────────
// The side_nav node doesn't render visible content inline.
// Instead it stores its children so the drawer (triggered by top_bar hamburger)
// can display them. We use NativeEdgeDrawerState as a bridge.

internal object NativeEdgeDrawerState {
    val sideNavNode = mutableStateOf<NativeUINode?>(null)
}

@Composable
internal fun RenderSideNav(node: NativeUINode, modifier: Modifier) {
    Log.d(TAG, "RenderSideNav: children=${node.children.size} types=${node.children.map { it.type }}")
    // Store the side_nav node for the drawer to render
    SideEffect {
        NativeEdgeDrawerState.sideNavNode.value = node
    }
    // Render nothing — the drawer is handled by the wrapper
}

/**
 * Renders the drawer content from a side_nav NativeUINode tree.
 * Called from NativeSideDrawer when native EDGE data is available.
 */
@Composable
internal fun RenderSideNavDrawerContent(
    node: NativeUINode,
    onCloseDrawer: (() -> Unit) -> Unit,
    onNavigate: ((String) -> Unit)? = null
) {
    val labelVisibility = node.props.getString("label_visibility", "labeled")

    // Separate pinned headers from scrollable content
    val pinnedHeaders = node.children.filter {
        it.type == "side_nav_header" && it.props.getBool("pinned")
    }
    val scrollableChildren = node.children.filter {
        !(it.type == "side_nav_header" && it.props.getBool("pinned"))
    }

    // Track expanded groups
    val expandedGroups = remember { mutableStateMapOf<String, Boolean>() }

    ModalDrawerSheet {
        Column(modifier = Modifier.fillMaxHeight()) {
            // Pinned headers
            pinnedHeaders.forEach { child ->
                RenderSideNavHeaderContent(child, onCloseDrawer)
            }

            // Scrollable content
            Column(
                modifier = Modifier
                    .fillMaxHeight()
                    .weight(1f)
                    .verticalScroll(rememberScrollState())
            ) {
                Spacer(Modifier.height(16.dp))

                scrollableChildren.forEach { child ->
                    when (child.type) {
                        "side_nav_header" -> RenderSideNavHeaderContent(child, onCloseDrawer)
                        "side_nav_item" -> RenderSideNavItemContent(child, labelVisibility, onCloseDrawer, onNavigate = onNavigate)
                        "side_nav_group" -> {
                            val heading = child.props.getString("heading", "Group")
                            if (!expandedGroups.containsKey(heading)) {
                                expandedGroups[heading] = child.props.getBool("expanded")
                            }
                            RenderSideNavGroupContent(
                                node = child,
                                isExpanded = expandedGroups[heading] ?: false,
                                onToggle = { expandedGroups[heading] = !(expandedGroups[heading] ?: false) },
                                labelVisibility = labelVisibility,
                                onCloseDrawer = onCloseDrawer,
                                onNavigate = onNavigate
                            )
                        }
                        "horizontal_divider" -> {
                            HorizontalDivider(modifier = Modifier.padding(vertical = 8.dp))
                        }
                    }
                }

                Spacer(Modifier.height(16.dp))
            }
        }
    }
}

@Composable
private fun RenderSideNavHeaderContent(
    node: NativeUINode,
    onCloseDrawer: (() -> Unit) -> Unit
) {
    val props = node.props
    val title = props.getString("title")
    val subtitle = props.getString("subtitle")
    val icon = props.getString("icon")
    val bgColorStr = props.getString("background_color")
    val showCloseButton = props.getBool("show_close_button", true)

    val bgColor = parseHexColor(bgColorStr)

    Surface(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 12.dp, vertical = 8.dp),
        color = bgColor ?: MaterialTheme.colorScheme.surfaceVariant,
        shape = MaterialTheme.shapes.medium
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(16.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            if (icon.isNotEmpty()) {
                MaterialIcon(
                    name = icon,
                    contentDescription = title,
                    modifier = Modifier.padding(end = 16.dp),
                    tint = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }

            Column(modifier = Modifier.weight(1f)) {
                if (title.isNotEmpty()) {
                    Text(
                        text = title,
                        style = MaterialTheme.typography.titleMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
                if (subtitle.isNotEmpty()) {
                    Text(
                        text = subtitle,
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.7f)
                    )
                }
            }

            if (showCloseButton) {
                IconButton(onClick = { onCloseDrawer {} }) {
                    MaterialIcon(
                        name = "close",
                        contentDescription = "Close drawer",
                        tint = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            }
        }
    }
}

@Composable
private fun RenderSideNavItemContent(
    node: NativeUINode,
    labelVisibility: String,
    onCloseDrawer: (() -> Unit) -> Unit,
    onNavigate: ((String) -> Unit)? = null,
    modifier: Modifier = Modifier.padding(horizontal = 12.dp)
) {
    val props = node.props
    val label = props.getString("label", "")
    val icon = props.getString("icon", "circle")
    val url = props.getString("url", "")
    val active = props.getBool("active")
    val badge = props.getString("badge")
    val badgeColor = props.getString("badge_color")
    val context = LocalContext.current

    NavigationDrawerItem(
        icon = {
            MaterialIcon(name = icon, contentDescription = label)
        },
        label = {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                when (labelVisibility) {
                    "unlabeled" -> {}
                    "selected" -> if (active) Text(label)
                    else -> Text(label)
                }

                if (badge.isNotEmpty()) {
                    Badge(
                        containerColor = parseBadgeColor(badgeColor),
                        contentColor = Color.White
                    ) {
                        Text(text = badge, style = MaterialTheme.typography.labelLarge)
                    }
                }
            }
        },
        selected = active,
        onClick = {
            Log.d(TAG, "Side nav item clicked: $label -> $url")
            val openInBrowser = props.getBool("open_in_browser") || isExternalUrl(url)
            if (openInBrowser) {
                try {
                    context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                } catch (e: Exception) {
                    Log.e(TAG, "Failed to open URL: $url", e)
                }
                onCloseDrawer {}
            } else if (node.onPress != 0) {
                onCloseDrawer { NativeUIBridge.sendPressEvent(node.onPress, node.id) }
            } else if (url.isNotEmpty() && onNavigate != null) {
                onCloseDrawer { onNavigate(url) }
            } else {
                onCloseDrawer {}
            }
        },
        modifier = modifier
    )
}

@Composable
private fun RenderSideNavGroupContent(
    node: NativeUINode,
    isExpanded: Boolean,
    onToggle: () -> Unit,
    labelVisibility: String,
    onCloseDrawer: (() -> Unit) -> Unit,
    onNavigate: ((String) -> Unit)? = null
) {
    val heading = node.props.getString("heading", "Group")
    val icon = node.props.getString("icon")

    Column {
        NavigationDrawerItem(
            icon = if (icon.isNotEmpty()) {
                { MaterialIcon(name = icon, contentDescription = heading) }
            } else null,
            label = {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(heading, modifier = Modifier.weight(1f))
                    Spacer(Modifier.width(8.dp))
                    MaterialIcon(
                        name = if (isExpanded) "expand_less" else "expand_more",
                        contentDescription = if (isExpanded) "Collapse" else "Expand"
                    )
                }
            },
            selected = false,
            onClick = onToggle,
            modifier = Modifier.padding(horizontal = 12.dp)
        )

        AnimatedVisibility(
            visible = isExpanded,
            enter = expandVertically(),
            exit = shrinkVertically()
        ) {
            Column(modifier = Modifier.padding(start = 16.dp)) {
                node.children.filter { it.type == "side_nav_item" }.forEach { child ->
                    RenderSideNavItemContent(
                        node = child,
                        labelVisibility = labelVisibility,
                        onCloseDrawer = onCloseDrawer,
                        onNavigate = onNavigate,
                        modifier = Modifier.padding(horizontal = 12.dp, vertical = 2.dp)
                    )
                }
            }
        }
    }
}

// ── Bottom Nav ───────────────────────────────────────────

@Composable
internal fun RenderBottomNav(node: NativeUINode, modifier: Modifier) {
    val props = node.props
    val labelVisibility = props.getString("label_visibility", "labeled")
    val activeColorStr = props.getString("active_color")
    val activeColor = parseHexColor(activeColorStr)

    val items = node.children.filter { it.type == "bottom_nav_item" }
    if (items.isEmpty()) return

    NavigationBar(modifier = modifier) {
        items.forEach { item ->
            val iProps = item.props
            val label = iProps.getString("label", "")
            val icon = iProps.getString("icon", "circle")
            val active = iProps.getBool("active")
            val badge = iProps.getString("badge")
            val badgeColor = iProps.getString("badge_color")
            val news = iProps.getBool("news")

            NavigationBarItem(
                icon = {
                    BadgedBox(
                        badge = {
                            when {
                                news -> Badge(containerColor = parseBadgeColor("red"))
                                badge.isNotEmpty() -> Badge(
                                    containerColor = parseBadgeColor(badgeColor),
                                    contentColor = Color.White
                                ) {
                                    Text(text = badge, style = MaterialTheme.typography.labelSmall)
                                }
                            }
                        }
                    ) {
                        MaterialIcon(name = icon, contentDescription = label)
                    }
                },
                label = {
                    when (labelVisibility) {
                        "unlabeled" -> {}
                        "selected" -> if (active) Text(label)
                        else -> Text(label)
                    }
                },
                selected = active,
                colors = if (activeColor != null) {
                    NavigationBarItemDefaults.colors(
                        selectedIconColor = activeColor,
                        selectedTextColor = activeColor
                    )
                } else {
                    NavigationBarItemDefaults.colors()
                },
                onClick = {
                    Log.d(TAG, "Bottom nav item clicked: $label")
                    if (item.onPress != 0) {
                        NativeUIBridge.sendPressEvent(item.onPress, item.id)
                    }
                }
            )
        }
    }
}

// ── FAB ──────────────────────────────────────────────────

@Composable
internal fun RenderFab(node: NativeUINode, modifier: Modifier) {
    val props = node.props
    val icon = props.getString("icon", "add")
    val label = props.getString("label")
    val containerColorStr = props.getString("container_color")
    val contentColorStr = props.getString("content_color")
    val size = props.getString("size", "regular")

    val containerColor = parseHexColor(containerColorStr) ?: MaterialTheme.colorScheme.primaryContainer
    val contentColor = parseHexColor(contentColorStr) ?: MaterialTheme.colorScheme.onPrimaryContainer

    when (size) {
        "small" -> SmallFloatingActionButton(
            onClick = {
                if (node.onPress != 0) NativeUIBridge.sendPressEvent(node.onPress, node.id)
            },
            containerColor = containerColor,
            contentColor = contentColor,
            modifier = modifier
        ) {
            MaterialIcon(name = icon, contentDescription = label)
        }
        "large" -> LargeFloatingActionButton(
            onClick = {
                if (node.onPress != 0) NativeUIBridge.sendPressEvent(node.onPress, node.id)
            },
            containerColor = containerColor,
            contentColor = contentColor,
            modifier = modifier
        ) {
            MaterialIcon(name = icon, contentDescription = label)
        }
        "extended" -> ExtendedFloatingActionButton(
            onClick = {
                if (node.onPress != 0) NativeUIBridge.sendPressEvent(node.onPress, node.id)
            },
            containerColor = containerColor,
            contentColor = contentColor,
            modifier = modifier,
            icon = { MaterialIcon(name = icon, contentDescription = null) },
            text = { Text(label) }
        )
        else -> FloatingActionButton(
            onClick = {
                if (node.onPress != 0) NativeUIBridge.sendPressEvent(node.onPress, node.id)
            },
            containerColor = containerColor,
            contentColor = contentColor,
            modifier = modifier
        ) {
            MaterialIcon(name = icon, contentDescription = label)
        }
    }
}

// ── Horizontal Divider ───────────────────────────────────

@Composable
internal fun RenderHorizontalDivider(node: NativeUINode, modifier: Modifier) {
    HorizontalDivider(modifier = modifier.padding(vertical = 8.dp))
}

// ── Bottom Nav Item (standalone, shouldn't render outside bottom_nav) ──

@Composable
internal fun RenderBottomNavItem(node: NativeUINode, modifier: Modifier) {
    // bottom_nav_item is rendered by its parent bottom_nav renderer
    // If it appears standalone, render nothing
}

// ── Top Bar Action (standalone, shouldn't render outside top_bar) ──

@Composable
internal fun RenderTopBarAction(node: NativeUINode, modifier: Modifier) {
    // top_bar_action is rendered by its parent top_bar renderer
}

// ── Side Nav children (standalone, shouldn't render outside side_nav) ──

@Composable
internal fun RenderSideNavItem(node: NativeUINode, modifier: Modifier) {
    // Rendered by side_nav drawer content
}

@Composable
internal fun RenderSideNavGroup(node: NativeUINode, modifier: Modifier) {
    // Rendered by side_nav drawer content
}

@Composable
internal fun RenderSideNavHeader(node: NativeUINode, modifier: Modifier) {
    // Rendered by side_nav drawer content
}

// ── Utility ──────────────────────────────────────────────

private fun parseHexColor(hex: String): Color? {
    if (hex.isEmpty()) return null
    return try {
        val sanitized = hex.removePrefix("#")
        when (sanitized.length) {
            6 -> Color(android.graphics.Color.parseColor("#$sanitized"))
            8 -> Color(android.graphics.Color.parseColor("#$sanitized"))
            else -> null
        }
    } catch (e: Exception) {
        null
    }
}

private fun parseBadgeColor(colorString: String): Color {
    return when (colorString.lowercase()) {
        "lime" -> Color(0xFF84CC16)
        "green" -> Color(0xFF22C55E)
        "blue" -> Color(0xFF3B82F6)
        "red" -> Color(0xFFEF4444)
        "yellow" -> Color(0xFFEAB308)
        "purple" -> Color(0xFFA855F7)
        "pink" -> Color(0xFFEC4899)
        "orange" -> Color(0xFFF97316)
        else -> Color(0xFF6366F1)
    }
}

private fun isExternalUrl(url: String): Boolean {
    return (url.startsWith("http://") || url.startsWith("https://"))
            && !url.contains("127.0.0.1")
            && !url.contains("localhost")
}
