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
/// Width of the app's window (not the screen — a Split View pane is a
/// window), published from the tree root so every `NodeView` can resolve
/// responsive variants against it. 0 until the first layout pass.
private struct WindowWidthKey: EnvironmentKey {
    static let defaultValue: CGFloat = 0
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
    var windowWidth: CGFloat {
        get { self[WindowWidthKey.self] }
        set { self[WindowWidthKey.self] = newValue }
    }
}

// MARK: - Root Renderer

/// Top-level entry point that captures viewport size and safe area insets.
struct NativeTreeRenderer: View {
    let tree: NativeUITree

    /// Live window width for responsive (`md:` / `lg:`) variants. Tracked
    /// here — the one ancestor every root shape shares — rather than in
    /// the per-root GeometryReader so native-chrome roots (tabs / stack)
    /// re-flow on rotation and Split View too.
    @State private var windowWidth: CGFloat = 0

    var body: some View {
        rootWithHosts
            .onGeometryChange(for: CGFloat.self) { $0.size.width } action: { width in
                // Flex measurements cached at the old width would place
                // children at stale sizes — see `measurementGeneration`.
                if windowWidth > 0 && width != windowWidth {
                    FlexContainer.measurementGeneration &+= 1
                }
                windowWidth = width
            }
            .environment(\.windowWidth, windowWidth)
    }

