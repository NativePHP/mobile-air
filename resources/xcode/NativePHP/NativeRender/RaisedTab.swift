import SwiftUI
import UIKit

/// Raised tabs (`Tab::raised()`): a `Tab` drawn as a disc rising out of
/// the bar. `NativeRootTabsRenderer` keeps the tab (label, selection,
/// VoiceOver, badge, taps) with a blank image and overlays a
/// [RaisedTabDisc] on its slot. The disc selects the tab through the
/// renderer's `selection`, so it runs the bar's own tap path. SwiftUI
/// doesn't expose tab item geometry, so [RaisedTabBarTracker] reads the
/// real `UITabBar` with public UIView API. Style props: `raised_*`.

/// Transparent template image the size of a tab icon, for the raised
/// tab's own (covered) slot, so the label stays where it would be.
enum RaisedTabSlotImage {
    static let blank: UIImage = UIGraphicsImageRenderer(size: CGSize(width: 25, height: 25))
        .image { _ in }
        .withRenderingMode(.alwaysTemplate)
}

/// One raised tab, as the renderer hands it to the overlay.
struct RaisedTabEntry {
    /// Stable PHP-side tab id (the `selection` value).
    let id: String
    let tab: NativeUINode
    /// Position of the tab among the bar's visible items, left to right.
    let slot: Int
}

/// Draws the discs over their tabs' slots. Only mounted when the bar has a
/// raised tab, so a TabView without one pays nothing (no tracker, no
/// observers).
struct RaisedTabsOverlay: View {
    let entries: [RaisedTabEntry]
    /// Items the bar shows, including a visible search tab (always last).
    let slotCount: Int
    let hasSearchSlot: Bool
    /// The bar is hidden (pushed level, `hide_tab_bar`) or not in its
    /// regular layout (the search tab is selected).
    let hidden: Bool
    let fallbackFill: Color
    /// Changes on every publish; the tracker re-measures after each.
    let publish: ObjectIdentifier
    @Binding var selection: String

    @StateObject private var tracker = RaisedTabBarTracker()
    /// The app's direction, read before the overlay forces LTR for its
    /// window-coordinate positioning, so the disc's own content (badge)
    /// still mirrors.
    @Environment(\.layoutDirection) private var layoutDirection

    var body: some View {
        GeometryReader { proxy in
            ZStack {
                // Rotation, Split View and window resizes change the bar's
                // geometry without a publish; re-measure whenever the space
                // this overlay fills changes size.
                Color.clear
                    .allowsHitTesting(false)
                    .onChange(of: proxy.size) { _, _ in tracker.follow() }

                if !hidden, let bar = tracker.bar {
                    let origin = proxy.frame(in: .global).origin
                    ForEach(entries, id: \.id) { entry in
                        if let slot = bar.slot(index: entry.slot, count: slotCount, hasSearchSlot: hasSearchSlot) {
                            let style = RaisedTabStyle(tab: entry.tab, fallbackFill: fallbackFill)
                            RaisedTabDisc(style: style, selected: selection == entry.id) {
                                // Same path as a tap on the bar. Re-tapping the
                                // selected tab leaves `selection` unchanged, so
                                // (as on the bar) nothing fires.
                                selection = entry.id
                            }
                            .environment(\.layoutDirection, layoutDirection)
                            .position(
                                x: slot.midX - origin.x,
                                // Top of the fill sits `lift` above the bar's top edge.
                                y: bar.top - style.lift + style.size / 2 - origin.y
                            )
                            // On iOS the keyboard covers the bar; a compact
                            // one (a hardware keyboard's shortcut bar) could
                            // leave the raised part showing above it, so the
                            // disc steps aside unless the tab opted out.
                            .opacity(style.dockWithKeyboard && tracker.keyboardVisible ? 0 : 1)
                            .animation(.easeOut(duration: 0.2), value: tracker.keyboardVisible)
                        }
                    }
                }
            }
        }
        .ignoresSafeArea()
        // Every position is an absolute UIKit window coordinate, so the
        // overlay's own space must not mirror in RTL.
        .environment(\.layoutDirection, .leftToRight)
        .onAppear { tracker.follow() }
        .onChange(of: publish) { _, _ in tracker.follow() }
        .onChange(of: hidden) { _, _ in tracker.follow() }
    }
}

