package com.nativephp.mobile.ui

import android.content.Intent
import android.net.Uri
import android.util.Log
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBars
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch

private const val TAG = "NativeTopBar"

/**
 * Material3 Top App Bar that renders from Laravel NativeUI state
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NativeTopBar(
    onMenuClick: () -> Unit,
    onNavigate: (String) -> Unit = {}
) {
    val topBarData by NativeUIState.topBarData
    val scope = rememberCoroutineScope()
    val context = LocalContext.current

    // Only render if we have top bar data
    if (topBarData == null) {
        return
    }

    val data = topBarData!!
    val backgroundColor = data.backgroundColor?.let { parseColor(it) }
    val textColor = data.textColor?.let { parseColor(it) }
    
    // Filter out top-level sections as standard action bar icons cannot logically be sections
    val topLevelComponents = data.children?.filter { it.type != "top_bar_section" } ?: emptyList()
    
    val handleActionClick: (TopBarActionData) -> Unit = { action ->
        Log.d(TAG, "⚡ Action clicked: ${action.label ?: action.id}")
        action.url?.let { url ->
            if (isExternalUrl(url)) {
                Log.d(TAG, "🌐 Opening external URL in browser: $url")
                try {
                    val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
                    context.startActivity(intent)
                } catch (e: Exception) {
                    Log.e(TAG, "Failed to open external URL: $url", e)
                }
            } else {
                Log.d(TAG, "📱 Opening internal URL in WebView: $url")
                onNavigate(url)
            }
        }
        action.event?.let {
            // Dispatch event if specified
            Log.d(TAG, "📢 Dispatching event: $it")
        }
    }

    // Split actions into visible (max 3) and overflow
    val visibleComponents = topLevelComponents.take(3)
    val overflowComponents = topLevelComponents.drop(3)
    val showOverflowMenu = remember { mutableStateOf(false) }

    TopAppBar(
        modifier = Modifier.windowInsetsPadding(WindowInsets.statusBars),
        title = {
            Column {
                Text(
                    text = data.title,
                    color = textColor ?: MaterialTheme.colorScheme.onSurface
                )
                data.subtitle?.let { subtitle ->
                    Text(
                        text = subtitle,
                        style = MaterialTheme.typography.bodySmall,
                        color = textColor?.copy(alpha = 0.7f) ?: MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f)
                    )
                }
            }
        },
        navigationIcon = {
            if (data.showNavigationIcon == true) {
                IconButton(onClick = {
                    Log.d(TAG, "🍔 Navigation icon clicked")
                    // Open the drawer via NativeUIState
                    scope.launch {
                        NativeUIState.drawerState?.open()
                        Log.d(TAG, "✅ Drawer opened!")
                    }
                    onMenuClick()
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
            // Render visible actions (max 3)
            visibleComponents.forEach { component ->
                val action = component.data
                val actionChildren = action.children ?: emptyList()

                if (actionChildren.isNotEmpty()) {
                    val showActionMenu = remember(action.id) { mutableStateOf(false) }

                    IconButton(onClick = { showActionMenu.value = true }) {
                        MaterialIcon(
                            name = action.icon ?: "menu",
                            contentDescription = action.label ?: action.id,
                            tint = textColor ?: MaterialTheme.colorScheme.onSurface
                        )
                    }

                    DropdownMenu(
                        expanded = showActionMenu.value,
                        onDismissRequest = { showActionMenu.value = false }
                    ) {
                        BuildMenuElements(
                            components = actionChildren,
                            textColor = textColor,
                            onDismiss = { showActionMenu.value = false },
                            handleActionClick = handleActionClick
                        )
                    }
                } else {
                    IconButton(onClick = { handleActionClick(action) }) {
                        MaterialIcon(
                            name = action.icon ?: "error",
                            contentDescription = action.label ?: action.id,
                            tint = textColor ?: MaterialTheme.colorScheme.onSurface
                        )
                    }
                }
            }

            // Overflow menu if more than 3 actions
            if (overflowComponents.isNotEmpty()) {
                IconButton(onClick = { showOverflowMenu.value = true }) {
                    MaterialIcon(
                        name = "more_vert",
                        contentDescription = "More options",
                        tint = textColor ?: MaterialTheme.colorScheme.onSurface
                    )
                }

                DropdownMenu(
                    expanded = showOverflowMenu.value,
                    onDismissRequest = { showOverflowMenu.value = false }
                ) {
                    BuildMenuElements(
                        components = overflowComponents,
                        textColor = textColor,
                        onDismiss = { showOverflowMenu.value = false },
                        handleActionClick = handleActionClick
                    )
                }
            }
        },
        colors = TopAppBarDefaults.topAppBarColors(
            containerColor = backgroundColor ?: MaterialTheme.colorScheme.surface,
            titleContentColor = textColor ?: MaterialTheme.colorScheme.onSurface,
            navigationIconContentColor = textColor ?: MaterialTheme.colorScheme.onSurface
        )
    )
}

/**
 * Recursively builds dropdown menu elements handling Sections and Action groupings.
 */
@Composable
private fun ColumnScope.BuildMenuElements(
    components: List<TopBarActionComponent>,
    textColor: Color?,
    onDismiss: () -> Unit,
    handleActionClick: (TopBarActionData) -> Unit
) {
    components.forEach { component ->
        if (component.type == "top_bar_section") {
            val section = component.data
            
            // Section Title - FIXED: Now checks for empty or blank strings
            if (!section.title.isNullOrBlank()) {
                Text(
                    text = section.title,
                    style = MaterialTheme.typography.labelMedium,
                    color = (textColor ?: MaterialTheme.colorScheme.onSurface).copy(alpha = 0.6f),
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp)
                )
            }
            
            // Section Children
            section.children?.let { children ->
                BuildMenuElements(children, textColor, onDismiss, handleActionClick)
            }
            
            // Appends a subtle divider after groups for cleaner structure
            Divider(color = (textColor ?: MaterialTheme.colorScheme.onSurface).copy(alpha = 0.1f))
            
        } else {
            val action = component.data
            val hasChildren = !action.children.isNullOrEmpty()

            val iconContent: @Composable (() -> Unit)? = if (!action.icon.isNullOrBlank()) {
                {
                    MaterialIcon(
                        name = action.icon!!,
                        contentDescription = action.label ?: action.id,
                        tint = textColor ?: MaterialTheme.colorScheme.onSurface
                    )
                }
            } else null

            if (hasChildren) {
                // Render an unclickable header, then recursively append its children.
                DropdownMenuItem(
                    text = { ActionTextContent(action, textColor) },
                    onClick = {},
                    enabled = false,
                    leadingIcon = iconContent
                )
                BuildMenuElements(action.children!!, textColor, onDismiss, handleActionClick)
            } else {
                DropdownMenuItem(
                    text = { ActionTextContent(action, textColor) },
                    onClick = {
                        onDismiss()
                        handleActionClick(action)
                    },
                    leadingIcon = iconContent
                )
            }
        }
    }
}

/**
 * Text renderer component supporting trailing subtitles natively in the DropdownMenuItem.
 */
@Composable
private fun ActionTextContent(action: TopBarActionData, textColor: Color?) {
    Column {
        Text(action.label ?: action.id ?: "")
        action.subtitle?.let { subtitle ->
            Text(
                text = subtitle,
                style = MaterialTheme.typography.bodySmall,
                color = (textColor ?: MaterialTheme.colorScheme.onSurface).copy(alpha = 0.7f)
            )
        }
    }
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