import Foundation

// MARK: - Size Modes (must match nativephp_ui.h)

enum SizeMode {
    static let fixed   = 0
    static let wrap    = 1
    static let fill    = 2
    static let percent = 3
}

// MARK: - Event Types (must match nativephp_ui.h)

enum EventType {
    static let press          = 0
    static let longPress      = 1
    static let textChange     = 2
    static let toggleChange   = 3
    static let submit         = 4
    static let focus          = 5
    static let blur           = 6
    static let scroll         = 7
    static let systemBack     = 8
    static let sliderChange   = 9
    static let checkboxChange = 10
    static let radioChange    = 11
    static let selectChange   = 12
    static let tabChange      = 13
    static let sheetDismiss   = 14
    static let hotReload      = 15
    static let native         = 20
}

// MARK: - Value Type Tags (must match nativephp_ui.h)

enum ValType {
    static let u8          = 0
    static let u16         = 1
    static let u32         = 2
    static let i32         = 3
    static let f32         = 4
    static let bool_       = 5
    static let string      = 6
    static let color       = 7
    static let callback    = 8
    static let stringArray = 9
}

// MARK: - Prop Key Lookup Table (must match nativephp_ui.h)

enum PropKey {
    static let fallback = 0xFF

    static let table: [String] = [
        "text",              //  0
        "label",             //  1
        "value",             //  2
        "color",             //  3
        "on_press",          //  4
        "on_change",         //  5
        "on_submit",         //  6
        "on_dismiss",        //  7
        "disabled",          //  8
        "placeholder",       //  9
        "font_size",         // 10
        "font_weight",       // 11
        "text_align",        // 12
        "max_lines",         // 13
        "src",               // 14
        "fit",               // 15
        "tint_color",        // 16
        "label_color",       // 17
        "keyboard",          // 18
        "secure",            // 19
        "max_length",        // 20
        "multiline",         // 21
        "horizontal",        // 22
        "shows_indicators",  // 23
        "min",               // 24
        "max",               // 25
        "step",              // 26
        "track_color",       // 27
        "size",              // 28
        "name",              // 29
        "options",           // 30
        "count",             // 31
        "text_color",        // 32
        "variant",           // 33
        "headline",          // 34
        "supporting",        // 35
        "overline",          // 36
        "leading_icon",      // 37
        "trailing_icon",     // 38
        "headline_color",    // 39
        "supporting_color",  // 40
        "selected_index",    // 41
        "icon",              // 42
        "visible",           // 43
    ]
}

// MARK: - Generic Props Container

final class GenericProps: Equatable {
    private let map: [String: Any]

    init(_ map: [String: Any] = [:]) {
        self.map = map
    }

    func getString(_ key: String, default defaultValue: String = "") -> String {
        (map[key] as? String) ?? defaultValue
    }

    func getInt(_ key: String, default defaultValue: Int = 0) -> Int {
        if let n = map[key] as? Int { return n }
        if let n = map[key] as? NSNumber { return n.intValue }
        return defaultValue
    }

    func getFloat(_ key: String, default defaultValue: Float = 0) -> Float {
        if let n = map[key] as? Float { return n }
        if let n = map[key] as? NSNumber { return n.floatValue }
        return defaultValue
    }

    func getBool(_ key: String, default defaultValue: Bool = false) -> Bool {
        if let b = map[key] as? Bool { return b }
        return defaultValue
    }

    func getColor(_ key: String, default defaultValue: Int = 0xFF000000) -> Int {
        if let n = map[key] as? Int { return n }
        if let n = map[key] as? NSNumber { return n.intValue }
        if let s = map[key] as? String { return ColorParser.parse(s, default: defaultValue) }
        return defaultValue
    }

    func getCallbackId(_ key: String) -> Int {
        if let n = map[key] as? Int { return n }
        if let n = map[key] as? NSNumber { return n.intValue }
        return 0
    }

    func getStringList(_ key: String) -> [String] {
        (map[key] as? [String]) ?? []
    }

    func has(_ key: String) -> Bool {
        map[key] != nil
    }

    var isEmpty: Bool { map.isEmpty }

    var debugDescription: String { "\(map)" }

    static func == (lhs: GenericProps, rhs: GenericProps) -> Bool {
        NSDictionary(dictionary: lhs.map).isEqual(to: rhs.map)
    }
}

// MARK: - UI Tree

struct NativeUITree {
    let version: Int
    let callbackCount: Int
    let root: NativeUINode
}

// MARK: - UI Node