/// A raised tab's style, read once per publish from its `raised_*` props.
struct RaisedTabStyle {
    let size: CGFloat
    let lift: CGFloat
    let ringWidth: CGFloat
    let ringColor: Color
    let haloWidth: CGFloat
    let haloColor: Color
    let fill: [Color]
    let angle: Double
    let icon: String
    let iconColor: Color
    let iconSize: CGFloat
    let shadow: Bool
    let shadowColor: Color
    let shadowElevation: CGFloat
    let pressScale: CGFloat
    let dockWithKeyboard: Bool
    let badge: String?

    /// The halo's space is always reserved so the disc never shifts when
    /// its tab becomes selected.
    var outer: CGFloat { size + (ringWidth + haloWidth) * 2 }

    init(tab: NativeUINode, fallbackFill: Color) {
        let props = tab.props

        size = CGFloat(props.getInt("raised_size", default: 52))
        lift = CGFloat(props.getInt("raised_lift", default: 20))
        ringWidth = props.has("raised_ring_color") ? CGFloat(props.getInt("raised_ring_width", default: 0)) : 0
        ringColor = Color(argb: props.getColor("raised_ring_color", default: 0))
        haloWidth = props.has("raised_halo_color") ? CGFloat(props.getInt("raised_halo_width", default: 0)) : 0
        haloColor = Color(argb: props.getColor("raised_halo_color", default: 0))

        if props.has("raised_gradient_from") && props.has("raised_gradient_to") {
            fill = [Color(argb: props.getColor("raised_gradient_from")), Color(argb: props.getColor("raised_gradient_to"))]
            angle = Double(props.getFloat("raised_gradient_angle", default: 135))
        } else if props.has("raised_color") {
            fill = [Color(argb: props.getColor("raised_color"))]
            angle = 0
        } else {
            fill = [fallbackFill]
            angle = 0
        }

        let raisedIcon = props.getString("raised_icon")
        let tabIcon = props.getString("icon").trimmingCharacters(in: .whitespaces)
        icon = !raisedIcon.isEmpty ? raisedIcon : (!tabIcon.isEmpty ? tabIcon : "add")
        iconColor = Color(argb: props.getColor("raised_icon_color", default: 0xFFFFFFFF))
        iconSize = CGFloat(props.getInt("raised_icon_size", default: 26))

        shadow = props.getBool("raised_shadow", default: true)
        shadowColor = props.has("raised_shadow_color") ? Color(argb: props.getColor("raised_shadow_color")) : (fill.last ?? .black)
        shadowElevation = CGFloat(props.getInt("raised_shadow_elevation", default: 8))
        pressScale = CGFloat(props.getFloat("raised_press_scale", default: 0.94))
        dockWithKeyboard = props.getBool("raised_dock_with_keyboard", default: true)

        // The tab keeps its own `.badge` (VoiceOver reads it there), but the
        // disc covers that slot, so the disc draws it too (same rule as
        // the bar: text, else a news dot).
        let badgeText = props.getString("badge")
        badge = !badgeText.isEmpty ? badgeText : (props.getBool("news") ? "" : nil)
    }
}

