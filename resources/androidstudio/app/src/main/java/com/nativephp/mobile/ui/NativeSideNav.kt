package com.nativephp.mobile.ui

import android.content.Intent
import android.net.Uri
import android.util.Log
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch

private const val TAG = "NativeSideNav"

/**
 * Material3 Modal Navigation Drawer that renders from Laravel NativeUI state
 *
 * In pure Compose architecture, the drawer wraps the main content.
 * When there's no data, it just renders the content without a drawer.
 *
 * @param onNavigate Callback when a navigation item is clicked
 * @param onDrawerStateChange Optional callback for monitoring drawer open/close state (for logging)
 * @param content The main app content to display (WebView + bottom nav)
 */
@Composable
fun NativeSideDrawer(
    onNavigate: (String) -> Unit,
    onDrawerStateChange: (Boolean) -> Unit,
    content: @Composable () -> Unit
) {
    val sideNavData by NativeUIState.sideNavData
    val drawerState = rememberDrawerState(initialValue = DrawerValue.Closed)
    val scope = rememberCoroutineScope()

    // Expose drawer state to be opened from elsewhere (e.g., JavaScript)
    LaunchedEffect(Unit) {
        NativeUIState.drawerState = drawerState
        NativeUIState.drawerScope = scope
    }

    // Monitor drawer state and notify parent
    LaunchedEffect(drawerState.currentValue) {
        val isOpen = drawerState.currentValue == DrawerValue.Open
        Log.d(TAG, "Drawer state changed: isOpen=$isOpen")
        onDrawerStateChange(isOpen)
    }

    // Track expanded state for each group by heading
    val expandedGroups = remember { mutableStateMapOf<String, Boolean>() }

    // Check if we have side nav data
    val hasData = sideNavData != null && !sideNavData?.children.isNullOrEmpty()
    val children = sideNavData?.children ?: emptyList()
    val gesturesEnabled = sideNavData?.gesturesEnabled ?: false

    if (hasData) {
        Log.d(TAG, "🎨 Rendering side nav with ${children.size} children")
        children.forEachIndexed { index, child ->
            Log.d(TAG, "🎨   Child $index: type=${child.type}")
        }
    } else {
        Log.d(TAG, "No side nav data - drawer will be disabled")
    }

    // Separate pinned and scrollable content
    val (pinnedHeaders, scrollableChildren) = children.partition { child ->
        if (child.type == "side_nav_header") {
            val header = NativeUIParser.parseSideNavHeader(child.data)
            header?.pinned == true
        } else {
            false
        }
    }

    // Always render ModalNavigationDrawer to keep composable structure stable for WebView
    ModalNavigationDrawer(
        drawerState = drawerState,
        gesturesEnabled = hasData && gesturesEnabled,  // Controlled via Laravel
        drawerContent = {
            ModalDrawerSheet {
                Column(modifier = Modifier.fillMaxHeight()) {
                    // Render pinned headers at the top (non-scrollable)
                    pinnedHeaders.forEach { child ->
                        if (child.type == "side_nav_header") {
                            val header = NativeUIParser.parseSideNavHeader(child.data)
                            header?.let {
                                SideNavHeaderView(
                                    header = it,
                                    onNavigate = onNavigate,
                                    onCloseDrawer = { scope.launch { drawerState.close() } }
                                )
                            }
                        }
                    }

                    // Scrollable column for remaining content
                    Column(
                        modifier = Modifier
                            .fillMaxHeight()
                            .weight(1f)
                            .verticalScroll(rememberScrollState())
                    ) {
                        Spacer(Modifier.height(16.dp))

                        scrollableChildren.forEach { child ->
                            when (child.type) {
                                "side_nav_header" -> {
                                    val header = NativeUIParser.parseSideNavHeader(child.data)
                                    header?.let {
                                        SideNavHeaderView(
                                            header = it,
                                            onNavigate = onNavigate,
                                            onCloseDrawer = { scope.launch { drawerState.close() } }
                                        )
                                    }
                                }
                                "side_nav_item" -> {
                                    val item = NativeUIParser.parseSideNavItem(child.data)
                                    item?.let {
                                        SideNavItemView(
                                            item = it,
                                            labelVisibility = sideNavData?.labelVisibility,
                                            onNavigate = onNavigate,
                                            onCloseDrawer = { scope.launch { drawerState.close() } }
                                        )
                                    }
                                }
                                "side_nav_group" -> {
                                    Log.d(TAG, "📦 Found side_nav_group, raw data: ${child.data}")
                                    val group = NativeUIParser.parseSideNavGroup(child.data)
                                    Log.d(TAG, "📦 Parsed group: heading=${group?.heading}, children=${group?.children?.size ?: 0}")
                                    group?.let {
                                        // Initialize expanded state from data
                                        if (!expandedGroups.containsKey(it.heading)) {
                                            expandedGroups[it.heading] = it.expanded ?: false
                                        }

                                        Log.d(TAG, "📦 Rendering group '${it.heading}' with ${it.children?.size ?: 0} children, expanded=${expandedGroups[it.heading]}")

                                        SideNavGroupView(
                                            group = it,
                                            isExpanded = expandedGroups[it.heading] ?: false,
                                            onToggle = { expandedGroups[it.heading] = !(expandedGroups[it.heading] ?: false) },
                                            labelVisibility = sideNavData?.labelVisibility,
                                            onNavigate = onNavigate,
                                            onCloseDrawer = { scope.launch { drawerState.close() } }
                                        )
                                    }
                                }
                                "horizontal_divider" -> {
                                    HorizontalDivider(
                                        modifier = Modifier.padding(vertical = 8.dp)
                                    )
                                }
                                else -> {
                                    Log.w(TAG, "Unknown side nav child type: ${child.type}")
                                }
                            }
                        }

                        Spacer(Modifier.height(16.dp))
                    }
                }
            }
        },
        content = content
    )
}

