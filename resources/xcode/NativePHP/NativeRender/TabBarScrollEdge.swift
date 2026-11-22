import SwiftUI
import UIKit

/// iOS 26's scroll edge effect under the native tab bar, for a tab's root
/// level. `style` is the tabs root's `edge_blur` prop: "soft" (the default,
/// sent as nothing), "hard" or "none".
///
/// On iOS 26 a tab bar blurs what scrolls under it by itself, but only for
/// the one scroll view UIKit picks as the tab's content scroll view. SwiftUI
/// picks it once, and in a horizontal pager (or any horizontal scroller
/// holding vertical lists) it picks one page's list, usually not the one on
/// screen, so the visible list gets no effect at all. `TabBarScrollEdgeProbe`
/// fixes that by pointing a `UIScrollEdgeElementContainerInteraction` on
/// the tab bar at whichever vertical scroll view is under the bar right now.
/// For a plain list the system's own effect is already on that scroll view,
/// and the interaction draws nothing extra.
///
/// An empty style (a pushed level, or a root that hides the tab bar) leaves
/// the level alone. "hard" sets the style for the scroll views in the level. "none" hides the
/// effect there. A bottom bar on the same level that asks for its own edge
/// blur sits inside this modifier, so its style wins for the bar's region.
/// Before iOS 26 this does nothing.
struct TabBarEdgeBlurModifier: ViewModifier {
    let style: String
    let isSelected: Bool

    func body(content: Content) -> some View {
        #if compiler(>=6.2)
        if #available(iOS 26.0, *) {
            switch style {
            case "":
                content
            case "none":
                content.scrollEdgeEffectHidden(true, for: .bottom)
            case "hard":
                content
                    .scrollEdgeEffectStyle(.hard, for: .bottom)
                    .background(TabBarScrollEdgeProbe(active: isSelected))
            default:
                content.background(TabBarScrollEdgeProbe(active: isSelected))
            }
        } else {
            content
        }
        #else
        content
        #endif
    }
}

#if compiler(>=6.2)
/// An invisible view in the tab's hierarchy that keeps the tab bar's scroll
/// edge interaction on the scroll view under the bar. It looks again when a
/// horizontal scroller in the tab moves (a pager swipe, so the effect follows
/// the page as it comes in) and every half second while the tab is on screen,
/// which catches lists that are replaced when a screen redraws.
@available(iOS 26.0, *)
private struct TabBarScrollEdgeProbe: UIViewRepresentable {
    let active: Bool

    func makeUIView(context: Context) -> TabBarScrollEdgeProbeView {
        TabBarScrollEdgeProbeView()
    }

    func updateUIView(_ view: TabBarScrollEdgeProbeView, context: Context) {
        view.active = active
    }

    static func dismantleUIView(_ view: TabBarScrollEdgeProbeView, coordinator: ()) {
        view.detach()
    }
}

@available(iOS 26.0, *)
private final class TabBarScrollEdgeProbeView: UIView {
    var active = true {
        didSet {
            if active != oldValue { refresh() }
        }
    }

    private weak var tabBar: UITabBar?
    /// The view of the tab's own controller, the child of the tab bar
    /// controller. Scroll views are looked for under it, so another tab's
    /// lists are never picked.
    private weak var container: UIView?
    private weak var target: UIScrollView?
    private var interaction: UIScrollEdgeElementContainerInteraction?
    private var offsetObservations: [ObjectIdentifier: NSKeyValueObservation] = [:]
    private var timer: Timer?

    override init(frame: CGRect) {
        super.init(frame: frame)
        isUserInteractionEnabled = false
        isHidden = true
    }

    required init?(coder: NSCoder) {
        fatalError("init(coder:) is not used")
    }

    override func didMoveToWindow() {
        super.didMoveToWindow()
        guard window != nil else {
            detach()
            return
        }
        DispatchQueue.main.async { [weak self] in self?.refresh() }
        if timer == nil {
            timer = Timer.scheduledTimer(withTimeInterval: 0.5, repeats: true) { [weak self] _ in
                self?.refresh()
            }
        }
    }

    func detach() {
        timer?.invalidate()
        timer = nil
        offsetObservations.removeAll()
        setTarget(nil)
    }

    private func refresh() {
        guard let window, active else {
            setTarget(nil)
            return
        }
        if tabBar == nil || container == nil {
            var responder: UIResponder? = next
            while let current = responder {
                if let controller = current as? UIViewController,
                   let tabs = controller.parent as? UITabBarController {
                    container = controller.view
                    tabBar = tabs.tabBar
                    break
                }
                responder = current.next
            }
        }
        guard let tabBar, let container, tabBar.window === window,
              !tabBar.isHidden, tabBar.alpha > 0.01 else {
            setTarget(nil)
            return
        }

        let bar = tabBar.convert(tabBar.bounds, to: window)
        let point = CGPoint(x: bar.midX, y: bar.midY)
        var scrollViews: [UIScrollView] = []
        collect(container, into: &scrollViews)

        // The deepest vertical scroll view under the bar's middle. A
        // horizontal-only scroller (a pager, a carousel) is never it, but
        // its movement is what changes the answer, so it's watched.
        var horizontal = Set<ObjectIdentifier>()
        var picked: UIScrollView?
        for scrollView in scrollViews {
            let size = scrollView.bounds.size
            let content = scrollView.contentSize
            if content.width > size.width + 1 && content.height <= size.height + 1 {
                let id = ObjectIdentifier(scrollView)
                horizontal.insert(id)
                if offsetObservations[id] == nil {
                    offsetObservations[id] = scrollView.observe(\.contentOffset) { [weak self] _, _ in
                        DispatchQueue.main.async { self?.refresh() }
                    }
                }
                continue
            }
            if scrollView.convert(scrollView.bounds, to: window).contains(point) {
                picked = scrollView
            }
        }
        offsetObservations = offsetObservations.filter { horizontal.contains($0.key) }
        setTarget(picked)
    }

    private func collect(_ view: UIView, into scrollViews: inout [UIScrollView]) {
        guard !view.isHidden, view.alpha > 0.01 else { return }
        if let scrollView = view as? UIScrollView {
            scrollViews.append(scrollView)
        }
        for subview in view.subviews {
            collect(subview, into: &scrollViews)
        }
    }

    private func setTarget(_ scrollView: UIScrollView?) {
        if scrollView === target, (scrollView == nil) == (interaction == nil) { return }
        target = scrollView
        guard let scrollView, let tabBar else {
            if let interaction {
                interaction.view?.removeInteraction(interaction)
                self.interaction = nil
            }
            return
        }
        let edgeInteraction = interaction ?? UIScrollEdgeElementContainerInteraction()
        edgeInteraction.edge = .bottom
        edgeInteraction.scrollView = scrollView
        if edgeInteraction.view !== tabBar {
            edgeInteraction.view?.removeInteraction(edgeInteraction)
            tabBar.addInteraction(edgeInteraction)
        }
        interaction = edgeInteraction
    }
}
#endif