struct RaisedTabDisc: View {
    let style: RaisedTabStyle
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            ZStack {
                if selected && style.haloWidth > 0 {
                    Circle().fill(style.haloColor).frame(width: style.outer, height: style.outer)
                }

                Circle()
                    .fill(style.ringWidth > 0 ? style.ringColor : Color.clear)
                    .frame(width: style.size + style.ringWidth * 2, height: style.size + style.ringWidth * 2)
                    .shadow(
                        color: style.shadow ? style.shadowColor.opacity(0.55) : .clear,
                        radius: style.shadowElevation,
                        x: 0,
                        y: style.shadowElevation * 0.6
                    )

                Circle()
                    .fill(fillStyle)
                    .frame(width: style.size, height: style.size)
                    .overlay(alignment: .topTrailing) { badgeView }

                Image(systemName: RaisedTabDisc.symbol(for: style.icon))
                    .font(.system(size: style.iconSize * 0.8, weight: .semibold))
                    .foregroundStyle(style.iconColor)
            }
            .frame(width: style.outer, height: style.outer)
            .contentShape(Circle())
        }
        .buttonStyle(RaisedTabPressStyle(scale: style.pressScale))
        // The real tab button in the bar underneath is the accessible
        // element (label, selected state, activation), so the disc stays
        // out of VoiceOver's way instead of announcing the tab twice.
        .accessibilityHidden(true)
    }

    @ViewBuilder
    private var badgeView: some View {
        if let badge = style.badge {
            Group {
                if badge.isEmpty {
                    Circle().fill(Color.red).frame(width: 10, height: 10)
                } else {
                    Text(badge)
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(.white)
                        .padding(.horizontal, 5)
                        .frame(minWidth: 18, minHeight: 18)
                        .background(Capsule().fill(Color.red))
                }
            }
            .offset(x: 4, y: -4)
        }
    }

    /// The SF Symbol for an icon name. Unknown names pass through
    /// `getIconForName` as-is (a Material name like `park` becomes a symbol
    /// that doesn't exist) and SwiftUI would draw an empty disc; fall back
    /// to a plus instead.
    #if DEBUG
    @MainActor private static var loggedMissingSymbols: Set<String> = []
    #endif

    @MainActor static func symbol(for icon: String) -> String {
        let symbol = getIconForName(icon)
        if UIImage(systemName: symbol) != nil { return symbol }

        #if DEBUG
        if loggedMissingSymbols.insert(symbol).inserted {
            print("[RaisedTab] No SF Symbol for icon \"\(icon)\" (\(symbol)); drawing \"plus\". Pass ->icon(ios: '<symbol>') to choose one.")
        }
        #endif

        return "plus"
    }

    /// CSS `linear-gradient()` geometry in unit space: the gradient line runs
    /// through the centre at the given angle (0 = up, clockwise) and is long
    /// enough that the corners get the end colours.
    private var fillStyle: AnyShapeStyle {
        guard style.fill.count > 1 else { return AnyShapeStyle(style.fill.first ?? .accentColor) }

        let radians = style.angle * .pi / 180
        let dx = sin(radians)
        let dy = -cos(radians)
        let half = (abs(dx) + abs(dy)) / 2

        return AnyShapeStyle(LinearGradient(
            colors: style.fill,
            startPoint: UnitPoint(x: 0.5 - dx * half, y: 0.5 - dy * half),
            endPoint: UnitPoint(x: 0.5 + dx * half, y: 0.5 + dy * half)
        ))
    }
}

private struct RaisedTabPressStyle: ButtonStyle {
    let scale: CGFloat

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? scale : 1)
            .animation(.easeOut(duration: 0.16), value: configuration.isPressed)
    }
}

/// Where the tab bar is on screen, read from the real `UITabBar` with
/// public UIView API only. Measures when asked (appear, publish, size
/// change, app activation), then on every frame for about a second — long
/// enough to ride out a tab switch or the bar settling — after which the
/// display link stops. Hands SwiftUI a new value only when the geometry
/// actually changes.
@MainActor
final class RaisedTabBarTracker: NSObject, ObservableObject {
    struct Bar: Equatable {
        /// Top edge of the visible bar, in window coordinates.
        let top: CGFloat
        /// Tab button frames left to right, in window coordinates.
        let items: [CGRect]
        /// UIKit mirrors the bar in RTL: the first tab is rightmost.
        let rightToLeft: Bool

        /// The frame of the tab at `index` (declaration order), or nil when
        /// the buttons don't reconcile with the tabs — UIKit's "More" tab
        /// past five tabs, the iPad top bar — where no disc is drawn rather
        /// than guessed.
        func slot(index: Int, count: Int, hasSearchSlot: Bool) -> CGRect? {
            guard index >= 0, index < count else { return nil }

            // Every visible tab has a button, or every one but the search
            // tab, which iOS 26 can draw in a capsule of its own. The search
            // tab is declared last, so the other tabs keep their order —
            // mirrored in RTL, whether or not the search capsule counts.
            guard items.count == count || (hasSearchSlot && items.count == count - 1) else { return nil }

            let visual = rightToLeft ? items.count - 1 - index : index
            return items.indices.contains(visual) ? items[visual] : nil
        }
    }

    @Published private(set) var bar: Bar?
    @Published private(set) var keyboardVisible = false

    private var link: CADisplayLink?
    /// The bar found last time; per-tick measuring only walks its subtree,
    /// and the window is rescanned only when it's gone or not showing.
    private weak var tabBar: UITabBar?
    private var followUntil: CFTimeInterval = 0
    private var observers: [NSObjectProtocol] = []

    override init() {
        super.init()

        let center = NotificationCenter.default
        observers = [
            center.addObserver(forName: UIResponder.keyboardWillShowNotification, object: nil, queue: .main) { [weak self] _ in
                MainActor.assumeIsolated { self?.keyboardVisible = true }
            },
            center.addObserver(forName: UIResponder.keyboardWillHideNotification, object: nil, queue: .main) { [weak self] _ in
                MainActor.assumeIsolated { self?.keyboardVisible = false }
            },
            center.addObserver(forName: UIApplication.didBecomeActiveNotification, object: nil, queue: .main) { [weak self] _ in
                MainActor.assumeIsolated { self?.follow() }
            },
        ]
    }

