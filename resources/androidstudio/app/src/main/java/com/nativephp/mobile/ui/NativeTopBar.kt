package com.nativephp.mobile.ui

import android.content.Intent
import android.net.Uri
import android.util.Log
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.statusBars
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
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
    val actions = data.children?.mapNotNull { it.data } ?: emptyList()
    val handleActionClick: (TopBarAction) -> Unit = { action ->
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
    val visibleActions = actions.take(3)
    val overflowActions = actions.drop(3)
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
            visibleActions.forEach { action ->
                val actionChildren = action.children?.mapNotNull { it.data } ?: emptyList()

                if (actionChildren.isNotEmpty()) {
                    val showActionMenu = remember(action.id) { mutableStateOf(false) }

                    IconButton(onClick = { showActionMenu.value = true }) {
                        MaterialIcon(
                            name = action.icon,
                            contentDescription = action.label ?: action.id,
                            tint = textColor ?: MaterialTheme.colorScheme.onSurface
                        )
                    }

                    DropdownMenu(
                        expanded = showActionMenu.value,
                        onDismissRequest = { showActionMenu.value = false }
                    ) {
                        actionChildren.forEach { child ->
                            DropdownMenuItem(
                                text = { Text(child.label ?: child.id) },
                                onClick = {
                                    showActionMenu.value = false
                                    handleActionClick(child)
                                },
                                leadingIcon = {
                                    MaterialIcon(
                                        name = child.icon,
                                        contentDescription = child.label ?: child.id
                                    )
                                }
                            )
                        }
                    }
                } else {
                    IconButton(onClick = { handleActionClick(action) }) {
                        MaterialIcon(
                            name = action.icon,
                            contentDescription = action.label ?: action.id,
                            tint = textColor ?: MaterialTheme.colorScheme.onSurface
                        )
                    }
                }
            }

            // Overflow menu if more than 3 actions
            if (overflowActions.isNotEmpty()) {
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
                    overflowActions.forEach { action ->
                        val actionChildren = action.children?.mapNotNull { it.data } ?: emptyList()

                        if (actionChildren.isNotEmpty()) {
                            DropdownMenuItem(
                                text = { Text(action.label ?: action.id) },
                                onClick = {},
                                enabled = false,
                                leadingIcon = {
                                    MaterialIcon(
                                        name = action.icon,
                                        contentDescription = action.label ?: action.id
                                    )
                                }
                            )

                            actionChildren.forEach { child ->
                                DropdownMenuItem(
                                    text = { Text(child.label ?: child.id) },
                                    onClick = {
                                        showOverflowMenu.value = false
                                        handleActionClick(child)
                                    },
                                    leadingIcon = {
                                        MaterialIcon(
                                            name = child.icon,
                                            contentDescription = child.label ?: child.id
                                        )
                                    }
                                )
                            }
                        } else {
                            DropdownMenuItem(
                                text = { Text(action.label ?: action.id) },
                                onClick = {
                                    showOverflowMenu.value = false
                                    handleActionClick(action)
                                },
                                leadingIcon = {
                                    MaterialIcon(
                                        name = action.icon,
                                        contentDescription = action.label ?: action.id
                                    )
                                }
                            )
                        }
                    }
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