/**
 * Renders a single side nav item
 */
@Composable
private fun SideNavItemView(
    item: SideNavItem,
    labelVisibility: String?,
    onNavigate: (String) -> Unit,
    onCloseDrawer: () -> Unit,
    modifier: Modifier = Modifier.padding(horizontal = 12.dp)
) {
    val context = LocalContext.current

    NavigationDrawerItem(
        icon = {
            MaterialIcon(
                name = item.icon,
                contentDescription = item.label
            )
        },
        label = {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                when (labelVisibility) {
                    "unlabeled" -> {}
                    "selected" -> if (item.active == true) Text(item.label)
                    else -> Text(item.label)
                }

                // Render badge if present
                item.badge?.let { badgeText ->
                    Badge(
                        containerColor = parseBadgeColor(item.badgeColor),
                        contentColor = Color.White
                    ) {
                        Text(
                            text = badgeText,
                            style = MaterialTheme.typography.labelLarge
                        )
                    }
                }
            }
        },
        selected = item.active == true,
        onClick = {
            Log.d(TAG, "🖱️ Side nav item clicked: ${item.label} -> ${item.url}")

            // Check if this should open in external browser
            val shouldOpenExternal = item.openInBrowser == true || isExternalUrl(item.url)

            if (shouldOpenExternal) {
                Log.d(TAG, "🌐 Opening external URL in browser: ${item.url}")
                try {
                    val intent = Intent(Intent.ACTION_VIEW, Uri.parse(item.url))
                    context.startActivity(intent)
                } catch (e: Exception) {
                    Log.e(TAG, "Failed to open external URL: ${item.url}", e)
                }
            } else {
                Log.d(TAG, "📱 Opening internal URL in WebView: ${item.url}")
                onNavigate(item.url)
            }

            onCloseDrawer()
        },
        modifier = modifier
    )
}

/**
 * Check if a URL is external (not a relative path or localhost)
 */