    deinit {
        observers.forEach(NotificationCenter.default.removeObserver)
    }

    /// Re-measure every frame for a moment. A call while a burst is running
    /// extends it rather than adding a second display link.
    func follow(for seconds: CFTimeInterval = 1.0) {
        followUntil = max(followUntil, CACurrentMediaTime() + seconds)
        measure()

        if link == nil {
            let link = CADisplayLink(target: self, selector: #selector(tick))
            link.add(to: .main, forMode: .common)
            self.link = link
        }
    }

    @objc private func tick() {
        measure()

        if CACurrentMediaTime() > followUntil {
            link?.invalidate()
            link = nil
        }
    }

    private func measure() {
        let next = locate()
        if next != bar { bar = next }
    }

    private func locate() -> Bar? {
        if let cached = tabBar, let window = cached.window, Self.isShowing(cached, in: window) {
            return Self.measure(cached)
        }

        guard let window = Self.keyWindow(), let found = Self.visibleTabBar(in: window) else {
            tabBar = nil
            return nil
        }
        tabBar = found

        return Self.measure(found)
    }

    private static func measure(_ tabBar: UITabBar) -> Bar {
        let frame = tabBar.convert(tabBar.bounds, to: nil)
        // iOS 26's Liquid Glass bar lays every tab button out twice (a
        // selected-content layer over the normal one), so keep one frame
        // per slot.
        var items: [CGRect] = []
        for rect in tabButtons(in: tabBar).map({ $0.convert($0.bounds, to: nil) }).sorted(by: { $0.minX < $1.minX })
            where !items.contains(where: { abs($0.midX - rect.midX) < 1 && abs($0.width - rect.width) < 1 }) {
            items.append(rect)
        }

        return Bar(
            top: barTop(of: tabBar, frame: frame),
            items: items,
            rightToLeft: tabBar.effectiveUserInterfaceLayoutDirection == .rightToLeft
        )
    }

    private static func keyWindow() -> UIWindow? {
        UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .flatMap(\.windows)
            .first { $0.isKeyWindow }
    }

    /// The lowest on-screen, visible UITabBar in the window.
    private static func visibleTabBar(in window: UIWindow) -> UITabBar? {
        var found: [UITabBar] = []
        collect(window) { view in
            if let bar = view as? UITabBar { found.append(bar) }
        }

        return found
            .filter { isShowing($0, in: window) }
            .max { $0.convert($0.bounds, to: nil).maxY < $1.convert($1.bounds, to: nil).maxY }
    }

    private static func isShowing(_ view: UIView, in window: UIWindow) -> Bool {
        var current: UIView? = view
        while let v = current {
            if v.isHidden || v.alpha < 0.01 { return false }
            current = v.superview
        }

        let frame = view.convert(view.bounds, to: nil)
        return frame.minY < window.bounds.maxY - 1 && frame.maxY > 0
    }

    /// The tab buttons: controls inside the bar about the size of a tab.
    private static func tabButtons(in tabBar: UITabBar) -> [UIView] {
        var buttons: [UIView] = []
        collect(tabBar) { view in
            guard view !== tabBar, view is UIControl, !view.isHidden, view.alpha > 0.01 else { return }
            let size = view.bounds.size
            if size.width >= 30 && size.height >= 30 { buttons.append(view) }
        }

        // A control nested in another counted control is part of it.
        return buttons.filter { button in
            !buttons.contains { other in other !== button && button.isDescendant(of: other) }
        }
    }

    /// The top of what the user sees as the bar. The docked bar's own frame
    /// starts at its top edge; the iOS 26 floating bar sits inside a larger
    /// UITabBar frame, so use the top of its platter (the tallest wide
    /// background view) instead.
    private static func barTop(of tabBar: UITabBar, frame: CGRect) -> CGFloat {
        if #available(iOS 26.0, *) {
            var top = frame.maxY
            collect(tabBar) { view in
                guard view !== tabBar, !view.isHidden, view.alpha > 0.01, !(view is UIControl) else { return }
                let rect = view.convert(view.bounds, to: nil)
                if rect.width >= frame.width * 0.6 && rect.height >= 40 {
                    top = min(top, rect.minY)
                }
            }
            return top < frame.maxY ? top : frame.minY
        }

        return frame.minY
    }

    private static func collect(_ view: UIView, _ visit: (UIView) -> Void) {
        visit(view)
        for subview in view.subviews { collect(subview, visit) }
    }
}