    private var rootWithHosts: some View {
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
            .dismissesKeyboardOnTap()
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

// MARK: - Tap-to-dismiss Keyboard

/// Focus policy shared between the text-input renderers, which know
/// whether the focused field opted into `keep-focus-on-submit`, and
/// the gesture layer, which decides whether a tap on an interactive
/// element should also dismiss the keyboard (mobile-air #335).
enum KeyboardFocusPolicy {
    /// Token for the field that owns the state below, nil when no field
    /// is focused. When focus moves between two fields the old field's
    /// blur can arrive after the new field's focus, so a blur only
    /// clears the state when it comes from the current owner.
    private static var focusedField: AnyHashable?

    /// Registered by the focused input; flushes its undispatched text
    /// change so PHP sees the field's latest value before a press.
    private static var flushFocusedField: (() -> Void)?

    /// Whether the focused field opted into `keep-focus-on-submit`.
    private(set) static var focusedFieldKeepsFocus = false

    /// True while any text field holds focus, so press dispatch knows
    /// a pending autocorrection or debounced change might be in play.
    static var focusedFieldActive: Bool { focusedField != nil }

    /// Called by an input when it gains focus. `field` identifies the
    /// input and must be the same value it later passes to `fieldBlurred`.
    static func fieldFocused(_ field: AnyHashable, keepsFocus: Bool, flush: @escaping () -> Void) {
        focusedField = field
        focusedFieldKeepsFocus = keepsFocus
        flushFocusedField = flush
    }

    /// Called by an input when it blurs or leaves the screen. Ignored
    /// unless `field` is the current owner, so a late blur from the
    /// previous field cannot clear the state of the one that took over.
    static func fieldBlurred(_ field: AnyHashable) {
        guard focusedField == field else { return }

        focusedField = nil
        focusedFieldKeepsFocus = false
        flushFocusedField = nil
    }

    /// Dispatch a press event with focus handling around it: flush the
    /// focused field's pending change, resign unless the field keeps
    /// focus, and defer the press one runloop turn while focused so
    /// a tap-committed autocorrection's change event lands in PHP
    /// before the press does (mobile-air #335).
    static func dispatchPress(_ send: @escaping () -> Void) {
        flushFocusedField?()

        if !focusedFieldKeepsFocus {
            resignKeyboard()
        }

        if focusedFieldActive {
            DispatchQueue.main.async(execute: send)
        } else {
            send()
        }
    }

    static func resignKeyboard() {
        UIApplication.shared.sendAction(
            #selector(UIResponder.resignFirstResponder),
            to: nil,
            from: nil,
            for: nil
        )
    }

    /// Whether `point`, in window coordinates, lands on the view that
    /// currently holds first responder. False when nothing is focused
    /// or the first responder is not a view.
    static func firstResponderContains(_ point: CGPoint) -> Bool {
        guard let view = UIResponder.currentFirstResponder() as? UIView else {
            return false
        }

        return view.convert(view.bounds, to: nil).contains(point)
    }
}

private extension UIResponder {
    private weak static var capturedFirstResponder: UIResponder?

    /// UIKit has no public accessor for the first responder. An action
    /// sent to a nil target is delivered to it, so it records itself.
    static func currentFirstResponder() -> UIResponder? {
        capturedFirstResponder = nil
        UIApplication.shared.sendAction(
            #selector(nativePHPCaptureFirstResponder(_:)),
            to: nil,
            from: nil,
            for: nil
        )
        return capturedFirstResponder
    }

    @objc func nativePHPCaptureFirstResponder(_ sender: Any?) {
        UIResponder.capturedFirstResponder = self
    }
}

extension View {
    /// Dismiss the keyboard when the user taps anywhere in this subtree.
    ///
    /// Applied to the screen CONTENT on every kind of screen — chrome-less
    /// roots here, plus the stack and tabs renderers, which host their screens
    /// separately and so never inherited the gesture. Before that, tapping
    /// outside a field dismissed the keyboard on a plain screen but did
    /// nothing on any screen inside a tab or stack layout (mobile-air #308).
    ///
    /// Deliberately attached to the screen content rather than to the
    /// TabView / NavigationStack root: `rootContent` bypasses NodeView's
    /// wrappers for those sentinels because cumulative wrapping breaks iOS 26's
    /// `Tab(role: .search)` capsule activation, and adding a gesture recognizer
    /// spanning the tab bar risks the same class of problem. Screen content is
    /// also the correct scope — a tap on the tab bar or a toolbar button is
    /// that control's business, not a dismiss.
    ///
    /// Two taps, because a tap means different things depending on
    /// whether a child claimed it (mobile-air #335):
    ///
    /// - `KeyboardDismissTap` loses to a child's own tap, so it only fires
    ///   for taps nothing else claimed. That is the plain-area tap-away
    ///   path and it always dismisses, even when the focused field opted
    ///   into `keep-focus-on-submit`.
    /// - `KeyboardDismissWindowTap` fires for every tap on the screen,
    ///   including ones a child `Button`, chip, checkbox or plugin control
    ///   claimed. It dismisses unless the focused field asked to keep focus.
    ///   Those controls never go through `KeyboardFocusPolicy.dispatchPress`,
    ///   so without this they would leave the keyboard up, where before
    ///   #335 a tap on them dropped it. It skips a tap on the focused
    ///   field itself, so tapping to move the caret keeps the keyboard.
    ///
    /// Neither is a SwiftUI `TapGesture`: once one of those recognises, UIKit
    /// cancels the touch in any UIKit view underneath, so UIKit-backed
    /// controls (SwiftUI's own `SignInWithAppleButton`, a plugin's
    /// `UIControl`) never got their touch-up and did nothing.
    ///
    /// `@press` handlers still dismiss through
    /// `KeyboardFocusPolicy.dispatchPress` themselves, under the same
    /// keep-focus rule. The `contentShape` keeps empty regions of the
    /// screen hit-testable for the gestures.
    func dismissesKeyboardOnTap() -> some View {
        contentShape(Rectangle())
            .gesture(
                KeyboardDismissTap {
                    KeyboardFocusPolicy.resignKeyboard()
                }
            )
            .background(KeyboardDismissWindowTap())
    }
}

/// The plain-area tap of `dismissesKeyboardOnTap()`: a UIKit tap that never
/// cancels the touch underneath. Attached with `.gesture`, it still loses to
/// a child's own tap.
private struct KeyboardDismissTap: UIGestureRecognizerRepresentable {
    let onTap: () -> Void

    func makeUIGestureRecognizer(context: Context) -> UITapGestureRecognizer {
        let recognizer = UITapGestureRecognizer()
        recognizer.cancelsTouchesInView = false
        recognizer.delaysTouchesEnded = false

        return recognizer
    }

    func handleUIGestureRecognizerAction(_ recognizer: UITapGestureRecognizer, context: Context) {
        if recognizer.state == .ended {
            onTap()
        }
    }
}

/// The every-tap half of `dismissesKeyboardOnTap()`. A SwiftUI gesture that
/// recognises alongside its children stops UIKit-backed ones (text fields
/// included) from getting their taps, so this is a plain UIKit recognizer on
/// the window, like `EscapeHatchGesture`: it never cancels or delays a touch
/// and recognises alongside everything.
///
/// It only acts on taps inside a screen that asked for it: the tapped view
/// must be inside the view controller that hosts one of these anchors. Taps
/// on the tab bar, a navigation bar or a sheet are left alone, as they were
/// when this was a gesture on the screen content.
private struct KeyboardDismissWindowTap: UIViewRepresentable {
    func makeUIView(context: Context) -> Anchor {
        Anchor()
    }

    func updateUIView(_ anchor: Anchor, context: Context) {}

    final class Anchor: UIView {
        init() {
            super.init(frame: .zero)
            isUserInteractionEnabled = false
        }

        @available(*, unavailable)
        required init?(coder: NSCoder) {
            fatalError("init(coder:) is not supported")
        }

        override func didMoveToWindow() {
            super.didMoveToWindow()

            if let window {
                Recognizer.installed(on: window).anchors.add(self)
            }
        }

        /// The view of the view controller hosting this screen.
        var screen: UIView? {
            var responder = next
            while let current = responder {
                if let controller = current as? UIViewController {
                    return controller.view
                }
                responder = current.next
            }

            return nil
        }
    }

    final class Recognizer: UITapGestureRecognizer, UIGestureRecognizerDelegate {
        let anchors = NSHashTable<Anchor>.weakObjects()

        /// The window's recognizer, added the first time a screen needs it.
        static func installed(on window: UIWindow) -> Recognizer {
            if let existing = window.gestureRecognizers?.lazy.compactMap({ $0 as? Recognizer }).first {
                return existing
            }

            let recognizer = Recognizer()
            window.addGestureRecognizer(recognizer)

            return recognizer
        }

        init() {
            super.init(target: nil, action: nil)
            addTarget(self, action: #selector(tapped))
            cancelsTouchesInView = false
            delaysTouchesBegan = false
            delaysTouchesEnded = false
            delegate = self
        }

        @objc private func tapped() {
            guard state == .ended, let window = view as? UIWindow else { return }

            let point = location(in: window)

            guard let hit = window.hitTest(point, with: nil),
                  anchors.allObjects.contains(where: { $0.window === window && $0.screen.map(hit.isDescendant) == true }),
                  !KeyboardFocusPolicy.focusedFieldKeepsFocus,
                  !KeyboardFocusPolicy.firstResponderContains(point) else { return }

            KeyboardFocusPolicy.resignKeyboard()
        }

        func gestureRecognizer(
            _ gestureRecognizer: UIGestureRecognizer,
            shouldRecognizeSimultaneouslyWith other: UIGestureRecognizer
        ) -> Bool {
            true
        }
    }
}

// MARK: - Recursive Node View

/// Renders a single NativeUINode and its children recursively.
/// Conforms to Equatable so SwiftUI skips re-rendering unchanged subtrees.
/// Public entry point every container and plugin renderer uses for a
/// child. Resolves the node's responsive variants against the live window
/// width, then hands the winning alternative to `ResolvedNodeView`, so no
/// renderer has to know breakpoints exist. Equatable on node identity:
/// SwiftUI still re-evaluates the body when the environment width changes,
/// and `resolved(forWidth:)` returns a cached node per winning variant so
/// the inner view's identity check keeps short-circuiting between resizes.
struct NodeView: View, Equatable {
    let node: NativeUINode

    @Environment(\.windowWidth) private var windowWidth
    @Environment(\.availableWidth) private var availableWidth

    static func == (lhs: NodeView, rhs: NodeView) -> Bool {
        lhs.node === rhs.node
    }

    var body: some View {
        ResolvedNodeView(node: node.resolved(forWidth: windowWidth > 0 ? windowWidth : availableWidth))
    }
}

struct ResolvedNodeView: View, Equatable {
    let node: NativeUINode
    @Environment(\.colorScheme) private var colorScheme
    @Environment(\.nativeSafeAreaTop) private var safeAreaTop
    @Environment(\.nativeSafeAreaBottom) private var safeAreaBottom
    @Environment(\.availableWidth) private var availableWidth
    @Environment(\.availableHeight) private var availableHeight

    static func == (lhs: ResolvedNodeView, rhs: ResolvedNodeView) -> Bool {
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
        let decorations = NodeDecorations(node)

        // A node that draws nothing beyond its content (a plain text, an
        // icon, a column that only lines its children up) gets its layout and
        // nothing else.
        if decorations.isEmpty {
            laidOut
        } else {
            laidOut
                .modifier(NodeStyleModifier(style: node.style, props: node.props, nodeType: node.type, decorations: decorations))
                // Opt-in text selection (`select-text` / `select-none`). Applied at
                // the node level so it propagates to descendant Text (SwiftUI's
                // `.textSelection` is inherited) — container-scoped like Android's
                // SelectionContainer.
                .nodeModifier(if: decorations.contains(.textSelection)) {
                    $0.modifier(NodeTextSelectionModifier(props: node.props))
                }
                // Content transition (numeric roll / crossfade) for in-place
                // text changes. Environment-propagating like text selection.
                .nodeModifier(if: decorations.contains(.contentTransition)) {
                    $0.modifier(NodeContentTransitionModifier(props: node.props))
                }
                // Animation modifier runs AFTER style so it sees the resolved
                // opacity.
                .nodeModifier(if: decorations.contains(.animation)) {
                    $0.modifier(NodeAnimationModifier(style: node.style, props: node.props))
                }
                // Gesture FIRST (inner) — onTapGesture must be attached
                // before any simultaneousGesture wrapper or the tap is
                // starved by SwiftUI's gesture composition.
                .nodeModifier(if: decorations.contains(.gesture)) {
                    $0.modifier(NodeGestureModifier(node: node))
                }
                // Press feedback LAST (outer) — simultaneousGesture wraps
                // the tap and runs alongside it for press-in/press-out
                // tracking.
                .nodeModifier(if: decorations.contains(.pressFeedback)) {
                    $0.modifier(NodePressFeedbackModifier(props: node.props))
                }
                // Hidden (`display: none`) outside everything above, so the
                // background and border go with the content. FlexContainer never
                // places a hidden child and SwiftUI centres it at the container's
                // size, so hiding only the content painted its `bg-*` over its
                // siblings (`xl:hidden` in a row showed an empty bar).
                .nodeModifier(if: decorations.contains(.hidden)) {
                    $0.opacity(node.layout?.display == Display.none ? 0 : 1)
                }
        }
    }

    private var laidOut: some View {
        NodeLayoutModifier(
            layout: node.layout,
            availableWidth: availableWidth,
            availableHeight: availableHeight,
            safeAreaTop: safeAreaTop,
            safeAreaBottom: safeAreaBottom,
            alwaysPads: node.keepsState
        )
        .apply(to: content)
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

// MARK: - Decorations

/// The SwiftUI modifiers a node needs on top of its layout, read from its
/// style and props.
///
/// SwiftUI builds a node's views the first time its parent measures it, and
/// every modifier in the chain costs it a few more. A list pays that for
/// every node of a row as the row scrolls in, so `ResolvedNodeView` only puts
/// a node through the modifiers in this set. Before, every node went through
/// all of them (about twenty), drawing nothing with most.
///
/// The catch: a node whose set changes moves to a different view structure,
/// and SwiftUI builds everything under it again. A node that keeps state
/// (`NativeUINode.keepsState`: a field, an image, a list, a menu, or anything
/// holding one) always gets the `stable` layers, used or not, so its
/// background, border, shadow, opacity and visibility change without a
/// rebuild, as they did before. Clip, glass, glow, blur, gestures, selection,
/// transitions and animation already rebuilt a node when they came or went.
/// Everything else can't lose anything by being built again.
struct NodeDecorations: OptionSet {
    let rawValue: UInt16

    static let fill = NodeDecorations(rawValue: 1 << 0)
    static let clip = NodeDecorations(rawValue: 1 << 1)
    static let glass = NodeDecorations(rawValue: 1 << 2)
    static let border = NodeDecorations(rawValue: 1 << 3)
    static let shadow = NodeDecorations(rawValue: 1 << 4)
    static let glow = NodeDecorations(rawValue: 1 << 5)
    static let blur = NodeDecorations(rawValue: 1 << 6)
    static let opacity = NodeDecorations(rawValue: 1 << 7)
    static let textSelection = NodeDecorations(rawValue: 1 << 8)
    static let contentTransition = NodeDecorations(rawValue: 1 << 9)
    static let animation = NodeDecorations(rawValue: 1 << 10)
    static let gesture = NodeDecorations(rawValue: 1 << 11)
    static let pressFeedback = NodeDecorations(rawValue: 1 << 12)
    static let hidden = NodeDecorations(rawValue: 1 << 13)

    /// The layers whose changes never rebuilt a node, kept on every node
    /// that keeps state.
    static let stable: NodeDecorations = [.fill, .border, .shadow, .opacity, .hidden]

    init(rawValue: UInt16) {
        self.rawValue = rawValue
    }

    /// Each test mirrors the condition under which its modifier draws or
    /// does something; a modifier that learns a new prop needs it here too.
    init(_ node: NativeUINode) {
        self = node.keepsState ? .stable : []

        let style = node.style
        let props = node.props

        if (style?.bgColor ?? 0) != 0 || props.getColor("dark_bg_color", default: 0) != 0 || props.has("gradient_stops") {
            insert(.fill)
        }
        if (style?.borderRadius ?? 0) > 0 || props.has("radius_tl") {
            insert(.clip)
        }
        if props.getInt("glass", default: 0) & 1 != 0, !NodeStyleModifier.glassHandledByRenderer.contains(node.type) {
            insert(.glass)
        }
        if (style?.borderWidth ?? 0) > 0 {
            insert(.border)
        }
        if (style?.elevation ?? 0) > 0 {
            insert(.shadow)
        }
        if props.getFloat("glow_radius", default: 0) > 0, props.getFloat("glow_opacity", default: 0) > 0 {
            // The glow's halos come out a shade different without the
            // (clear) elevation shadow in front of them.
            insert([.shadow, .glow])
        }
        if props.getFloat("blur", default: 0) > 0 {
            insert(.blur)
        }

        let animates = props.getFloat("animate-duration", default: 0) > 0 || props.getBool("animate-loop")
        let bound = ["translate-x", "translate-y", "scale", "rotate", "opacity"].contains {
            !props.getString("\($0)_sv", default: "").isEmpty
        }
        let transformed = props.getFloat("translate-x", default: 0) != 0
            || props.getFloat("translate-y", default: 0) != 0
            || props.getFloat("scale", default: 1) != 1
            || props.getFloat("rotate", default: 0) != 0

        // NodeStyleModifier leaves opacity to NodeAnimationModifier when the
        // node animates or binds it.
        if !animates, props.getString("opacity_sv", default: "").isEmpty,
           (style?.opacity ?? 1) != 1 || props.getFloat("dark_opacity") > 0 {
            insert(.opacity)
        }
        if animates || bound || transformed {
            insert(.animation)
        }

        if props.has("selectable") {
            insert(.textSelection)
        }
        if !props.getString("content_transition", default: "").isEmpty {
            insert(.contentTransition)
        }
        if node.onPress != 0 || node.onLongPress != 0 || props.getInt("on_double_tap") != 0
            || props.getInt("on_press_down") != 0 || props.getInt("on_press_up") != 0 {
            insert(.gesture)
        }
        if props.getFloat("press-scale", default: 0) > 0 || props.getFloat("press-opacity", default: 0) > 0
            || props.getFloat("press-translate-y", default: 0) != 0 {
            insert(.pressFeedback)
        }
        if node.layout?.display == Display.none {
            insert(.hidden)
        }
    }
}

extension View {
    /// `transform(self)` when `condition` holds, otherwise the view as it is.
    /// Flipping `condition` changes the view's structure, which rebuilds
    /// everything under it, so this is only for what `NodeDecorations` picks.
    @ViewBuilder
    func nodeModifier<V: View>(if condition: Bool, _ transform: (Self) -> V) -> some View {
        if condition {
            transform(self)
        } else {
            self
        }
    }
}

// MARK: - Gesture Modifier

/// Wires onPress / onLongPress callbacks to SwiftUI gestures.
///
/// `.contentShape(Rectangle())` is applied ONLY when the node actually has
/// a press handler (`NodeDecorations.gesture`). Without that gate, every
/// container's full frame becomes hit-testable — including transparent empty
/// ones — which breaks pass-through patterns like a backdrop scroll-view
/// layered behind an empty foreground column. SwiftUI's default hit-testing on
/// opaque/visible content is what we want for non-interactive containers.
private struct NodeGestureModifier: ViewModifier {
    let node: NativeUINode

    func body(content: Content) -> some View {
        let nodeId = node.id
        let pressId = node.onPress
        let longPressId = node.onLongPress
        // Double-tap is carried in the props dict (`on_double_tap`) rather than a
        // dedicated node field like onPress/onLongPress, so it needs no binary
        // wire-format change. 0 = no handler.
        let doubleTapId = node.props.getInt("on_double_tap")
        // Press-down/up ride the props dict the same way (see
        // PressDownUpModifier in ViewClickHandlers.swift).
        let pressDownId = node.props.getInt("on_press_down")
        let pressUpId = node.props.getInt("on_press_up")

        content
            .contentShape(Rectangle())
            // Double-tap attached before single-tap so the 2-count
            // recognizer gets first claim on the gesture. Combining
            // @tap + @doubleTap on one node incurs SwiftUI's usual
            // tap-arbitration delay on the single tap.
            .nodeModifier(if: doubleTapId != 0) {
                $0.onTapGesture(count: 2) {
                    // Reuses the Press event type — the callback id alone routes
                    // to the @doubleTap handler, and Press dispatch passes no args.
                    KeyboardFocusPolicy.dispatchPress {
                        NativeElementBridge.sendPressEvent(doubleTapId, nodeId: nodeId)
                    }
                }
            }
            .nodeModifier(if: pressId != 0) {
                $0.onTapGesture {
                    KeyboardFocusPolicy.dispatchPress {
                        NativeElementBridge.sendPressEvent(pressId, nodeId: nodeId)
                    }
                }
            }
            .nodeModifier(if: longPressId != 0) {
                $0.onLongPressGesture(minimumDuration: 0.5) {
                    KeyboardFocusPolicy.dispatchPress {
                        NativeElementBridge.sendLongPressEvent(longPressId, nodeId: nodeId)
                    }
                }
            }
            .nodeModifier(if: pressDownId != 0 || pressUpId != 0) {
                $0.modifier(PressDownUpModifier(downId: pressDownId, upId: pressUpId, nodeId: nodeId))
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
