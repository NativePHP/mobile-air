import SwiftUI

/// The blur under the native tab bar, for a tab's root level. `style` is the
/// tabs root's `edge_blur` prop: "soft" (the default, sent as nothing),
/// "hard" or "none".
///
/// "soft" hides iOS 26's own soft scroll edge effect and draws
/// `TabBarProgressiveBlur` instead: the system effect is too faint to blur
/// rows behind the floating glass bar, and its strength can't be raised.
/// The band is laid over the whole level, so it covers whichever page of a
/// horizontal pager is on screen. Don't add a second system effect for the
/// list inside a page (an edge element interaction on the tab bar, say): the
/// two stack and wash out everything under the bar instead of blurring it.
///
/// "hard" sets the system's hard style for the scroll views in the level,
/// and "none" hides the effect there. An empty style (a pushed level, or a
/// root that hides the tab bar) leaves the level alone. A root level with a
/// bottom bar keeps the system effect (`hasBottomBar`): the bar's own edge
/// blur sits inside this modifier and wins for the bar's region, and a band
/// over the level would blur the bar itself. Before iOS 26 this does nothing.
///
/// Each style applies only while the tab bar is actually on screen across
/// the foot of the level (`TabBarEdgeEffect`). Content can hide the bar from
/// inside a root level (a UIKit navigation controller hosted there that
/// pushes its own pages, say), and core's own signals never see that.
struct TabBarEdgeBlurModifier: ViewModifier {
    let style: String
    var hasBottomBar: Bool = false

