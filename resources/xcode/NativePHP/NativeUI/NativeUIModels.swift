import Foundation
import UIKit

// MARK: - Base Component Structure
struct NativeComponent: Codable {
    let type: String
    let data: ComponentData
}

enum ComponentData: Codable {
    case bottomNav(BottomNavData)
    case sideNav(SideNavData)
    case topBar(TopBarData)
    case unknown

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()

        // Try to decode as different types based on context
        if let bottomNav = try? container.decode(BottomNavData.self) {
            self = .bottomNav(bottomNav)
        } else if let sideNav = try? container.decode(SideNavData.self) {
            self = .sideNav(sideNav)
        } else if let topBar = try? container.decode(TopBarData.self) {
            self = .topBar(topBar)
        } else {
            self = .unknown
        }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        switch self {
        case .bottomNav(let data):
            try container.encode(data)
        case .sideNav(let data):
            try container.encode(data)
        case .topBar(let data):
            try container.encode(data)
        case .unknown:
            try container.encodeNil()
        }
    }
}

// MARK: - Bottom Navigation
struct BottomNavData: Codable, Equatable {
    let dark: Bool?
    let labelVisibility: String?
    let activeColor: String?
    let children: [BottomNavItemComponent]?

    enum CodingKeys: String, CodingKey {
        case dark
        case labelVisibility = "label_visibility"
        case activeColor = "active_color"
        case children
    }
}

struct BottomNavItemComponent: Codable, Equatable {
    let type: String
    let data: BottomNavItem
}

struct BottomNavItem: Codable, Equatable {
    let id: String
    let label: String
    let url: String
    let icon: String
    let active: Bool?
    let badge: String?
    let badgeColor: String?
    let news: Bool?

    enum CodingKeys: String, CodingKey {
        case id, label, url, icon, active, badge, news
        case badgeColor = "badge_color"
    }
}

// MARK: - Side Navigation
struct SideNavData: Codable, Equatable {
    let dark: Bool?
    let labelVisibility: String?
    let gesturesEnabled: Bool?
    let children: [SideNavChild]?

    enum CodingKeys: String, CodingKey {
        case dark
        case labelVisibility = "label_visibility"
        case gesturesEnabled = "gestures_enabled"
        case children
    }
}

struct SideNavChild: Codable, Equatable {
    let type: String
    let data: SideNavChildData?
}

enum SideNavChildData: Codable, Equatable {
    case item(SideNavItem)
    case group(SideNavGroup)
    case header(SideNavHeader)
    case divider

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()

        if let item = try? container.decode(SideNavItem.self) {
            self = .item(item)
        } else if let group = try? container.decode(SideNavGroup.self) {
            self = .group(group)
        } else if let header = try? container.decode(SideNavHeader.self) {
            self = .header(header)
        } else {
            self = .divider
        }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        switch self {
        case .item(let data):
            try container.encode(data)
        case .group(let data):
            try container.encode(data)
        case .header(let data):
            try container.encode(data)
        case .divider:
            try container.encodeNil()
        }
    }

    static func == (lhs: SideNavChildData, rhs: SideNavChildData) -> Bool {
        switch (lhs, rhs) {
        case (.item(let l), .item(let r)): return l == r
        case (.group(let l), .group(let r)): return l == r
        case (.header(let l), .header(let r)): return l == r
        case (.divider, .divider): return true
        default: return false
        }
    }
}

struct SideNavItem: Codable, Equatable {
    let id: String
    let label: String
    let url: String
    let icon: String
    let active: Bool?
    let badge: String?
    let badgeColor: String?
    let openInBrowser: Bool?

    enum CodingKeys: String, CodingKey {
        case id, label, url, icon, active, badge
        case badgeColor = "badge_color"
        case openInBrowser = "open_in_browser"
    }
}

struct SideNavGroup: Codable, Equatable {
    let heading: String
    let icon: String?
    let expanded: Bool?
    let children: [SideNavGroupChild]?
}

struct SideNavGroupChild: Codable, Equatable {
    let type: String
    let data: SideNavItem?
}

struct SideNavHeader: Codable, Equatable {
    let title: String?
    let subtitle: String?
    let icon: String?
    let backgroundColor: String?
    let imageUrl: String?
    let event: String?
    let showCloseButton: Bool?
    let pinned: Bool?

    enum CodingKeys: String, CodingKey {
        case title, subtitle, icon, event, pinned
        case backgroundColor = "background_color"
        case imageUrl = "image_url"
        case showCloseButton = "show_close_button"
    }
}

