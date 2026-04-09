import SwiftUI
import UIKit

// MARK: - Environment Keys

private struct SafeAreaTopKey: EnvironmentKey {
    static let defaultValue: CGFloat = 0
}
private struct SafeAreaBottomKey: EnvironmentKey {
    static let defaultValue: CGFloat = 0
}
private struct AvailableWidthKey: EnvironmentKey {
    static let defaultValue: CGFloat = 390
}
private struct AvailableHeightKey: EnvironmentKey {
    static let defaultValue: CGFloat = 844
}

extension EnvironmentValues {
    var nativeSafeAreaTop: CGFloat {
        get { self[SafeAreaTopKey.self] }
        set { self[SafeAreaTopKey.self] = newValue }
    }
    var nativeSafeAreaBottom: CGFloat {
        get { self[SafeAreaBottomKey.self] }
        set { self[SafeAreaBottomKey.self] = newValue }
    }
    var availableWidth: CGFloat {
        get { self[AvailableWidthKey.self] }
        set { self[AvailableWidthKey.self] = newValue }
    }
    var availableHeight: CGFloat {
        get { self[AvailableHeightKey.self] }
        set { self[AvailableHeightKey.self] = newValue }
    }
}

// MARK: - Root Renderer

/// Top-level entry point that captures viewport size and safe area insets.
struct NativeTreeRenderer: View {
    let tree: NativeUITree

    var body: some View {
        GeometryReader { geo in
            // Get safe area from the window — GeometryReader reports zero
            // after .ignoresSafeArea() since it thinks there's no safe area.
            let insets = Self.windowSafeAreaInsets
            NodeView(node: tree.root)
                .environment(\.nativeSafeAreaTop, insets.top)
                .environment(\.nativeSafeAreaBottom, insets.bottom)
                .environment(\.availableWidth, geo.size.width)
                .environment(\.availableHeight, geo.size.height)
        }
        .ignoresSafeArea()
    }

    private static var windowSafeAreaInsets: (top: CGFloat, bottom: CGFloat) {
        guard let insets = UIApplication.shared.connectedScenes
            .compactMap({ $0 as? UIWindowScene })
            .first?.windows.first?.safeAreaInsets else {
            return (0, 0)
        }
        return (insets.top, insets.bottom)
    }
}

// MARK: - Recursive Node View

/// Renders a single NativeUINode and its children recursively.
/// Conforms to Equatable so SwiftUI skips re-rendering unchanged subtrees.
struct NodeView: View, Equatable {
    let node: NativeUINode
    @Environment(\.colorScheme) private var colorScheme
    @Environment(\.nativeSafeAreaTop) private var safeAreaTop
    @Environment(\.nativeSafeAreaBottom) private var safeAreaBottom
    @Environment(\.availableWidth) private var availableWidth
    @Environment(\.availableHeight) private var availableHeight

    static func == (lhs: NodeView, rhs: NodeView) -> Bool {
        lhs.node === rhs.node
    }

    var body: some View {
        content
            .modifier(NodeLayoutModifier(
                layout: node.layout,
                availableWidth: availableWidth,
                availableHeight: availableHeight,
                safeAreaTop: safeAreaTop,
                safeAreaBottom: safeAreaBottom
            ))
            .modifier(NodeStyleModifier(style: node.style, props: node.props))
            .modifier(NodeGestureModifier(node: node))
    }

    // MARK: - Content Dispatch

    @ViewBuilder
    private var content: some View {
        switch node.type {
        case "column":
            containerView
        case "row":
            containerView
        case "stack":
            stackView
        case "scroll_view":
            scrollView
        case "pressable":
            containerView
        case "canvas":
            containerView
        case "text":
            textView
        case "button":
            buttonView
        case "text_input":
            textInputView
        case "toggle":
            toggleView
        case "image":
            imageView
        case "icon":
            iconView
        case "spacer":
            Spacer()
        case "divider":
            dividerView
        case "activity_indicator":
            activityIndicatorView
        case "bottom_sheet":
            sheetView
        case "rect":
            Rectangle().fill(.clear)
        case "circle":
            Circle().fill(.clear)
        case "line":
            lineView
        default:
            containerView
        }
    }

