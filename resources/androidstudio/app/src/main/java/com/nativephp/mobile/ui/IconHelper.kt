package com.nativephp.mobile.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.material3.LocalContentColor
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.PlatformTextStyle
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.style.LineHeightStyle
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
            textAlign = TextAlign.Center,
            // Icons are rendered as an icon-font glyph; like regular text, Android's
            // default font padding pushes the glyph off-center inside its box, so it
            // doesn't vertically align with adjacent text (e.g. the X engagement row).
            // Strip the padding/line-height so the glyph sits centered in the box.
            style = TextStyle(
                platformStyle = PlatformTextStyle(includeFontPadding = false),
                lineHeightStyle = LineHeightStyle(
                    alignment = LineHeightStyle.Alignment.Center,
                    trim = LineHeightStyle.Trim.Both
                )
            )
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
            textAlign = TextAlign.Center,
            // Icons are rendered as an icon-font glyph; like regular text, Android's
            // default font padding pushes the glyph off-center inside its box, so it
            // doesn't vertically align with adjacent text (e.g. the X engagement row).
            // Strip the padding/line-height so the glyph sits centered in the box.
            style = TextStyle(
                platformStyle = PlatformTextStyle(includeFontPadding = false),
                lineHeightStyle = LineHeightStyle(
                    alignment = LineHeightStyle.Alignment.Center,
                    trim = LineHeightStyle.Trim.Both
                )
            )
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
        "group", "groups", "users", "user-group" -> "group"

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
        "code", "code-bracket", "code-bracket-square" -> "code"
        "git-branch", "git-fork" -> "fork_right"
        "archive", "archive-box" -> "archive"
        "cube", "package" -> "inventory_2"
        "newspaper", "news", "article" -> "article"

        // Device & Hardware
        "camera" -> "camera_alt"
        "device-phone-mobile", "smartphone" -> "smartphone"
        "vibrate" -> "vibration"
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

        // SF Symbols — Charts & Data
        "chart.bar", "chart.bar.fill" -> "bar_chart"
        "chart.line.uptrend.xyaxis" -> "show_chart"
        "chart.pie", "chart.pie.fill" -> "pie_chart"

        // SF Symbols — People
        "person.fill" -> "person"
        "person.2", "person.2.fill" -> "people"
        "person.3", "person.3.fill" -> "group"
        "person.crop.circle", "person.crop.circle.fill" -> "account_circle"

        // SF Symbols — Chevrons / Code
        "chevron.left/chevron.right" -> "code"
        "chevron.left.forwardslash.chevron.right" -> "code"
        "chevron.left" -> "chevron_left"
        "chevron.right" -> "chevron_right"
        "chevron.up" -> "expand_less"
        "chevron.down" -> "expand_more"

        // SF Symbols — Navigation
        "house", "house.fill" -> "home"
        "gearshape", "gearshape.fill", "gear" -> "settings"
        "arrow.left" -> "arrow_back"
        "arrow.right" -> "arrow_forward"
        "arrow.up" -> "arrow_upward"
        "arrow.down" -> "arrow_downward"
        "xmark" -> "close"
        "xmark.circle", "xmark.circle.fill" -> "cancel"
        "line.3.horizontal" -> "menu"

        // SF Symbols — Actions
        "plus" -> "add"
        "plus.circle", "plus.circle.fill" -> "add_circle"
        "minus" -> "remove"
        "minus.circle", "minus.circle.fill" -> "remove_circle"
        "pencil", "pencil.circle" -> "edit"
        "trash", "trash.fill" -> "delete"
        "square.and.arrow.up", "square.and.arrow.up.fill" -> "share"
        "square.and.arrow.down", "square.and.arrow.down.fill" -> "download"
        "magnifyingglass" -> "search"
        "arrow.clockwise" -> "refresh"
        "arrow.2.circlepath" -> "sync"

        // SF Symbols — Communication
        "bell", "bell.fill" -> "notifications"
        "envelope", "envelope.fill" -> "email"
        "bubble.left", "bubble.left.fill" -> "chat"
        "bubble.left.and.bubble.right", "bubble.left.and.bubble.right.fill" -> "chat_bubble"
        "phone.fill" -> "phone"

        // SF Symbols — Content
        "heart", "heart.fill" -> "favorite"
        "star.fill" -> "star"
        "bookmark.fill" -> "bookmark"
        "photo", "photo.fill" -> "image"
        "folder.fill" -> "folder"
        "doc", "doc.fill" -> "description"
        "book", "book.fill" -> "menu_book"
        "newspaper.fill" -> "article"

        // SF Symbols — Status
        "checkmark" -> "check"
        "checkmark.circle", "checkmark.circle.fill" -> "check_circle"
        "exclamationmark.triangle", "exclamationmark.triangle.fill" -> "warning"
        "exclamationmark.circle", "exclamationmark.circle.fill" -> "error"
        "info.circle", "info.circle.fill" -> "info"

        // SF Symbols — Auth
        "lock.fill" -> "lock"
        "lock.open", "lock.open.fill" -> "lock_open"

        // SF Symbols — Device & Hardware
        "camera.fill" -> "camera_alt"
        "iphone" -> "smartphone"
        "hand.point.up.braille.fill" -> "fingerprint"
        "mappin", "mappin.circle", "mappin.circle.fill" -> "place"
        "map.fill" -> "map"
        "globe" -> "public"
        "bolt.fill" -> "bolt"
        "qrcode" -> "qr_code_2"
        "flashlight.on.fill" -> "flashlight_on"

        // SF Symbols — Audio
        "speaker.wave.2", "speaker.wave.2.fill" -> "volume_up"
        "speaker.slash", "speaker.slash.fill" -> "volume_off"
        "music.note" -> "music_note"
        "mic.fill" -> "mic"

        // SF Symbols — Common
        "paperplane", "paperplane.fill" -> "send"
        "flame", "flame.fill" -> "local_fire_department"
        "plus.message", "plus.message.fill" -> "add_comment"
        "qrcode.viewfinder" -> "qr_code_scanner"
        "person.fill.badge.plus", "person.badge.plus" -> "person_add"
        "person.crop.circle.badge.plus" -> "person_add"
        "arrow.triangle.2.circlepath" -> "sync"
        "face.smiling", "face.smiling.fill" -> "mood"
        "paperclip" -> "attach_file"
        "doc.on.doc", "doc.on.doc.fill" -> "content_copy"
        "video", "video.fill" -> "videocam"

        // SF Symbols — Editor & Text
        "square.and.pencil" -> "edit"
        "textformat", "textformat.alt", "textformat.size" -> "text_fields"
        "square.text.square", "doc.text" -> "text_snippet"
        "doc.plaintext", "doc.plaintext.fill" -> "description"

        // SF Symbols — Layout & Grid
        "rectangle.3.group", "rectangle.3.group.fill", "square.grid.3x3" -> "view_module"
        "rectangle.grid.2x2" -> "grid_view"
        "rectangle.split.3x1" -> "view_column"

        // SF Symbols — Media
        "play.rectangle", "play.rectangle.fill" -> "play_circle"
        "play.tv", "play.tv.fill" -> "smart_display"
        "tv", "tv.fill" -> "tv"
        "film", "film.fill" -> "movie"

        // SF Symbols — Furniture & Lifestyle
        "bed.double", "bed.double.fill" -> "bed"
        "sofa", "sofa.fill" -> "weekend"
        "house.lodge", "house.lodge.fill" -> "cottage"

        // SF Symbols — Misc
        "eye", "eye.fill" -> "visibility"
        "eye.slash", "eye.slash.fill" -> "visibility_off"
        "ellipsis" -> "more_horiz"
        "ellipsis.circle" -> "more_vert"
        "list.bullet" -> "list"
        "questionmark.circle" -> "help"
        "clock.fill" -> "schedule"
        "link" -> "link"
        "paintbrush", "paintbrush.fill" -> "brush"
        "slider.horizontal.3" -> "tune"
        "square.grid.2x2", "square.grid.2x2.fill" -> "grid_view"
        "rectangle.stack", "rectangle.stack.fill" -> "layers"
        "shippingbox", "shippingbox.fill" -> "inventory_2"
        "tag", "tag.fill" -> "label"
        "flag", "flag.fill" -> "flag"
        "hand.thumbsup", "hand.thumbsup.fill" -> "thumb_up"
        "hand.thumbsdown", "hand.thumbsdown.fill" -> "thumb_down"
        "wifi" -> "wifi"
        "airplane" -> "flight"
        "power" -> "power_settings_new"
        "moon", "moon.fill" -> "dark_mode"
        "sun.max", "sun.max.fill" -> "light_mode"
        "cloud", "cloud.fill" -> "cloud"
        "location.fill" -> "my_location"

        // SF Symbols — Notifications & messaging
        "bell.badge", "bell.badge.fill" -> "notifications_active"
        "bell.slash", "bell.slash.fill" -> "notifications_off"
        "tray.full", "tray.full.fill" -> "inbox"
        "exclamationmark.bubble", "exclamationmark.bubble.fill" -> "feedback"
        "text.bubble", "text.bubble.fill" -> "sms"

        // SF Symbols — System & hardware (extended)
        "iphone.radiowaves.left.and.right" -> "vibration"
        "safari", "safari.fill" -> "explore"
        "faceid" -> "face"
        "checkmark.shield", "checkmark.shield.fill" -> "verified_user"
        "creditcard", "creditcard.fill" -> "credit_card"

        // SF Symbols — Location (extended)
        "location.north.line", "location.north.line.fill" -> "navigation"
        "location.viewfinder" -> "gps_fixed"

        // SF Symbols — Media transport
        "play", "play.fill" -> "play_arrow"
        "pause", "pause.fill" -> "pause"
        "stop", "stop.fill" -> "stop"
        "photo.on.rectangle", "photo.on.rectangle.angled" -> "photo_library"

        // SF Symbols — Time & misc (extended)
        "calendar.badge.plus" -> "event"
        "clock.arrow.circlepath" -> "history"
        "rectangle.and.hand.point.up.left", "rectangle.and.hand.point.up.left.fill" -> "touch_app"
        "infinity" -> "all_inclusive"
        "zzz" -> "bedtime"

        // SF Symbols — Gestures, effects & misc (added for the demo launcher)
        "hand.tap", "hand.tap.fill" -> "touch_app"
        "sparkles" -> "auto_awesome"
        "drop", "drop.fill" -> "water_drop"
        "list.bullet.rectangle" -> "list_alt"
        "square.on.square" -> "filter_none"
        "arrow.left.arrow.right", "arrow.left.and.right" -> "swap_horiz"

        else -> null  // No mapping, will use normalized name
    }
}