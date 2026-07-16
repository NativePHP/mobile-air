import Foundation

/// Centralized helper to convert icon names to SF Symbols
/// Used by both SideNav and BottomNav components
///
/// Features smart fallback:
/// 1. If icon contains ".", treat as direct SF Symbol path (e.g., "car.side.fill")
/// 2. Checks manual mapping for aliases and special cases
/// 3. Attempts auto-conversion to SF Symbol naming (e.g., "newspaper" -> "newspaper.fill")
/// 4. Falls back to default circle icon if not found
func getIconForName(_ iconName: String) -> String {
    // If icon contains a dot, assume it's a direct SF Symbol path
    // e.g., "car.side.fill", "airplane.circle", "figure.walk"
    if iconName.contains(".") {
        return iconName
    }

    // Check manual mappings for special cases and aliases
    let manualMapping = getManualMapping(iconName)
    if let mapping = manualMapping {
        return mapping
    }

    // Attempt smart fallback: try common SF Symbol patterns
    let autoSymbol = tryAutoConvertIcon(iconName)
    if let symbol = autoSymbol {
        print("✅ Auto-resolved icon: \(iconName) -> \(symbol)")
        return symbol
    }

    // Final fallback: default circle icon
    print("⚠️ Unknown icon: \(iconName), using default circle")
    return "circle.fill"
}