    // MARK: - Container (column / row / pressable / canvas / default)

    @ViewBuilder
    private var containerView: some View {
        if node.children.isEmpty {
            // Empty container (e.g. colored rectangle) — Color.clear accepts
            // frame + background modifiers. An empty FlexContainer gets optimized away.
            Color.clear
        } else {
            let dir = node.type == "row" ? FlexDirection.row : (node.layout?.flexDirection ?? FlexDirection.column)
            FlexContainer(
                direction: dir,
                justify: node.layout?.justifyContent ?? JustifyContent.start,
                align: node.layout?.alignItems ?? AlignItems.stretch,
                gap: CGFloat(node.layout?.gap ?? 0),
                wrap: node.layout?.flexWrap ?? 0,
                childNodes: node.children
            ) {
                ForEach(node.children) { child in
                    NodeView(node: child)
                        .equatable()
                }
            }
        }
    }

    // MARK: - Stack (ZStack equivalent)

    private var stackView: some View {
        ZStack {
            ForEach(node.children) { child in
                NodeView(node: child)
                    .equatable()
            }
        }
    }

    // MARK: - ScrollView (virtualized)

    private var scrollView: some View {
        let horizontal = node.props.getBool("horizontal")
        let showsIndicators = node.props.getBool("shows_indicators", default: true)
        let spacing = CGFloat(node.layout?.gap ?? 0)

        return Group {
            if horizontal {
                ScrollView(.horizontal, showsIndicators: showsIndicators) {
                    LazyHStack(alignment: .top, spacing: spacing) {
                        ForEach(node.children) { child in
                            NodeView(node: child)
                                .equatable()
                        }
                    }
                }
            } else {
                ScrollView(.vertical, showsIndicators: showsIndicators) {
                    LazyVStack(alignment: .leading, spacing: spacing) {
                        ForEach(node.children) { child in
                            NodeView(node: child)
                                .equatable()
                                .frame(maxWidth: .infinity, alignment: .leading)
                        }
                    }
                    .frame(maxWidth: .infinity)
                }
            }
        }
    }

    // MARK: - Text

    private var textView: some View {
        let p = node.props
        let text = p.getString("text")
        let fontSize = CGFloat(p.getFloat("font_size", default: 16))
        let fontWeight = resolveSwiftUIWeight(p.getInt("font_weight"))
        let maxLines = p.getInt("max_lines")
        let textAlign = resolveTextAlignment(p.getInt("text_align"))

        let dark = colorScheme == .dark
        let darkColor = dark ? p.getColor("dark_color", default: 0) : 0
        let textArgb = darkColor != 0 ? darkColor : p.getColor("color", default: 0xFF000000)

        return Text(text)
            .font(.system(size: fontSize, weight: fontWeight))
            .foregroundStyle(colorFromARGB(textArgb))
            .multilineTextAlignment(textAlign)
            .lineLimit(maxLines > 0 ? maxLines : nil)
    }

    // MARK: - Button

    private var buttonView: some View {
        let p = node.props
        let label = p.getString("label")
        let fontSize = CGFloat(p.getFloat("font_size", default: 16))
        let disabled = p.getBool("disabled")

        let labelColor = p.getColor("label_color", default: 0)
        let propColor = p.getColor("color", default: 0)
        let effectiveColor = labelColor != 0 ? labelColor : (propColor != 0 ? propColor : 0)

        return Text(label)
            .font(.system(size: fontSize, weight: .medium))
            .foregroundStyle(effectiveColor != 0 ? colorFromARGB(effectiveColor) : Color.primary)
            .frame(maxWidth: .infinity)
            .multilineTextAlignment(.center)
            .opacity(disabled ? 0.5 : 1.0)
    }

    // MARK: - Text Input

    private var textInputView: some View {
        let p = node.props
        let placeholder = p.getString("placeholder")
        let secure = p.getBool("secure")
        let onChangeCb = p.getCallbackId("on_change")
        let onSubmitCb = p.getCallbackId("on_submit")
        let nodeId = node.id
        let initialValue = p.getString("value")

        return NativeTextFieldWrapper(
            initialValue: initialValue,
            placeholder: placeholder,
            isSecure: secure,
            nodeId: nodeId,
            onChangeCb: onChangeCb,
            onSubmitCb: onSubmitCb
        )
    }