    func body(content: Content) -> some View {
        #if compiler(>=6.2)
        if #available(iOS 26.0, *) {
            switch style {
            case "none", "hard":
                content.modifier(TabBarEdgeEffect(style: style))
            case "soft" where !hasBottomBar:
                content.modifier(TabBarEdgeEffect(style: style))
            default:
                content
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
/// `TabBarEdgeBlurModifier`'s styles, applied while `TabBarVisibilityProbe`
/// sees the tab bar across the foot of the level and dropped while it is
/// hidden or off screen, however that came about. Then no band is drawn,
/// and nothing hides or restyles the bottom edge effect, so a page shown in
/// the level can blur under its own bottom bar.
///
/// The structure stays the same either way: only the modifiers' values and
/// the band change, so the level's content keeps its identity and state.
@available(iOS 26.0, *)
private struct TabBarEdgeEffect: ViewModifier {
    let style: String
    @State private var tabBarShows = true

    func body(content: Content) -> some View {
        content
            .scrollEdgeEffectHidden(tabBarShows && style != "hard", for: .bottom)
            .scrollEdgeEffectStyle(tabBarShows && style == "hard" ? .hard : nil, for: .bottom)
            .overlay {
                if tabBarShows && style == "soft" {
                    TabBarProgressiveBlur()
                }
            }
            .background {
                TabBarVisibilityProbe { tabBarShows = $0 }
                    .ignoresSafeArea()
                    .allowsHitTesting(false)
                    .accessibilityHidden(true)
            }
    }
}

/// Says whether the tab bar shows across the foot of the view it backs: in
/// the window, not hidden or faded out (it or anything above it), and lying
/// over the bottom half of the view wider than it is tall. A bar at the top
/// or side, or the whole-window frame a resize can leave for a moment, does
/// not count. It reports changes only.
///
/// It looks again on the next turn after anything that can move or hide the
/// bar: joining a window, a layout or safe-area change (hiding the bar moves
/// the level's bottom inset), and the bar's own hidden, alpha or frame
/// changing. Hiding fades the bar to clear before setting `isHidden`, and
/// showing does the reverse, so either is seen as it starts.
@available(iOS 26.0, *)
private struct TabBarVisibilityProbe: UIViewRepresentable {
    let onChange: (Bool) -> Void

    func makeUIView(context: Context) -> ProbeView {
        let view = ProbeView()
        view.onChange = onChange
        return view
    }

    func updateUIView(_ view: ProbeView, context: Context) {
        view.onChange = onChange
    }

    static func dismantleUIView(_ view: ProbeView, coordinator: ()) {
        view.stopObserving()
    }

    final class ProbeView: UIView {
        var onChange: ((Bool) -> Void)?
        private var reported: Bool?
        private weak var observedBar: UITabBar?
        private var observations: [NSKeyValueObservation] = []
        private var checkScheduled = false

        override init(frame: CGRect) {
            super.init(frame: frame)
            isUserInteractionEnabled = false
            isAccessibilityElement = false
        }

        required init?(coder: NSCoder) {
            fatalError("init(coder:) has not been implemented")
        }

        override func didMoveToWindow() {
            super.didMoveToWindow()
            if window == nil {
                stopObserving()
            } else {
                scheduleCheck()
            }
        }

        override func layoutSubviews() {
            super.layoutSubviews()
            scheduleCheck()
        }

        override func safeAreaInsetsDidChange() {
            super.safeAreaInsetsDidChange()
            scheduleCheck()
        }

        func stopObserving() {
            observations.forEach { $0.invalidate() }
            observations = []
            observedBar = nil
        }

        /// On the next turn, once: the callbacks come inside UIKit and
        /// SwiftUI updates, and the probe's owner changes state on a report.
        private func scheduleCheck() {
            guard !checkScheduled else { return }
            checkScheduled = true
            DispatchQueue.main.async { [weak self] in
                guard let self else { return }
                self.checkScheduled = false
                self.check()
            }
        }

        private func check() {
            guard window != nil, let bar = tabBarController()?.tabBar else { return }
            observe(bar)
            let shows = showsAcrossFoot(bar)
            guard shows != reported else { return }
            reported = shows
            onChange?(shows)
        }

        private func showsAcrossFoot(_ bar: UITabBar) -> Bool {
            guard let window, bar.window === window else { return false }
            var view: UIView? = bar
            while let current = view, current !== window {
                if current.isHidden || current.alpha < 0.01 { return false }
                view = current.superview
            }
            let overlap = bounds.intersection(bar.convert(bar.bounds, to: self))
            return !overlap.isNull
                && overlap.width > overlap.height
                && overlap.midY > bounds.midY
        }

        private func observe(_ bar: UITabBar) {
            guard observedBar !== bar else { return }
            stopObserving()
            observedBar = bar
            observations = [
                bar.observe(\.isHidden) { [weak self] _, _ in self?.scheduleCheck() },
                bar.observe(\.alpha) { [weak self] _, _ in self?.scheduleCheck() },
                bar.observe(\.frame) { [weak self] _, _ in self?.scheduleCheck() },
                bar.observe(\.center) { [weak self] _, _ in self?.scheduleCheck() },
            ]
        }

        private func tabBarController() -> UITabBarController? {
            var responder: UIResponder? = self
            while let current = responder {
                if let controller = current as? UIViewController, let tabs = controller.tabBarController {
                    return tabs
                }
                responder = current.next
            }
            return nil
        }
    }
}
#endif

/// A progressive blur from `reach` points above the tab bar to the bottom of
/// the screen, laid over a tab's content and under the tab bar (which UIKit
/// draws above the tab's view).
///
/// Built from public pieces only. A system material masked by a gradient
/// doesn't blur progressively: part way down its ramp it shows the sharp
/// content and the blurred copy at once, and once it is fully in, small text
/// is already gone. So the band stacks `steps` weak blurs instead
/// (`PartialBlurView`), each covering from a little lower than the last down
/// to the bottom with a long soft top, the first barely blurred and each a
/// little stronger. Each re-blurs what the ones above it already blurred, so
/// the blur grows towards the bottom in steps too small to see, close to the
/// system's soft edge under a `.safeAreaBar` composer but stronger. Content
/// stays recognisable behind the glass bar: only a light tint of the window
/// background (`UI.SetBackground`, else systemBackground) is laid over the
/// band's foot. Every layer starts from clear, so the band has no visible
/// top edge.
///
/// The tab bar's height is the level's bottom safe-area inset, read from the
/// band's own geometry with the keyboard left out, so the band doesn't climb
/// with the keyboard. It takes no touches and is hidden from VoiceOver.
@available(iOS 26.0, *)
struct TabBarProgressiveBlur: View {
    /// How far above the tab bar's top the band starts. The level's bottom
    /// safe-area inset already reaches the floating bar's top edge.
    var reach: CGFloat = 20
    /// How many blur layers, and how strong the first and the last are (a
    /// fraction of the system ultra-thin material's blur). The ones between
    /// step evenly, so the blur starts barely there and builds gently.
    var steps: Int = 12
    var firstAmount: CGFloat = 0.02
    var lastAmount: CGFloat = 0.08
    /// Where the last layer starts, and how long each layer's soft top is,
    /// as fractions of the band's height.
    var lastStart: CGFloat = 0.7
    var ramp: CGFloat = 0.2
    /// How much of the window background is laid over the band's foot.
    var tint: Double = 0.2

    @ObservedObject private var windowBackground = WindowBackgroundState.shared

    var body: some View {
        GeometryReader { proxy in
            let height = proxy.safeAreaInsets.bottom + reach
            band
                .frame(height: height)
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottom)
                .ignoresSafeArea(.container, edges: .bottom)
        }
        .ignoresSafeArea(.keyboard)
        .allowsHitTesting(false)
        .accessibilityHidden(true)
    }

    private var band: some View {
        let background = windowBackground.color ?? Color(uiColor: .systemBackground)
        return ZStack {
            ForEach(0..<steps, id: \.self) { index in
                let progress = CGFloat(index) / CGFloat(max(steps - 1, 1))
                let from = lastStart * progress
                PartialBlurView(amount: firstAmount + (lastAmount - firstAmount) * progress)
                    .mask {
                        LinearGradient(
                            stops: [
                                .init(color: .clear, location: from),
                                .init(color: .black, location: min(from + ramp, 1)),
                            ],
                            startPoint: .top,
                            endPoint: .bottom
                        )
                    }
            }
            LinearGradient(
                stops: [
                    .init(color: background.opacity(0), location: 0.5),
                    .init(color: background.opacity(tint), location: 1.0),
                ],
                startPoint: .top,
                endPoint: .bottom
            )
        }
    }
}

/// A blur weaker than any system material: a `UIVisualEffectView` whose
/// change from no effect to the system thin material is left paused at
/// `amount` by a `UIViewPropertyAnimator` (public API; how weak blurs are
/// usually made on iOS). The tint scales down with the blur.
///
/// A paused animator doesn't survive everything: the view re-arms it a turn
/// after it joins a window, the app becomes active again or light or dark
/// mode changes, and stops it when it leaves the window or is dismantled
/// (releasing a paused animator traps).
@available(iOS 26.0, *)
private struct PartialBlurView: UIViewRepresentable {
    let amount: CGFloat

    func makeUIView(context: Context) -> PartialBlurUIView {
        PartialBlurUIView(amount: amount)
    }

    func updateUIView(_ view: PartialBlurUIView, context: Context) {
        view.setAmount(amount)
    }

    static func dismantleUIView(_ view: PartialBlurUIView, coordinator: ()) {
        view.disarm()
    }
}

@available(iOS 26.0, *)
final class PartialBlurUIView: UIVisualEffectView {
    private var amount: CGFloat
    private var animator: UIViewPropertyAnimator?
    private var foregroundObserver: NSObjectProtocol?

    init(amount: CGFloat) {
        self.amount = amount
        super.init(effect: nil)
        isUserInteractionEnabled = false
        foregroundObserver = NotificationCenter.default.addObserver(
            forName: UIApplication.didBecomeActiveNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            DispatchQueue.main.async { self?.arm() }
        }
        registerForTraitChanges([UITraitUserInterfaceStyle.self]) { (view: PartialBlurUIView, _) in
            // Not straight away: this runs before the effect view rebuilds
            // its filters for the new appearance. Armed now, the animator
            // only holds the old appearance's filters, and any the new one
            // adds (light mode's brightness, for one) sit at full strength.
            // Twelve of those stack into an opaque strip.
            DispatchQueue.main.async { [weak view] in view?.arm() }
        }
    }

    required init?(coder: NSCoder) {
        fatalError("init(coder:) has not been implemented")
    }

    deinit {
        if let foregroundObserver {
            NotificationCenter.default.removeObserver(foregroundObserver)
        }
    }

    func setAmount(_ newAmount: CGFloat) {
        guard newAmount != amount else { return }
        amount = newAmount
        arm()
    }

    override func didMoveToWindow() {
        super.didMoveToWindow()
        if window != nil {
            // Not straight away: SwiftUI can add the view inside an update
            // with animations off, and then the animator's block runs at
            // once and leaves the full material in place.
            DispatchQueue.main.async { [weak self] in self?.arm() }
        } else {
            disarm()
        }
    }

    func arm() {
        disarm()
        guard window != nil else { return }
        effect = nil
        let animator = UIViewPropertyAnimator(duration: 1, curve: .linear) { [weak self] in
            self?.effect = UIBlurEffect(style: .systemUltraThinMaterial)
        }
        animator.pausesOnCompletion = true
        animator.fractionComplete = amount
        self.animator = animator
    }

    func disarm() {
        guard let animator else { return }
        animator.stopAnimation(true)
        self.animator = nil
    }
}
