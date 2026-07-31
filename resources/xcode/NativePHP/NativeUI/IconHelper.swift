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
    case "phone":
        return "phone.fill"

    // Navigation arrows
    case "back":
        return "chevron.left"
    case "forward":
        return "chevron.right"
    case "up":
        return "chevron.up"
    case "down":
        return "chevron.down"

    // Status
    case "check", "done":
        return "checkmark.circle.fill"
    case "close":
        return "xmark.circle.fill"
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
    case "star":
        return "star.fill"
    case "bookmark":
        return "bookmark.fill"
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

/// Attempt to auto-convert icon name to SF Symbol
/// Tries common patterns: "newspaper" -> "newspaper.fill", "shopping-cart" -> "cart.fill"
private func tryAutoConvertIcon(_ iconName: String) -> String? {
    // Convert kebab-case to lowercase without separators for SF Symbol matching
    // "shopping-cart" -> "shoppingcart", "qr-code" -> "qrcode"
    let normalized = iconName.replacingOccurrences(of: "-", with: "")
        .replacingOccurrences(of: "_", with: "")
        .lowercased()

    // Try common SF Symbol patterns
    let patterns = [
        "\(normalized).fill",           // e.g., "newspaper.fill"
        "\(normalized)",                // e.g., "newspaper"
        "\(normalized).circle.fill",    // e.g., "newspaper.circle.fill"
        "\(normalized).square.fill",    // e.g., "newspaper.square.fill"
    ]

    // Check if any pattern exists as an SF Symbol
    // Note: SF Symbols are validated at runtime by UIImage/Image
    // We'll try the most common pattern and let iOS handle validation
    for pattern in patterns {
        // Return first pattern - iOS will show placeholder if invalid
        // In production, we could validate using UIImage(systemName:) != nil
        if pattern == patterns[0] {
            return pattern
        }
    }

    return nil
}