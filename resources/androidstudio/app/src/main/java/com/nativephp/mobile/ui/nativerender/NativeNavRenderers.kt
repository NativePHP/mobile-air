package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.content.Intent
import android.content.res.Configuration
import android.graphics.Color
import android.graphics.Typeface
import android.net.Uri
import android.util.Log
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import com.nativephp.mobile.ui.MaterialIcon
import com.nativephp.mobile.ui.NativeUIState
import com.nativephp.mobile.ui.getIconName
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

private const val TAG = "NativeNavRenderers"

// ── Top Bar (View-based) ──

class TopBarViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        val container = LinearLayout(context)
        container.orientation = LinearLayout.HORIZONTAL
        container.gravity = Gravity.CENTER_VERTICAL
        applyProps(container, context, node)
        return container
    }

    override fun updateView(view: View, node: NativeUINode) {
        val container = view as? LinearLayout ?: return
        container.removeAllViews()
        applyProps(container, view.context, node)
    }

    private fun applyProps(container: LinearLayout, context: Context, node: NativeUINode) {
        val props = node.props
        val title = props.getString("title", "")
        val textColorStr = props.getString("text_color")
        val textColor = parseHexColorInt(textColorStr)

        val density = context.resources.displayMetrics.density

        container.setPadding(
            (16 * density).toInt(),
            (8 * density).toInt(),
            (16 * density).toInt(),
            (8 * density).toInt()
        )

        // Nav icon (hamburger menu)
        val showNavIcon = props.getBool("show_navigation_icon", true)
        if (showNavIcon) {
            val navIcon = createIconTextView(context, "menu", 24f, textColor ?: Color.BLACK)
            navIcon.setPadding((8 * density).toInt(), (8 * density).toInt(), (16 * density).toInt(), (8 * density).toInt())
            navIcon.setOnClickListener {
                Log.d(TAG, "Navigation icon clicked — opening drawer")
                NativeUIState.drawerScope?.launch {
                    NativeUIState.drawerState?.open()
                }
            }
            container.addView(navIcon)
        }

        // Title
        val titleView = TextView(context)
        titleView.text = title
        titleView.textSize = 20f
        titleView.typeface = Typeface.create(Typeface.DEFAULT, Typeface.BOLD)
        titleView.setTextColor(textColor ?: Color.BLACK)
        titleView.layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f)
        container.addView(titleView)

        // Action buttons
        val actions = node.children.filter { it.type == "top_bar_action" }
        for (action in actions.take(3)) {
            val aProps = action.props
            val icon = aProps.getString("icon", "more_vert")
            val url = aProps.getString("url")

            val iconView = createIconTextView(context, icon, 24f, textColor ?: Color.BLACK)
            iconView.setPadding((8 * density).toInt(), (8 * density).toInt(), (8 * density).toInt(), (8 * density).toInt())
            iconView.setOnClickListener {
                if (url.isNotEmpty() && isExternalUrl(url)) {
                    try {
                        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                    } catch (e: Exception) {
                        Log.e(TAG, "Failed to open URL: $url", e)
                    }
                } else if (action.onPress != 0) {
                    NativeElementBridge.sendPressEvent(action.onPress, action.id)
                }
            }
            container.addView(iconView)
        }
    }
}

// ── Bottom Nav (View-based) ──

class BottomNavViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        val container = LinearLayout(context)
        container.orientation = LinearLayout.HORIZONTAL
        val dark = (context.resources.configuration.uiMode and
            Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
        container.setBackgroundColor(if (dark) Color.parseColor("#1C1C1E") else Color.WHITE)
        applyProps(container, context, node)
        return container
    }

    override fun updateView(view: View, node: NativeUINode) {
        val container = view as? LinearLayout ?: return
        container.removeAllViews()
        applyProps(container, view.context, node)
    }

    private fun applyProps(container: LinearLayout, context: Context, node: NativeUINode) {
        val items = node.children.filter { it.type == "bottom_nav_item" }
        if (items.isEmpty()) return

        val density = context.resources.displayMetrics.density
        container.elevation = 8 * density

        for (item in items) {
            val iProps = item.props
            val label = iProps.getString("label", "")
            val icon = iProps.getString("icon", "circle")
            val active = iProps.getBool("active")

            val itemLayout = LinearLayout(context)
            itemLayout.orientation = LinearLayout.VERTICAL
            itemLayout.gravity = Gravity.CENTER
            itemLayout.setPadding(0, (8 * density).toInt(), 0, (8 * density).toInt())
            itemLayout.layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f)

            val activeColor = if (active) Color.parseColor("#1976D2") else Color.parseColor("#757575")

            val iconView = createIconTextView(context, icon, 24f, activeColor)
            itemLayout.addView(iconView)

            if (label.isNotEmpty()) {
                val labelView = TextView(context)
                labelView.text = label
                labelView.textSize = 12f
                labelView.setTextColor(activeColor)
                labelView.gravity = Gravity.CENTER
                itemLayout.addView(labelView)
            }

            itemLayout.setOnClickListener {
                if (item.onPress != 0) {
                    NativeElementBridge.sendPressEvent(item.onPress, item.id)
                }
            }

            container.addView(itemLayout)
        }
    }
}