/// Manual icon mappings for aliases and special cases
private func getManualMapping(_ iconName: String) -> String? {
    switch iconName.lowercased() {
    // Common navigation icons
    case "dashboard":
        return "square.grid.2x2"
    case "home":
        return "house.fill"
    case "menu":
        return "line.3.horizontal"
    case "settings":
        return "gearshape.fill"
    case "account", "profile", "user":
        return "person.circle.fill"
    case "person":
        return "person.fill"

    // Business/commerce icons
    case "orders", "receipt":
        return "receipt.fill"
    case "cart", "shopping":
        return "cart.fill"
    case "shop", "store":
        return "storefront.fill"
    case "products", "inventory":
        return "shippingbox.fill"

    // Charts and data
    case "chart", "barchart":
        return "chart.bar.fill"
    case "analytics":
        return "chart.xyaxis.line"
    case "summary", "report", "assessment":
        return "doc.text.fill"

    // Time and scheduling
    case "clock", "schedule", "time":
        return "clock.fill"
    case "calendar":
        return "calendar"
    case "history":
        return "clock.arrow.circlepath"

    // Actions
    case "add", "plus":
        return "plus.circle.fill"
    case "edit":
        return "pencil"
    case "delete":
        return "trash.fill"
    case "save":
        return "square.and.arrow.down.fill"
    case "search":
        return "magnifyingglass"
    case "filter":
        return "line.3.horizontal.decrease.circle"
    case "refresh":
        return "arrow.clockwise"
    case "repeat":
        return "arrow.2.squarepath"
    case "arrow_back":
        return "arrow.left"
    case "share":
        return "square.and.arrow.up"
    case "download":
        return "arrow.down.circle.fill"
    case "upload":
        return "arrow.up.circle.fill"

    // Communication
    case "notifications":
        return "bell.fill"
    case "message":
        return "message.fill"
    case "email", "mail":
        return "envelope.fill"
    case "chat":
        return "bubble.left.and.bubble.right.fill"
    case "chat_bubble", "chat_bubble_outline":
        return "bubble.left"
    case "phone":
        return "phone.fill"

    // Navigation arrows. The `chevron_*` aliases are mapped explicitly because
    // tryAutoConvertIcon strips the underscore (→ `chevronright`), which is NOT
    // a valid SF Symbol — the correct names are dotted (`chevron.right`).
    case "back", "chevron_left":
        return "chevron.left"
    case "forward", "chevron_right":
        return "chevron.right"
    case "up", "chevron_up":
        return "chevron.up"
    case "down", "chevron_down":
        return "chevron.down"
    case "expand_less":
        return "chevron.up"
    case "expand_more":
        return "chevron.down"

    // Media controls — Material names → SF Symbol equivalents.
    case "play_arrow":
        return "play.fill"
    case "play_circle", "play_circle_filled":
        return "play.circle.fill"
    case "pause":
        return "pause.fill"
    case "stop":
        return "stop.fill"
    case "skip_next":
        return "forward.fill"
    case "skip_previous":
        return "backward.fill"
    case "volume_up":
        return "speaker.wave.3.fill"
    case "volume_off":
        return "speaker.slash.fill"
    case "playlist_add":
        return "text.badge.plus"
    case "cast":
        return "tv.and.hifispeaker.fill"
    case "videocam":
        return "video.fill"

    // Reactions — Material thumb_up / thumb_down Have outlined +
    // filled variants; map to SF Symbols' hand.thumbs* family.
    case "thumb_up":
        return "hand.thumbsup.fill"
    case "thumb_up_off_alt", "thumb_up_outline":
        return "hand.thumbsup"
    case "thumb_down":
        return "hand.thumbsdown.fill"
    case "thumb_down_off_alt", "thumb_down_outline":
        return "hand.thumbsdown"

    // Status
    case "verified":
        return "checkmark.seal.fill"
    case "check", "done":
        return "checkmark.circle.fill"
    case "close":
        return "xmark.circle.fill"

    // Form controls — Material-style names mapped to nearest SF Symbol so
    // composed-in-Blade checkboxes / radios look native on iOS without
    // platform-specific icon names in the template.
    case "check_box", "checkbox":
        return "checkmark.square.fill"
    case "check_box_outline", "check_box_outline_blank", "checkbox_outline":
        return "square"
    case "radio_button_checked", "radio_checked":
        return "circle.inset.filled"
    case "radio_button_unchecked", "radio_unchecked", "radio_button":
        return "circle"
    case "warning":
        return "exclamationmark.triangle.fill"
    case "error":
        return "exclamationmark.circle.fill"
    case "info":
        return "info.circle.fill"

    // Auth
    case "login":
        return "arrow.right.square.fill"
    case "logout", "exit":
        return "arrow.left.square.fill"
    case "lock":
        return "lock.fill"
    case "unlock":
        return "lock.open.fill"

    // Content
    case "favorite", "heart":
        return "heart.fill"
    case "favorite_border", "heart_outline":
        return "heart"
    case "star":
        return "star.fill"
    case "bookmark":
        return "bookmark.fill"
    case "bookmark_border", "bookmark_outline":
        return "bookmark"
    case "image", "photo":
        return "photo.fill"
    case "image-plus":
        return "photo.badge.plus"
    case "video":
        return "video.fill"
    case "folder":
        return "folder.fill"
    case "folder-lock":
        return "folder.fill.badge.minus"
    case "file", "description":
        return "doc.text.fill"
    case "book-open":
        return "book.fill"

    // Device & Hardware
    case "camera":
        return "camera.fill"
    case "qrcode", "qr-code", "qr":
        return "qrcode"
    case "device-phone-mobile", "smartphone":
        return "iphone"
    case "vibrate":
        return "iphone.radiowaves.left.and.right"
    case "bell":
        return "bell.fill"
    case "finger-print", "fingerprint":
        return "touchid"
    case "light-bulb", "lightbulb", "flashlight":
        return "lightbulb.fill"
    case "map", "location":
        return "map.fill"
    case "globe-alt", "globe", "web":
        return "globe"
    case "bolt", "flash":
        return "bolt.fill"
    case "speaker":
        return "speaker.wave.3.fill"
    case "speaker-muted", "speaker-off", "mute":
        return "speaker.slash.fill"

    // Communication (extended)
    case "chat-bubble-left-right", "chat-bubbles":
        return "bubble.left.and.bubble.right.fill"

    // Misc
    case "help":
        return "questionmark.circle.fill"
    case "about", "information-circle":
        return "info.circle.fill"
    case "more":
        return "ellipsis.circle.fill"
    case "ellipsis", "more_vert", "more_horiz":
        // SF Symbols `ellipsis` (horizontal three dots) is well-supported
        // across all iOS versions. Auto-conversion otherwise produces
        // `ellipsis.fill` which renders as nothing on some iOS versions.
        return "ellipsis"
    case "list":
        return "list.bullet"
    case "visibility":
        return "eye.fill"
    case "visibility_off":
        return "eye.slash.fill"

    default:
        return nil  // No manual mapping found
    }
}

/// Last-line normaliser for raw icon strings that aren't already in
/// canonical SF form and don't appear in the manual mapping above.
///
/// Returns the input lower-cased with `_` / `-` collapsed — that's the
/// shape SF Symbol names actually take. **Crucially, it does NOT
/// append `.fill`.** Many valid SF symbols (`archivebox`, `bell`,
/// `house`, `trash`, …) ship as the unfilled glyph at that bare name,
/// and auto-appending `.fill` silently swaps them for their filled
/// variant. The `(name, sf, material)` API on the PHP side now passes
/// canonical SF names through (e.g. `SF::Archivebox` = `archivebox`),
/// so the right thing is to leave them alone here. Callers that want
/// the filled glyph use the `*.fill` enum case (e.g.
/// `SF::ArchiveboxFill`).
///
/// `Image(systemName:)` shows a placeholder for unknown names, which is
/// the right diagnostic — surfaces typos to the developer instead of
/// hiding them behind a wrong-but-valid filled fallback.
private func tryAutoConvertIcon(_ iconName: String) -> String? {
    let normalized = iconName.replacingOccurrences(of: "-", with: "")
        .replacingOccurrences(of: "_", with: "")
        .lowercased()

    return normalized.isEmpty ? nil : normalized
}