private fun isExternalUrl(url: String): Boolean {
    return (url.startsWith("http://") || url.startsWith("https://"))
            && !url.contains("127.0.0.1")
            && !url.contains("localhost")
}

/**
 * Renders an expandable group with child items
 */
@Composable
private fun SideNavGroupView(
    group: SideNavGroup,
    isExpanded: Boolean,
    onToggle: () -> Unit,
    labelVisibility: String?,
    onNavigate: (String) -> Unit,
    onCloseDrawer: () -> Unit
) {
    Column {
        // Group header (clickable to expand/collapse)
        NavigationDrawerItem(
            icon = group.icon?.let {
                {
                    MaterialIcon(
                        name = it,
                        contentDescription = group.heading
                    )
                }
            },
            label = {
                Row(
                    verticalAlignment = androidx.compose.ui.Alignment.CenterVertically
                ) {
                    Text(group.heading, modifier = Modifier.weight(1f))
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

        // Expandable children
        AnimatedVisibility(
            visible = isExpanded,
            enter = expandVertically(),
            exit = shrinkVertically()
        ) {
            Column(modifier = Modifier.padding(start = 16.dp)) {
                group.children?.forEach { child ->
                    // Parse the child item data
                    val item = NativeUIParser.parseSideNavItem(child.data)
                    item?.let {
                        SideNavItemView(
                            item = it,
                            labelVisibility = labelVisibility,
                            onNavigate = onNavigate,
                            onCloseDrawer = onCloseDrawer,
                            modifier = Modifier.padding(horizontal = 12.dp, vertical = 2.dp)
                        )
                    }
                }
            }
        }
    }
}

/**
 * Renders a side nav header
 */
@Composable
private fun SideNavHeaderView(
    header: SideNavHeader,
    onNavigate: (String) -> Unit,
    onCloseDrawer: () -> Unit
) {
    val backgroundColor = header.backgroundColor?.let { parseColor(it) }

    Surface(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 12.dp, vertical = 8.dp),
        color = backgroundColor ?: MaterialTheme.colorScheme.surfaceVariant,
        shape = MaterialTheme.shapes.medium
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(16.dp),
            verticalAlignment = androidx.compose.ui.Alignment.CenterVertically
        ) {
            // Icon
            header.icon?.let { iconName ->
                MaterialIcon(
                    name = iconName,
                    contentDescription = header.title,
                    modifier = Modifier.padding(end = 16.dp),
                    size = 48.dp,
                    tint = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }

            // Title and subtitle
            Column(modifier = Modifier.weight(1f)) {
                header.title?.let { title ->
                    Text(
                        text = title,
                        style = MaterialTheme.typography.titleMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
                header.subtitle?.let { subtitle ->
                    Text(
                        text = subtitle,
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.7f)
                    )
                }
            }

            // Close button
            if (header.showCloseButton == true) {
                IconButton(onClick = onCloseDrawer) {
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

/**
 * Parse hex color string to Color
 */
private fun parseColor(colorString: String): Color? {
    return try {
        val hex = colorString.removePrefix("#")
        when (hex.length) {
            6 -> Color(android.graphics.Color.parseColor("#$hex"))
            8 -> Color(android.graphics.Color.parseColor("#$hex"))
            else -> null
        }
    } catch (e: Exception) {
        null
    }
}

/**
 * Parse badge color string to Color
 */
private fun parseBadgeColor(colorString: String?): Color {
    return when (colorString?.lowercase()) {
        "lime" -> Color(0xFF84CC16)
        "green" -> Color(0xFF22C55E)
        "blue" -> Color(0xFF3B82F6)
        "red" -> Color(0xFFEF4444)
        "yellow" -> Color(0xFFEAB308)
        "purple" -> Color(0xFFA855F7)
        "pink" -> Color(0xFFEC4899)
        "orange" -> Color(0xFFF97316)
        else -> Color(0xFF6366F1)  // Default indigo color
    }
}
