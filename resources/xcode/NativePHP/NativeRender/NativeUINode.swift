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

    /// The backing dictionary — used to fold a responsive variant's prop
    /// delta over the base props (see `NodeVariant`).
    var rawMap: [String: Any] { map }

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

    /// Responsive alternatives (`md:` / `lg:` classes), sorted by min
    /// width, each already folded over the base — see `NodeVariant`.
    /// Empty for the vast majority of nodes.
    let variants: [NodeVariant]

    /// True when any DIRECT child carries variants. Containers (FlexContainer
    /// and the plugin column / row renderers) read `children[i].layout` to
    /// build flex modifiers before each child's own NodeView runs, so
    /// `resolved(forWidth:)` resolves one level of children too — this flag
    /// keeps that a single branch for the common variant-free subtree.
    let hasResponsiveChildren: Bool

    /// Main-thread cache of `resolved(forWidth:)` results, keyed by which
    /// variant won for this node and for each responsive child. Keeps node
    /// identity stable across body evaluations so `NodeView.==` (identity)
    /// keeps short-circuiting between resizes.
    private var resolvedCache: [String: NativeUINode] = [:]

    init(
        id: Int,
        type: String,
        layout: NodeLayout?,
        style: NodeStyle?,
        props: GenericProps,
        onPress: Int,
        onLongPress: Int,
        children: [NativeUINode],
        variants: [NodeVariant] = []
    ) {
        self.id = id
        self.type = type
        self.layout = layout
        self.style = style
        self.props = props
        self.onPress = onPress
        self.onLongPress = onLongPress
        self.children = children
        self.variants = variants
        self.hasResponsiveChildren = children.contains { !$0.variants.isEmpty }
    }

    func copy(children: [NativeUINode]? = nil) -> NativeUINode {
        NativeUINode(
            id: id, type: type, layout: layout, style: style,
            props: props, onPress: onPress, onLongPress: onLongPress,
            children: children ?? self.children, variants: variants
        )
    }

    /// The node as it should render at `width` points of window: the
    /// widest variant whose min fits, or `self` when none does (or the
    /// node has no variants — the common case, which costs one branch).
    /// Mobile-first like Tailwind: variants are cumulative, so the
    /// winner already carries every narrower breakpoint's overrides.
    func resolved(forWidth width: CGFloat) -> NativeUINode {
        guard !variants.isEmpty || hasResponsiveChildren else { return self }

        let winner = winningVariant(forWidth: width)
        var key = "\(winner)"
        var resolvedChildren = children
        var childrenChanged = false
        if hasResponsiveChildren {
            for (index, child) in children.enumerated() where !child.variants.isEmpty {
                key += ",\(child.winningVariant(forWidth: width))"
                let resolvedChild = child.resolved(forWidth: width)
                if resolvedChild !== child {
                    resolvedChildren[index] = resolvedChild
                    childrenChanged = true
                }
            }
        }
        guard winner >= 0 || childrenChanged else { return self }
        if let cached = resolvedCache[key] { return cached }

        let variant = winner >= 0 ? variants[winner] : nil
        let node = NativeUINode(
            id: id, type: type,
            layout: variant?.layout ?? layout,
            style: variant?.style ?? style,
            props: variant?.props ?? props,
            onPress: onPress, onLongPress: onLongPress,
            children: resolvedChildren
        )
        resolvedCache[key] = node
        return node
    }

    /// Index of the widest variant whose min fits `width`, or -1.
    func winningVariant(forWidth width: CGFloat) -> Int {
        var winner = -1
        for (index, variant) in variants.enumerated() where width >= variant.minWidth {
            winner = index
        }
        return winner
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
    // Extended layout fields (flexbox)
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

// MARK: - Responsive Variants

/// One breakpoint alternative for a node, already folded over the base
/// node and every narrower breakpoint. Built once at decode time from the
/// `_variants` prop PHP emits (a JSON list of `{min, layout?, style?,
/// props?}` deltas keyed by the same wire names the packed node uses), so
/// `NativeUINode.resolved(forWidth:)` is a comparison, not a merge.
struct NodeVariant {
    let minWidth: CGFloat
    let layout: NodeLayout?
    let style: NodeStyle?
    let props: GenericProps

    static func parse(props: GenericProps, baseLayout: NodeLayout?, baseStyle: NodeStyle?) -> [NodeVariant] {
        guard props.has("_variants") else { return [] }
        let json = props.getString("_variants")
        guard let data = json.data(using: .utf8),
              let entries = try? JSONSerialization.jsonObject(with: data) as? [[String: Any]]
        else { return [] }

        var out: [NodeVariant] = []
        out.reserveCapacity(entries.count)
        var layout = baseLayout
        var style = baseStyle
        var propMap = props.rawMap
        for entry in entries {
            guard let min = (entry["min"] as? NSNumber)?.doubleValue else { continue }
            if let delta = entry["layout"] as? [String: Any] {
                layout = (layout ?? NodeLayout.empty).applying(delta)
            }
            if let delta = entry["style"] as? [String: Any] {
                style = (style ?? NodeStyle.empty).applying(delta)
            }
            if let delta = entry["props"] as? [String: Any] {
                propMap.merge(delta) { _, new in new }
            }
            out.append(NodeVariant(minWidth: CGFloat(min), layout: layout, style: style, props: GenericProps(propMap)))
        }
        return out.sorted { $0.minWidth < $1.minWidth }
    }
}

extension NodeLayout {
    static let empty = NodeLayout(
        width: 0, widthMode: SizeMode.wrap, height: 0, heightMode: SizeMode.wrap,
        paddingTop: 0, paddingRight: 0, paddingBottom: 0, paddingLeft: 0,
        marginTop: 0, marginRight: 0, marginBottom: 0, marginLeft: 0,
        flexGrow: 0, flexShrink: 0, alignSelf: 0, alignItems: 0, justifyContent: 0, gap: 0, safeArea: 0,
        minWidth: 0, minHeight: 0, maxWidth: 0, maxHeight: 0,
        flexBasis: 0, flexBasisMode: 0, flexWrap: 0, flexDirection: 0, positionType: 0,
        positionTop: 0, positionRight: 0, positionBottom: 0, positionLeft: 0,
        display: 0, overflow: 0, alignContent: 0, direction: 0, aspectRatio: 0, rowGap: 0
    )

    /// A copy with a PHP layout-array delta applied. Keys and value
    /// encodings mirror `NativeElementCollector::buildLayoutArray`, which
    /// is also what the packed node was built from: sizes are a number
    /// (fixed), `"fill"`, `"wrap"` or `"N%"`; padding / margin / position
    /// are a number or a `[top, right, bottom, left]` list.
    func applying(_ d: [String: Any]) -> NodeLayout {
        func f(_ key: String, _ current: Float) -> Float {
            (d[key] as? NSNumber)?.floatValue ?? current
        }
        func i(_ key: String, _ current: Int) -> Int {
            (d[key] as? NSNumber)?.intValue ?? current
        }
        func size(_ key: String, _ current: (Float, Int)) -> (Float, Int) {
            guard let raw = d[key] else { return current }
            if let n = raw as? NSNumber { return (n.floatValue, SizeMode.fixed) }
            guard let s = raw as? String else { return current }
            if s == "fill" { return (0, SizeMode.fill) }
            if s == "wrap" { return (0, SizeMode.wrap) }
            if s.hasSuffix("%"), let p = Float(s.dropLast()) { return (p, SizeMode.percent) }
            if let n = Float(s) { return (n, SizeMode.fixed) }
            return current
        }
        func edges(_ key: String, _ current: (Float, Float, Float, Float)) -> (Float, Float, Float, Float) {
            guard let raw = d[key] else { return current }
            if let n = raw as? NSNumber { let v = n.floatValue; return (v, v, v, v) }
            if let list = raw as? [NSNumber], list.count == 4 {
                return (list[0].floatValue, list[1].floatValue, list[2].floatValue, list[3].floatValue)
            }
            return current
        }

        let (w, wm) = size("width", (width, widthMode))
        let (h, hm) = size("height", (height, heightMode))
        let (pt, pr, pb, pl) = edges("padding", (paddingTop, paddingRight, paddingBottom, paddingLeft))
        let (mt, mr, mb, ml) = edges("margin", (marginTop, marginRight, marginBottom, marginLeft))
        let (pot, por, pob, pol) = edges("position", (positionTop, positionRight, positionBottom, positionLeft))

        return NodeLayout(
            width: w, widthMode: wm, height: h, heightMode: hm,
            paddingTop: pt, paddingRight: pr, paddingBottom: pb, paddingLeft: pl,
            marginTop: mt, marginRight: mr, marginBottom: mb, marginLeft: ml,
            flexGrow: f("flex_grow", flexGrow), flexShrink: f("flex_shrink", flexShrink),
            alignSelf: i("align_self", alignSelf), alignItems: i("align_items", alignItems),
            justifyContent: i("justify_content", justifyContent), gap: f("gap", gap),
            safeArea: i("safe_area", safeArea),
            minWidth: f("min_width", minWidth), minHeight: f("min_height", minHeight),
            maxWidth: f("max_width", maxWidth), maxHeight: f("max_height", maxHeight),
            flexBasis: f("flex_basis", flexBasis),
            flexBasisMode: d["flex_basis"] != nil ? SizeMode.fixed : flexBasisMode,
            flexWrap: i("flex_wrap", flexWrap), flexDirection: i("flex_direction", flexDirection),
            positionType: i("position_type", positionType),
            positionTop: pot, positionRight: por, positionBottom: pob, positionLeft: pol,
            display: i("display", display), overflow: i("overflow", overflow),
            alignContent: i("align_content", alignContent), direction: i("direction", direction),
            aspectRatio: f("aspect_ratio", aspectRatio), rowGap: f("row_gap", rowGap)
        )
    }
}

extension NodeStyle {
    static let empty = NodeStyle(bgColor: 0, borderRadius: 0, borderWidth: 0, borderColor: 0, opacity: 1, elevation: 0)

    /// A copy with a PHP style-array delta applied (keys as in
    /// `NativeElementCollector::buildStyleArray`; colours are hex strings).
    func applying(_ d: [String: Any]) -> NodeStyle {
        func f(_ key: String, _ current: Float) -> Float {
            (d[key] as? NSNumber)?.floatValue ?? current
        }
        func color(_ key: String, _ current: Int) -> Int {
            if let n = d[key] as? NSNumber { return n.intValue }
            if let s = d[key] as? String { return ColorParser.parse(s, default: current) }
            return current
        }
        return NodeStyle(
            bgColor: color("bg_color", bgColor),
            borderRadius: f("border_radius", borderRadius),
            borderWidth: f("border_width", borderWidth),
            borderColor: color("border_color", borderColor),
            opacity: f("opacity", opacity),
            elevation: f("elevation", elevation)
        )
    }
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

