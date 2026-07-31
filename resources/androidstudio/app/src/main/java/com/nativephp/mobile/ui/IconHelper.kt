package com.nativephp.mobile.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.material3.LocalContentColor
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.material3.Text
import com.nativephp.mobile.R

/**
 * Material Icons font family - uses ligatures to render icons by name
 * The font file is ~348KB vs ~30MB for material-icons-extended
 */
val MaterialIconsFont = FontFamily(
    Font(R.font.material_icons)
)

/**
 * Renders a Material Icon using the font-based ligature approach.
 * This composable is a drop-in replacement for Icon(imageVector = ...).
 *
 * Users can specify ANY Material Icon by its exact ligature name.
 * See: https://fonts.google.com/icons for available icons
 *
 * Common icon names: home, settings, search, menu, close, add, delete,
 * person, notifications, shopping_cart, favorite, star, etc.
 *
 * @param name The Material Icon ligature name (e.g., "settings", "qr_code_2", "home")
 * @param contentDescription Accessibility description (matches Icon API)
 * @param modifier Modifier for the icon
 * @param tint Icon color (defaults to LocalContentColor)
 */
@Composable
fun MaterialIcon(
    name: String,
    contentDescription: String?,
    modifier: Modifier = Modifier,
    tint: Color = LocalContentColor.current
) {
    Box(
        modifier = modifier.size(24.dp),
        contentAlignment = Alignment.Center
    ) {
        Text(
            text = getIconName(name),
            fontFamily = MaterialIconsFont,
            fontSize = 24.sp,
            color = tint,
            textAlign = TextAlign.Center
        )
    }
}

/**
 * Overload with custom size support
 */
@Composable
fun MaterialIcon(
    name: String,
    contentDescription: String?,
    modifier: Modifier = Modifier,
    size: Dp = 24.dp,
    tint: Color = LocalContentColor.current
) {
    Box(
        modifier = modifier.size(size),
        contentAlignment = Alignment.Center
    ) {
        Text(
            text = getIconName(name),
            fontFamily = MaterialIconsFont,
            fontSize = size.value.sp,
            color = tint,
            textAlign = TextAlign.Center
        )
    }
}

/**
 * Get the Material Icon ligature name for the given icon name.
 *
 * Flow:
 * 1. Check manual mapping for aliases (e.g., "home" -> "home", "cart" -> "shopping_cart")
 * 2. Normalize the name (kebab-case to underscore)
 * 3. Return for font ligature rendering
 *
 * @param iconName The icon name from EDGE JSON
 * @return Material Icons font ligature name
 */
fun getIconName(iconName: String): String {
    // Check manual mapping first for aliases
    val mapped = getManualMapping(iconName)
    if (mapped != null) {
        return mapped
    }

    // Normalize: kebab-case to underscore, lowercase
    return iconName.lowercase().replace("-", "_")
}

/**
 * Manual icon mappings for aliases and cross-platform consistency.
 * Maps friendly names to Material Icons ligature names.
 */
private fun getManualMapping(iconName: String): String? {
    return when (iconName.lowercase()) {
        // Common navigation icons
        "dashboard" -> "dashboard"
        "home" -> "home"
        "menu" -> "menu"
        "settings" -> "settings"
        "account", "profile", "user" -> "account_circle"
        "person" -> "person"
        "people", "connections", "contacts" -> "people"
        "group", "groups" -> "group"

        // Business/commerce icons
        "orders", "receipt" -> "receipt"
        "cart", "shopping" -> "shopping_cart"
        "shop", "store" -> "store"
        "products", "inventory" -> "inventory"

        // Charts and data
        "chart", "barchart" -> "bar_chart"
        "analytics" -> "analytics"
        "summary", "report", "assessment" -> "assessment"

        // Time and scheduling
        "clock", "schedule", "time" -> "schedule"
        "calendar" -> "calendar_today"
        "history" -> "history"

        // Actions
        "add", "plus" -> "add"
        "edit" -> "edit"
        "delete" -> "delete"
        "save" -> "save"
        "search" -> "search"
        "filter" -> "filter_list"
        "refresh" -> "refresh"
        "share" -> "share"
        "download" -> "download"
        "upload" -> "upload"

        // Communication
        "notifications" -> "notifications"
        "message" -> "message"
        "email", "mail" -> "email"
        "chat" -> "chat"
        "phone" -> "phone"

        // Navigation arrows
        "back" -> "arrow_back"
        "forward" -> "arrow_forward"
        "up" -> "arrow_upward"
        "down" -> "arrow_downward"

        // Status
        "check", "done" -> "check"
        "close" -> "close"
        "warning" -> "warning"
        "error" -> "error"
        "info" -> "info"

        // Auth
        "login" -> "login"
        "logout", "exit" -> "logout"
        "lock" -> "lock"
        "unlock" -> "lock_open"

        // Content
        "favorite", "heart" -> "favorite"
        "star" -> "star"
        "bookmark" -> "bookmark"
        "image", "photo" -> "image"
        "image-plus" -> "add_photo_alternate"
        "video" -> "video_library"
        "folder" -> "folder"
        "folder-lock" -> "folder_off"
        "file", "description" -> "description"
        "book-open" -> "menu_book"
        "newspaper", "news", "article" -> "article"

        // Device & Hardware
        "camera" -> "camera_alt"
        "device-phone-mobile", "smartphone" -> "smartphone"
        "vibrate" -> "vibration"
        "bell" -> "notifications"
        "finger-print", "fingerprint" -> "fingerprint"
        "light-bulb", "lightbulb", "flashlight" -> "lightbulb"
        "map", "location" -> "map"
        "globe-alt", "globe", "web" -> "public"
        "bolt", "flash" -> "bolt"
        "qr", "qrcode", "qr-code" -> "qr_code_2"

        // Audio & Speaker icons
        "speaker", "speaker-wave" -> "volume_up"
        "volume-up" -> "volume_up"
        "volume-down" -> "volume_down"
        "volume-mute", "mute" -> "volume_mute"
        "volume-off" -> "volume_off"
        "music", "audio", "music-note" -> "music_note"
        "microphone", "mic" -> "mic"

        // Communication (extended)
        "chat-bubble-left-right", "chat-bubbles" -> "chat_bubble"

        // Misc
        "help" -> "help"
        "about", "information-circle" -> "info"
        "more" -> "more_vert"
        "list" -> "list"
        "visibility" -> "visibility"
        "visibility_off" -> "visibility_off"
        "expand_less" -> "expand_less"
        "expand_more" -> "expand_more"

        else -> null  // No mapping, will use normalized name
    }
}