// MARK: - Top Bar
struct TopBarData: Codable, Equatable {
    let title: String
    let subtitle: String?
    let showNavigationIcon: Bool?
    let backgroundColor: String?
    let textColor: String?
    let elevation: Int?
    let children: [TopBarActionComponent]?

    enum CodingKeys: String, CodingKey {
        case title, subtitle, elevation, children
        case showNavigationIcon = "show_navigation_icon"
        case backgroundColor = "background_color"
        case textColor = "text_color"
    }
}

struct TopBarActionComponent: Codable, Equatable {
    let type: String
    let data: TopBarAction
}

struct TopBarAction: Codable, Equatable {
    let id: String
    let label: String
    let url: String
    let icon: String
}

// MARK: - NativeUI Parser
class NativeUIParser {
    static func parse(_ jsonString: String) -> [NativeComponent] {
        guard let jsonData = jsonString.data(using: .utf8) else {
            print("❌ NativeUIParser: Failed to convert JSON string to data")
            return []
        }

        do {
            // First, decode as an array of dictionaries to inspect the type
            let json = try JSONSerialization.jsonObject(with: jsonData) as? [[String: Any]]

            var components: [NativeComponent] = []

            for (index, dict) in (json ?? []).enumerated() {
                guard let type = dict["type"] as? String else {
                    print("⚠️ NativeUIParser: Item \(index + 1) has no 'type' field")
                    continue
                }

                if type == "bottom_nav" {
                    // Re-encode the data field and decode it properly
                    if let dataDict = dict["data"],
                       let dataJson = try? JSONSerialization.data(withJSONObject: dataDict),
                       let bottomNavData = try? JSONDecoder().decode(BottomNavData.self, from: dataJson) {
                        components.append(NativeComponent(
                            type: type,
                            data: .bottomNav(bottomNavData)
                        ))
                    }
                } else if type == "side_nav" {
                    if let dataDict = dict["data"],
                       let dataJson = try? JSONSerialization.data(withJSONObject: dataDict),
                       let sideNavData = try? JSONDecoder().decode(SideNavData.self, from: dataJson) {
                        components.append(NativeComponent(
                            type: type,
                            data: .sideNav(sideNavData)
                        ))
                    }
                } else if type == "top_bar" {
                    if let dataDict = dict["data"],
                       let dataJson = try? JSONSerialization.data(withJSONObject: dataDict),
                       let topBarData = try? JSONDecoder().decode(TopBarData.self, from: dataJson) {
                        components.append(NativeComponent(
                            type: type,
                            data: .topBar(topBarData)
                        ))
                    }
                }
            }

            return components
        } catch {
            print("❌ NativeUIParser: Failed to parse Native UI JSON: \(error)")
            return []
        }
    }

    static func parseBottomNavData(from component: NativeComponent) -> BottomNavData? {
        switch component.data {
        case .bottomNav(let data):
            return data
        default:
            return nil
        }
    }

    static func parseSideNavData(from component: NativeComponent) -> SideNavData? {
        switch component.data {
        case .sideNav(let data):
            return data
        default:
            return nil
        }
    }

    static func parseTopBarData(from component: NativeComponent) -> TopBarData? {
        switch component.data {
        case .topBar(let data):
            return data
        default:
            return nil
        }
    }
}

// MARK: - UIColor Extension for Hex Colors
extension UIColor {
    /// Parse hex color string to UIColor
    /// Supports both 6-digit (#RRGGBB) and 8-digit (#RRGGBBAA) hex formats
    convenience init?(hex: String) {
        var hexSanitized = hex.trimmingCharacters(in: .whitespacesAndNewlines)
        hexSanitized = hexSanitized.replacingOccurrences(of: "#", with: "")

        var rgb: UInt64 = 0

        guard Scanner(string: hexSanitized).scanHexInt64(&rgb) else {
            return nil
        }

        let length = hexSanitized.count

        let r, g, b, a: CGFloat
        if length == 6 {
            r = CGFloat((rgb & 0xFF0000) >> 16) / 255.0
            g = CGFloat((rgb & 0x00FF00) >> 8) / 255.0
            b = CGFloat(rgb & 0x0000FF) / 255.0
            a = 1.0
        } else if length == 8 {
            r = CGFloat((rgb & 0xFF000000) >> 24) / 255.0
            g = CGFloat((rgb & 0x00FF0000) >> 16) / 255.0
            b = CGFloat((rgb & 0x0000FF00) >> 8) / 255.0
            a = CGFloat(rgb & 0x000000FF) / 255.0
        } else {
            return nil
        }

        self.init(red: r, green: g, blue: b, alpha: a)
    }
}