final class NativeUINode: Identifiable, Equatable {
    let id: Int
    let type: String
    let layout: NodeLayout?
    let style: NodeStyle?
    let props: GenericProps
    let onPress: Int
    let onLongPress: Int
    let children: [NativeUINode]

    init(
        id: Int,
        type: String,
        layout: NodeLayout?,
        style: NodeStyle?,
        props: GenericProps,
        onPress: Int,
        onLongPress: Int,
        children: [NativeUINode]
    ) {
        self.id = id
        self.type = type
        self.layout = layout
        self.style = style
        self.props = props
        self.onPress = onPress
        self.onLongPress = onLongPress
        self.children = children
    }

    func copy(children: [NativeUINode]? = nil) -> NativeUINode {
        NativeUINode(
            id: id, type: type, layout: layout, style: style,
            props: props, onPress: onPress, onLongPress: onLongPress,
            children: children ?? self.children
        )
    }

    static func == (lhs: NativeUINode, rhs: NativeUINode) -> Bool {
        lhs === rhs
    }

    /// Recursive structural equality. Used by `NodeView.==` so SwiftUI's
    /// `.equatable()` short-circuit fires on semantically-unchanged subtrees,
    /// even when PHP rebuilds the tree (producing fresh `NativeUINode` refs).
    ///
    /// Short-circuits on first difference — cheap in practice even on large
    /// trees (a few hundred nodes compare in microseconds).
    func deepEquals(_ other: NativeUINode) -> Bool {
        if self === other { return true }
        guard id == other.id,
              type == other.type,
              onPress == other.onPress,
              onLongPress == other.onLongPress,
              layout == other.layout,
              style == other.style,
              props == other.props,
              children.count == other.children.count
        else { return false }

        for (a, b) in zip(children, other.children) {
            if !a.deepEquals(b) { return false }
        }
        return true
    }
}

// MARK: - Layout

struct NodeLayout: Equatable {
    let width: Float
    let widthMode: Int
    let height: Float
    let heightMode: Int
    let paddingTop: Float
    let paddingRight: Float
    let paddingBottom: Float
    let paddingLeft: Float
    let marginTop: Float
    let marginRight: Float
    let marginBottom: Float
    let marginLeft: Float
    let flexGrow: Float
    let flexShrink: Float
    let alignSelf: Int
    let alignItems: Int
    let justifyContent: Int
    let gap: Float
    let safeArea: Int
    // Extended layout fields (Yoga)
    let minWidth: Float
    let minHeight: Float
    let maxWidth: Float
    let maxHeight: Float
    let flexBasis: Float
    let flexBasisMode: Int
    let flexWrap: Int
    let flexDirection: Int
    let positionType: Int
    let positionTop: Float
    let positionRight: Float
    let positionBottom: Float
    let positionLeft: Float
    let display: Int
    let overflow: Int
    let alignContent: Int
    let direction: Int
    let aspectRatio: Float
    let rowGap: Float
}

// MARK: - Style

struct NodeStyle: Equatable {
    let bgColor: Int
    let borderRadius: Float
    let borderWidth: Float
    let borderColor: Int
    let opacity: Float
    let elevation: Float
}

// MARK: - Color Parser

/// Parses color strings (hex) to ARGB int.
/// Supports: #RGB, #RRGGBB, #AARRGGBB — matching Android's ColorParser.
enum ColorParser {
    static func parse(_ string: String, default defaultValue: Int = 0xFF000000) -> Int {
        var hex = string.trimmingCharacters(in: .whitespaces)
        if hex.hasPrefix("#") { hex = String(hex.dropFirst()) }
        hex = hex.uppercased()

        switch hex.count {
        case 3:
            // #RGB → #FFRRGGBB
            let r = parseHexChar(hex[hex.index(hex.startIndex, offsetBy: 0)])
            let g = parseHexChar(hex[hex.index(hex.startIndex, offsetBy: 1)])
            let b = parseHexChar(hex[hex.index(hex.startIndex, offsetBy: 2)])
            return Int(0xFF000000)
                | (r << 20) | (r << 16)
                | (g << 12) | (g << 8)
                | (b << 4)  | b
        case 6:
            // #RRGGBB → #FFRRGGBB
            guard let rgb = UInt32(hex, radix: 16) else { return defaultValue }
            return Int(0xFF000000) | Int(rgb)
        case 8:
            // #AARRGGBB
            guard let argb = UInt32(hex, radix: 16) else { return defaultValue }
            return Int(argb)
        default:
            return defaultValue
        }
    }

    private static func parseHexChar(_ c: Character) -> Int {
        if let v = c.hexDigitValue { return v }
        return 0
    }
}