    // MARK: - Toggle

    private var toggleView: some View {
        let p = node.props
        let label = p.getString("label")
        let isOn = p.getBool("value")
        let disabled = p.getBool("disabled")
        let onChangeCb = p.getCallbackId("on_change")
        let nodeId = node.id

        let tintArgb = node.style?.bgColor ?? 0
        let tintAlpha = (tintArgb >> 24) & 0xFF
        let tintColor: Color? = (tintArgb != 0 && tintAlpha != 0) ? colorFromARGB(tintArgb) : nil

        return NativeToggleWrapper(
            label: label,
            isOn: isOn,
            disabled: disabled,
            nodeId: nodeId,
            onChangeCb: onChangeCb,
            tintColor: tintColor
        )
    }

    // MARK: - Image

    private var imageView: some View {
        let p = node.props
        let src = p.getString("src")
        let fit = p.getInt("fit")
        let tintArgb = p.getColor("tint_color", default: 0)
        let contentMode = resolveContentMode(fit)

        return NativeAsyncImage(src: src, contentMode: contentMode, tintArgb: tintArgb)
    }

    // MARK: - Icon (SF Symbols)

    private var iconView: some View {
        let p = node.props
        let name = p.getString("name")
        let size = CGFloat(p.getFloat("size", default: 24))
        let colorArgb = p.getColor("color", default: 0)
        let sfName = getIconForName(name)

        return Image(systemName: sfName)
            .font(.system(size: size))
            .foregroundStyle(colorArgb != 0 ? colorFromARGB(colorArgb) : Color.primary)
    }

    // MARK: - Divider

    private var dividerView: some View {
        let borderArgb = node.style?.borderColor ?? 0
        let color: Color = borderArgb != 0 ? colorFromARGB(borderArgb) : Color(uiColor: .separator)
        return Rectangle().fill(color).frame(height: 1)
    }

    // MARK: - Activity Indicator

    private var activityIndicatorView: some View {
        let sizeVal = node.props.getInt("size")
        let colorArgb = node.props.getColor("color", default: 0)
        let scale: ControlSize = sizeVal == 1 ? .large : (sizeVal == 2 ? .small : .regular)

        return ProgressView()
            .controlSize(scale)
            .tint(colorArgb != 0 ? colorFromARGB(colorArgb) : nil)
    }

    // MARK: - Bottom Sheet

    @ViewBuilder
    private var sheetView: some View {
        let visible = node.props.getBool("visible")
        let onDismissCb = node.props.getCallbackId("on_dismiss")
        let nodeId = node.id

        Color.clear
            .frame(width: 0, height: 0)
            .sheet(isPresented: .constant(visible)) {
                VStack(spacing: 0) {
                    ForEach(node.children) { child in
                        NodeView(node: child)
                            .equatable()
                    }
                }
                .presentationDetents([.medium, .large])
                .presentationDragIndicator(.visible)
                .onDisappear {
                    if onDismissCb != 0 {
                        NativeElementBridge.sendSheetDismissEvent(onDismissCb, nodeId: nodeId)
                    }
                }
            }
    }

    // MARK: - Line

    private var lineView: some View {
        let borderArgb = node.style?.borderColor ?? 0
        let color: Color = borderArgb != 0 ? colorFromARGB(borderArgb) : Color(uiColor: .separator)
        let width = CGFloat(node.style?.borderWidth ?? 1)

        return Path { path in
            path.move(to: .zero)
            path.addLine(to: CGPoint(x: 100, y: 0))
        }
        .stroke(color, lineWidth: width)
    }
}

// MARK: - Gesture Modifier

/// Wires onPress / onLongPress callbacks to SwiftUI gestures.
private struct NodeGestureModifier: ViewModifier {
    let node: NativeUINode

    func body(content: Content) -> some View {
        content
            .contentShape(Rectangle())
            .modifier(TapModifier(callbackId: node.onPress, nodeId: node.id))
            .modifier(LongPressModifier(callbackId: node.onLongPress, nodeId: node.id))
    }
}

private struct TapModifier: ViewModifier {
    let callbackId: Int
    let nodeId: Int

