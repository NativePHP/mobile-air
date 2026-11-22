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
struct TabBarEdgeBlurModifier: ViewModifier {
    let style: String
    var hasBottomBar: Bool = false

    func body(content: Content) -> some View {
        #if compiler(>=6.2)
        if #available(iOS 26.0, *) {
            switch style {
            case "none":
                content.scrollEdgeEffectHidden(true, for: .bottom)
            case "hard":
                content.scrollEdgeEffectStyle(.hard, for: .bottom)
            case "soft" where !hasBottomBar:
                content
                    .scrollEdgeEffectHidden(true, for: .bottom)
                    .overlay { TabBarProgressiveBlur() }
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

/// A progressive blur from `reach` points above the tab bar to the bottom of
/// the screen, laid over a tab's content and under the tab bar (which UIKit
/// draws above the tab's view).
///
/// Built from public pieces only. A system material masked by a gradient
/// doesn't blur progressively: part way down its ramp it shows the sharp
/// content and the blurred copy at once, and once it is fully in, small text
/// is already gone. So the band stacks `steps` weak blurs instead
/// (`PartialBlurView`), each covering from a little lower than the last down
/// to the bottom with a short soft top. Each re-blurs what the ones above it
/// already blurred, so the blur grows towards the bottom in steps too small
/// to see. A fade to the window background (`UI.SetBackground`, else
/// systemBackground) on top ends the band in the background colour, the way
/// the NYT Games app's does. Every layer starts from clear, so the band has
/// no visible top edge.
///
/// The tab bar's height is the level's bottom safe-area inset, read from the
/// band's own geometry with the keyboard left out, so the band doesn't climb
/// with the keyboard. It takes no touches and is hidden from VoiceOver.
@available(iOS 26.0, *)
struct TabBarProgressiveBlur: View {
    /// How far above the tab bar's top the band starts.
    var reach: CGFloat = 56
    /// How many blur layers, and how strong each is (a fraction of the
    /// system thin material's blur).
    var steps: Int = 8
    var amount: CGFloat = 0.14
    /// Where the last layer starts, and how long each layer's soft top is,
    /// as fractions of the band's height.
    var lastStart: CGFloat = 0.65
    var ramp: CGFloat = 0.15

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
                let from = lastStart * CGFloat(index) / CGFloat(max(steps - 1, 1))
                PartialBlurView(amount: amount)
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
                    .init(color: background.opacity(0), location: 0.3),
                    .init(color: background.opacity(0.35), location: 0.65),
                    .init(color: background.opacity(0.7), location: 1.0),
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
/// A paused animator doesn't survive everything: the view re-arms it just
/// after it joins a window, when the app becomes active again and when light
/// or dark mode changes, and stops it when it leaves the window or is
/// dismantled (releasing a paused animator traps).
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
            view.arm()
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
