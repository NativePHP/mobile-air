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
        // Fold any plugin-registered root hosts (side drawers, global overlays,
        // …) around the rendered tree. A host pulls its own sentinel child out
        // of `tree.root` and renders nothing when absent. When no hosts are
        // registered this returns `rootContent` unchanged, so trees that use no
        // plugin chrome pay nothing — preserving the minimal-wrapping guarantee
        // below (for the iOS 26 tabs Liquid Glass capsule).
        NativeRootHostRegistry.shared.wrap(root: tree.root, content: AnyView(rootContent))
    }

    @ViewBuilder
    private var rootContent: some View {
        // Native chrome sentinels (`native_root_tabs`,
        // `native_root_stack`) need the TabView / NavigationStack to
        // sit AS CLOSE TO THE ROOT as possible. Going through `NodeView`
        // wraps them in `NodeLayoutModifier` + `NodeStyleModifier` +
        // `AnyView` (from the renderer registry), and `GeometryReader`
        // adds another layer. That cumulative wrapping breaks iOS 26's
        // `Tab(role: .search)` floating Liquid Glass capsule single-
        // tap activation. Bypassing the wrappers gets us byte-for-byte
        // close to the minimal SwiftUI reproducer, where capsule
        // activation works reliably.
        if tree.root.type == "native_root_tabs" {
            NativeRootTabsRenderer(node: tree.root)
                // `.container` (not `.all`): stay edge-to-edge under the notch /
                // home indicator, but RESPECT the keyboard region so SwiftUI's
                // avoidance shifts the content up when the keyboard appears.
                // In the clean column layout (inline input in the flex flow)
                // this shifts the whole screen up by one keyboard height — no
                // custom padding needed (which only doubled it).
                .ignoresSafeArea(.container, edges: .all)
        } else if tree.root.type == "native_root_stack" {
            NativeRootStackRenderer(node: tree.root)
                .ignoresSafeArea(.container, edges: .all)
        } else {
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
            .ignoresSafeArea(.container, edges: .all)
            .simultaneousGesture(
                TapGesture().onEnded {
                    UIApplication.shared.sendAction(#selector(UIResponder.resignFirstResponder), to: nil, from: nil, for: nil)
                }
            )
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
        // Reference identity. Between PHP publishes, `node` refs are stable
        // across SwiftUI body re-evaluations (scroll, focus, env changes) —
        // so `===` short-circuits reliably during steady-state, keeping scroll
        // frames cheap. On PHP publish the whole tree gets new refs → full
        // re-render, which is fine since that's exactly when we want updates.
        //
        // Deep-equality comparison (walking the whole subtree on every ==)
        // was tried earlier and killed scroll perf once the tree got dense:
        // `.equatable()` is applied at every child NodeView, so the cost
        // compounds to O(n·depth) per scroll frame.
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
            .modifier(NodeStyleModifier(style: node.style, props: node.props, nodeType: node.type))
            // Opt-in text selection (`select-text` / `select-none`). Applied at
            // the node level so it propagates to descendant Text (SwiftUI's
            // `.textSelection` is inherited) — container-scoped like Android's
            // SelectionContainer. No-op when the prop is absent.
            .modifier(NodeTextSelectionModifier(props: node.props))
            // Animation modifier runs AFTER style so it sees the resolved
            // opacity. No-op when `animate-duration` is not set, so the
            // hot path is unchanged for non-animated nodes.
            .modifier(NodeAnimationModifier(style: node.style, props: node.props))
            // Gesture FIRST (inner) — onTapGesture must be attached
            // before any simultaneousGesture wrapper or the tap is
            // starved by SwiftUI's gesture composition.
            .modifier(NodeGestureModifier(node: node))
            // Press feedback LAST (outer) — simultaneousGesture wraps
            // the tap and runs alongside it for press-in/press-out
            // tracking. No-op when no `press-*` prop is set.
            .modifier(NodePressFeedbackModifier(props: node.props))
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
///
/// `.contentShape(Rectangle())` is applied ONLY when the node actually has
/// a press handler. Without that gate, every container's full frame becomes
/// hit-testable — including transparent empty ones — which breaks
/// pass-through patterns like a backdrop scroll-view layered behind an
/// empty foreground column. SwiftUI's default hit-testing on opaque/visible
/// content is what we want for non-interactive containers.
private struct NodeGestureModifier: ViewModifier {
    let node: NativeUINode

    // Double-tap is carried in the props dict (`on_double_tap`) rather than a
    // dedicated node field like onPress/onLongPress, so it needs no binary
    // wire-format change. 0 = no handler.
    private var doubleTapId: Int { node.props.getInt("on_double_tap") }

    // Press-down/up ride the props dict the same way (see
    // PressDownUpModifier in ViewClickHandlers.swift).
    private var pressDownId: Int { node.props.getInt("on_press_down") }

    private var pressUpId: Int { node.props.getInt("on_press_up") }

    private var hasGesture: Bool {
        node.onPress != 0 || node.onLongPress != 0 || doubleTapId != 0
            || pressDownId != 0 || pressUpId != 0
    }

    func body(content: Content) -> some View {
        if hasGesture {
            content
                .contentShape(Rectangle())
                // Double-tap attached before single-tap so the 2-count
                // recognizer gets first claim on the gesture. Combining
                // @press + @doubleTap on one node incurs SwiftUI's usual
                // tap-arbitration delay on the single tap.
                .modifier(DoubleTapModifier(callbackId: doubleTapId, nodeId: node.id))
                .modifier(TapModifier(callbackId: node.onPress, nodeId: node.id))
                .modifier(LongPressModifier(callbackId: node.onLongPress, nodeId: node.id))
                .modifier(PressDownUpGate(downId: pressDownId, upId: pressUpId, nodeId: node.id))
        } else {
            content
        }
    }
}

/// Applies PressDownUpModifier only when a down/up handler exists — mirrors
/// the callbackId != 0 gating of the tap modifiers above.
private struct PressDownUpGate: ViewModifier {
    let downId: Int
    let upId: Int
    let nodeId: Int

    func body(content: Content) -> some View {
        if downId != 0 || upId != 0 {
            content.modifier(PressDownUpModifier(downId: downId, upId: upId, nodeId: nodeId))
        } else {
            content
        }
    }
}

/// Opt-in native text selection. `selectable == 1` enables the long-press Copy
/// menu for this node's whole subtree (SwiftUI `.textSelection` propagates to
/// descendant Text); `== 0` opts a subtree back out inside a selectable
/// ancestor. Absent → no modifier, so the node inherits its ancestor's setting.
private struct NodeTextSelectionModifier: ViewModifier {
    let props: GenericProps

    func body(content: Content) -> some View {
        if props.has("selectable"), #available(iOS 15.0, *) {
            if props.getInt("selectable") == 1 {
                content.textSelection(.enabled)
            } else {
                content.textSelection(.disabled)
            }
        } else {
            content
        }
    }
}

private struct DoubleTapModifier: ViewModifier {
    let callbackId: Int
    let nodeId: Int

    func body(content: Content) -> some View {
        if callbackId != 0 {
            content.onTapGesture(count: 2) {
                // Reuses the Press event type — the callback id alone routes
                // to the @doubleTap handler, and Press dispatch passes no args.
                NativeElementBridge.sendPressEvent(callbackId, nodeId: nodeId)
            }
        } else {
            content
        }
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
                    // `.aspectRatio(.fill)` makes the image fill its frame
                    // by aspect-scaling — but SwiftUI does NOT clip the
                    // overflowing dimension. Without an explicit `.clipped()`
                    // the cropped pixels are still drawn OUTSIDE the
                    // declared frame, bleeding over sibling views below
                    // (or above) the image. Looks fine on the simulator
                    // when the source's aspect happens to match the frame,
                    // but breaks on real devices where higher-resolution
                    // images decode to larger dimensions and overflow
                    // visibly. Always clip — it's a no-op for `.fit`.
                    let img = image
                        .resizable()
                        .aspectRatio(contentMode: contentMode)
                        .clipped()
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