// ── Side Nav (View-based — renders nothing, stores node for drawer) ──

class SideNavViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        NativeEdgeDrawerState.sideNavNode.value = node
        val v = View(context)
        v.visibility = View.GONE
        return v
    }

    override fun updateView(view: View, node: NativeUINode) {
        NativeEdgeDrawerState.sideNavNode.value = node
    }
}

// ── Empty Renderer ──

class EmptyViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        val v = View(context)
        v.visibility = View.GONE
        return v
    }

    override fun updateView(view: View, node: NativeUINode) {}
}

// ── Side Nav Drawer State (Compose bridge) ──

internal object NativeEdgeDrawerState {
    val sideNavNode = mutableStateOf<NativeUINode?>(null)
}

/**
 * Renders the drawer content from a side_nav NativeUINode tree.
 * Called from NativeSideDrawer when native EDGE data is available.
 * This remains Compose-based since the drawer itself is managed by Compose Scaffold.
 */
@Composable
internal fun RenderSideNavDrawerContent(
    node: NativeUINode,
    onCloseDrawer: (() -> Unit) -> Unit,
    onNavigate: ((String) -> Unit)? = null
) {
    val labelVisibility = node.props.getString("label_visibility", "labeled")

    val pinnedHeaders = node.children.filter {
        it.type == "side_nav_header" && it.props.getBool("pinned")
    }
    val scrollableChildren = node.children.filter {
        !(it.type == "side_nav_header" && it.props.getBool("pinned"))
    }

    val expandedGroups = remember { mutableStateMapOf<String, Boolean>() }

    ModalDrawerSheet {
        Column(modifier = Modifier.fillMaxHeight()) {
            pinnedHeaders.forEach { child ->
                RenderSideNavHeaderContent(child, onCloseDrawer)
            }

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
                        contentColor = androidx.compose.ui.graphics.Color.White
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

// ── Utility ──

private fun createIconTextView(context: Context, iconName: String, size: Float, color: Int): TextView {
    val tv = TextView(context)
    tv.text = getIconName(iconName)
    tv.typeface = IconViewRenderer.getMaterialIconsTypeface(context)
    tv.textSize = size
    tv.setTextColor(color)
    tv.gravity = Gravity.CENTER
    return tv
}

private fun parseHexColor(hex: String): androidx.compose.ui.graphics.Color? {
    if (hex.isEmpty()) return null
    return try {
        val sanitized = hex.removePrefix("#")
        when (sanitized.length) {
            6 -> androidx.compose.ui.graphics.Color(android.graphics.Color.parseColor("#$sanitized"))
            8 -> androidx.compose.ui.graphics.Color(android.graphics.Color.parseColor("#$sanitized"))
            else -> null
        }
    } catch (e: Exception) {
        null
    }
}

private fun parseHexColorInt(hex: String): Int? {
    if (hex.isEmpty()) return null
    return try {
        val sanitized = hex.removePrefix("#")
        when (sanitized.length) {
            6 -> android.graphics.Color.parseColor("#$sanitized")
            8 -> android.graphics.Color.parseColor("#$sanitized")
            else -> null
        }
    } catch (e: Exception) {
        null
    }
}

private fun parseBadgeColor(colorString: String): androidx.compose.ui.graphics.Color {
    return when (colorString.lowercase()) {
        "lime" -> androidx.compose.ui.graphics.Color(0xFF84CC16)
        "green" -> androidx.compose.ui.graphics.Color(0xFF22C55E)
        "blue" -> androidx.compose.ui.graphics.Color(0xFF3B82F6)
        "red" -> androidx.compose.ui.graphics.Color(0xFFEF4444)
        "yellow" -> androidx.compose.ui.graphics.Color(0xFFEAB308)
        "purple" -> androidx.compose.ui.graphics.Color(0xFFA855F7)
        "pink" -> androidx.compose.ui.graphics.Color(0xFFEC4899)
        "orange" -> androidx.compose.ui.graphics.Color(0xFFF97316)
        else -> androidx.compose.ui.graphics.Color(0xFF6366F1)
    }
}

private fun isExternalUrl(url: String): Boolean {
    return (url.startsWith("http://") || url.startsWith("https://"))
            && !url.contains("127.0.0.1")
            && !url.contains("localhost")
}
