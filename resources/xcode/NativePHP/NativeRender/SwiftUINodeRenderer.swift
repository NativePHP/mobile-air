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
        // Tap outside any input dismisses keyboard
        .onTapGesture {
            UIApplication.shared.sendAction(#selector(UIResponder.resignFirstResponder), to: nil, from: nil, for: nil)
        }
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

    // MARK: - Content Dispatch (via plugin registry)

    @ViewBuilder
    private var content: some View {
        if let renderer = SwiftUIRendererRegistry.shared.get(node.type) {
            renderer(node)
        } else {
            // Fallback for unregistered types — render as column container
            containerView
        }
    }

    // MARK: - Fallback Container

    @ViewBuilder
    private var containerView: some View {
        if node.children.isEmpty {
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