    func body(content: Content) -> some View {
        if callbackId != 0 {
            content.onTapGesture {
                NativeElementBridge.sendPressEvent(callbackId, nodeId: nodeId)
            }
        } else {
            content
        }
    }
}

private struct LongPressModifier: ViewModifier {
    let callbackId: Int
    let nodeId: Int

    func body(content: Content) -> some View {
        if callbackId != 0 {
            content.onLongPressGesture(minimumDuration: 0.5) {
                NativeElementBridge.sendLongPressEvent(callbackId, nodeId: nodeId)
            }
        } else {
            content
        }
    }
}

// MARK: - TextField Wrapper (stateful)

/// Wraps a SwiftUI TextField/SecureField with local state for live editing.
/// Sends onChange events back to PHP via NativeElementBridge.
private struct NativeTextFieldWrapper: View {
    let initialValue: String
    let placeholder: String
    let isSecure: Bool
    let nodeId: Int
    let onChangeCb: Int
    let onSubmitCb: Int

    @State private var text: String = ""
    @State private var hasInitialized = false

    var body: some View {
        Group {
            if isSecure {
                SecureField(placeholder, text: $text)
            } else {
                TextField(placeholder, text: $text)
            }
        }
        .textFieldStyle(.roundedBorder)
        .onAppear {
            if !hasInitialized {
                text = initialValue
                hasInitialized = true
            }
        }
        .onChange(of: text) { _, newValue in
            if onChangeCb != 0 {
                NativeElementBridge.sendTextChangeEvent(onChangeCb, nodeId: nodeId, text: newValue)
            }
        }
        .onSubmit {
            if onSubmitCb != 0 {
                NativeElementBridge.sendSubmitEvent(onSubmitCb, nodeId: nodeId, text: text)
            }
        }
    }
}

// MARK: - Toggle Wrapper (stateful)

/// Wraps a SwiftUI Toggle with local state for immediate UI feedback.
private struct NativeToggleWrapper: View {
    let label: String
    let isOn: Bool
    let disabled: Bool
    let nodeId: Int
    let onChangeCb: Int
    let tintColor: Color?

    @State private var localValue: Bool = false
    @State private var hasInitialized = false

    var body: some View {
        Toggle(label, isOn: $localValue)
            .disabled(disabled)
            .tint(tintColor)
            .onAppear {
                if !hasInitialized {
                    localValue = isOn
                    hasInitialized = true
                }
            }
            .onChange(of: isOn) { _, newValue in
                localValue = newValue
            }
            .onChange(of: localValue) { _, newValue in
                if onChangeCb != 0 {
                    NativeElementBridge.sendToggleChangeEvent(onChangeCb, nodeId: nodeId, value: newValue)
                }
            }
    }
}

// MARK: - Async Image Loader

/// Loads an image from a URL with content mode and optional tint.
private struct NativeAsyncImage: View {
    let src: String
    let contentMode: ContentMode
    let tintArgb: Int

    var body: some View {
        if let url = URL(string: src), !src.isEmpty {
            AsyncImage(url: url) { phase in
                switch phase {
                case .success(let image):
                    let img = image.resizable().aspectRatio(contentMode: contentMode)
                    if tintArgb != 0 {
                        img.foregroundStyle(colorFromARGB(tintArgb))
                    } else {
                        img
                    }
                case .failure:
                    Color.clear
                case .empty:
                    ProgressView()
                @unknown default:
                    Color.clear
                }
            }
        } else {
            Color.clear
        }
    }
}

// MARK: - Helpers

private func resolveSwiftUIWeight(_ weight: Int) -> Font.Weight {
    switch weight {
    case 1: return .thin
    case 2: return .light
    case 3: return .regular
    case 4: return .medium
    case 5: return .semibold
    case 6: return .bold
    case 7: return .heavy
    default: return .regular
    }
}

private func resolveTextAlignment(_ align: Int) -> TextAlignment {
    switch align {
    case 0: return .leading
    case 1: return .center
    case 2: return .trailing
    default: return .leading
    }
}

private func resolveContentMode(_ fit: Int) -> ContentMode {
    switch fit {
    case 2: return .fill   // crop
    case 3: return .fill   // fillBounds
    default: return .fit   // fit, inside, none
    }
}
