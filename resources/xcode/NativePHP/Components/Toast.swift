import UIKit
import WebKit

// MARK: - Toast Position

enum ToastPosition: String {
    case top
    case bottom

    static func from(_ value: Any?) -> ToastPosition? {
        guard let raw = value as? String else { return nil }
        return ToastPosition(rawValue: raw.lowercased())
    }
}

// MARK: - Toast Configuration

struct ToastConfiguration {
    let id: String
    let message: String?
    let html: String?
    /// Seconds the toast stays on screen once it reaches the front of the
    /// stack. `nil` means "work it out from the message", `0` means "stay
    /// until dismissed".
    let duration: TimeInterval?
    let position: ToastPosition
    let dismissible: Bool

    init(
        id: String = UUID().uuidString,
        message: String? = nil,
        html: String? = nil,
        duration: TimeInterval? = nil,
        position: ToastPosition = .bottom,
        dismissible: Bool = true
    ) {
        self.id = id
        self.message = message
        self.html = html
        self.duration = duration
        self.position = position
        self.dismissible = dismissible
    }

    init?(parameters: [String: Any]) {
        let message = parameters["message"] as? String
        let html = parameters["html"] as? String

        guard message != nil || html != nil else { return nil }

        var duration: TimeInterval?
        if let number = parameters["duration"] as? NSNumber {
            duration = max(number.doubleValue, 0)
        } else if let string = parameters["duration"] as? String {
            switch string.lowercased() {
            case "short": duration = ToastMetrics.shortDuration
            case "long": duration = ToastMetrics.longDuration
            default: duration = Double(string).map { max($0, 0) }
            }
        }

        self.init(
            id: parameters["id"] as? String ?? UUID().uuidString,
            message: message,
            html: html,
            duration: duration,
            position: ToastPosition.from(parameters["position"]) ?? .bottom,
            dismissible: parameters["dismissible"] as? Bool ?? true
        )
    }
}

// MARK: - Metrics

enum ToastMetrics {
    /// The most toasts we'll keep in the visible stack. Anything pushed while
    /// the stack is full is silently dropped.
    static let maxStack = 5

    static let shortDuration: TimeInterval = 2.0
    static let longDuration: TimeInterval = 4.0

    /// How much of each toast behind the front one peeks out.
    static let peek: CGFloat = 10.0
    /// How far the whole stack shifts towards its edge for each extra toast,
    /// so the front toast visibly nudges over as new ones arrive.
    static let nudge: CGFloat = 4.0
    /// Distance from the safe area to the front toast when it's on its own.
    static let baseInset: CGFloat = 24.0
    /// How close to the safe area a full stack is allowed to get.
    static let minInset: CGFloat = 8.0
    /// How much smaller each toast behind the front one is drawn.
    static let scaleStep: CGFloat = 0.04
    static let horizontalMargin: CGFloat = 16.0

    /// How long we'll wait for a view-backed toast to render before showing
    /// it at a best-guess height.
    static let renderTimeout: TimeInterval = 1.5
    static let fallbackHtmlHeight: CGFloat = 88.0
}

// MARK: - Passthrough Window

/// Hosts the toast stack above the app without swallowing touches: anything
/// that doesn't land on a toast is passed straight through to the app below.
final class ToastWindow: UIWindow {
    override func hitTest(_ point: CGPoint, with event: UIEvent?) -> UIView? {
        guard let hit = super.hitTest(point, with: event) else { return nil }

        if hit === self || hit === rootViewController?.view {
            return nil
        }

        return hit
    }
}

/// The root view of the toast window. Re-lays out the stack whenever the
/// window resizes (rotation, split view, keyboard-driven safe area changes).
final class ToastRootView: UIView {
    var onLayout: (() -> Void)?

    override func layoutSubviews() {
        super.layoutSubviews()
        onLayout?()
    }
}

// MARK: - Toast View

/// A single toast: either the built-in text style, or a developer-supplied
/// view rendered as HTML in a transparent web view.
final class ToastView: UIView, UIGestureRecognizerDelegate {
    let configuration: ToastConfiguration

    /// Whether this toast is currently interactive (only the front toast is).
    var canInteract: (() -> Bool)?
    var onDismiss: ((ToastView) -> Void)?
    /// Called when the toast's content settles at a different height after it
    /// has already been shown.
    var onContentResize: (() -> Void)?

    private var webView: WKWebView?
    private var navigationHandler: ToastWebNavigationHandler?
    private var renderCompletion: ((Bool) -> Void)?
    private var renderTimeoutTimer: Timer?
    private var hasFinishedRendering = false
    private var hasRetriedWithoutBaseURL = false

    /// Shared between toasts so app assets (CSS, images, fonts) resolve
    /// through the same handler the main web view uses.
    private static let schemeHandler = PHPSchemeHandler()

    var id: String { configuration.id }

    init(configuration: ToastConfiguration) {
        self.configuration = configuration

        super.init(frame: .zero)

        backgroundColor = .clear
        isUserInteractionEnabled = configuration.dismissible

        if configuration.dismissible {
            addGestureRecognizers()
        }
    }

    required init?(coder: NSCoder) {
        fatalError("init(coder:) has not been implemented")
    }

    deinit {
        renderTimeoutTimer?.invalidate()
    }

    // MARK: Rendering

    /// Build and measure the toast's content, calling back once it's ready to
    /// be added to the stack.
    func render(in window: UIWindow, completion: @escaping (Bool) -> Void) {
        let available = availableWidth(in: window)

        if let html = configuration.html {
            renderHtml(html, width: available, maxHeight: window.bounds.height * 0.6, completion: completion)
        } else {
            renderLabel(width: available)
            completion(true)
        }
    }

    private func availableWidth(in window: UIWindow) -> CGFloat {
        let insets = window.safeAreaInsets

        return max(
            window.bounds.width - insets.left - insets.right - (ToastMetrics.horizontalMargin * 2),
            120
        )
    }

    private func renderLabel(width maxWidth: CGFloat) {
        let horizontalPadding: CGFloat = 20.0
        let verticalPadding: CGFloat = 14.0

        let label = UILabel()
        label.textColor = .white
        label.textAlignment = .center
        label.text = configuration.message
        label.numberOfLines = 0
        label.font = .preferredFont(forTextStyle: .subheadline)

        let maxTextWidth = maxWidth - (horizontalPadding * 2)
        let textSize = label.sizeThatFits(CGSize(width: maxTextWidth, height: .greatestFiniteMagnitude))

        let toastWidth = min(textSize.width + (horizontalPadding * 2), maxWidth)
        let toastHeight = textSize.height + (verticalPadding * 2)

        backgroundColor = UIColor.black.withAlphaComponent(0.8)
        layer.cornerRadius = 12
        layer.cornerCurve = .continuous
        layer.shadowColor = UIColor.black.cgColor
        layer.shadowOpacity = 0.2
        layer.shadowRadius = 12
        layer.shadowOffset = CGSize(width: 0, height: 4)

        bounds = CGRect(x: 0, y: 0, width: toastWidth, height: toastHeight)

        label.frame = bounds.insetBy(dx: horizontalPadding, dy: verticalPadding)
        label.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        label.isUserInteractionEnabled = false
        addSubview(label)
    }

    private func renderHtml(
        _ html: String,
        width: CGFloat,
        maxHeight: CGFloat,
        completion: @escaping (Bool) -> Void
    ) {
        renderCompletion = completion

        let webConfiguration = WKWebViewConfiguration()
        webConfiguration.websiteDataStore = WebView.dataStore
        webConfiguration.setURLSchemeHandler(ToastView.schemeHandler, forURLScheme: "php")

        // Start with a 1pt tall viewport so the document's scrollHeight
        // reports the content height rather than the viewport height.
        let webView = WKWebView(
            frame: CGRect(x: 0, y: 0, width: width, height: 1),
            configuration: webConfiguration
        )
        webView.isOpaque = false
        webView.backgroundColor = .clear
        webView.scrollView.backgroundColor = .clear
        webView.scrollView.isScrollEnabled = false
        webView.scrollView.contentInsetAdjustmentBehavior = .never
        // Taps and swipes belong to the toast, not to the content inside it.
        webView.isUserInteractionEnabled = false

        let handler = ToastWebNavigationHandler { [weak self] success in
            guard let self = self else { return }

            guard success else {
                self.handleRenderFailure(maxHeight: maxHeight)
                return
            }

            self.measureContentHeight(maxHeight: maxHeight)
        }

        webView.navigationDelegate = handler

        self.webView = webView
        self.navigationHandler = handler

        bounds = CGRect(x: 0, y: 0, width: width, height: ToastMetrics.fallbackHtmlHeight)
        webView.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        addSubview(webView)

        renderTimeoutTimer = Timer.scheduledTimer(
            withTimeInterval: ToastMetrics.renderTimeout,
            repeats: false
        ) { [weak self] _ in
            // Slow or stalled render — show it anyway rather than losing the toast.
            self?.finishRendering(true, height: ToastMetrics.fallbackHtmlHeight, maxHeight: maxHeight)
        }

        webView.loadHTMLString(html, baseURL: URL(string: "php://127.0.0.1/"))
    }

    /// If the content couldn't be loaded against the app's base URL, try once
    /// more without it. Relative asset paths won't resolve, but the toast is
    /// still shown rather than disappearing without a trace.
    private func handleRenderFailure(maxHeight: CGFloat) {
        guard !hasRetriedWithoutBaseURL,
              let html = configuration.html,
              let webView = webView else {
            finishRendering(false, height: 0, maxHeight: maxHeight)
            return
        }

        hasRetriedWithoutBaseURL = true
        webView.loadHTMLString(html, baseURL: nil)
    }

    private func measureContentHeight(maxHeight: CGFloat) {
        let js = """
        (function() {
            var body = document.body, doc = document.documentElement;
            return Math.ceil(Math.max(
                body ? body.scrollHeight : 0,
                body ? body.offsetHeight : 0,
                doc ? doc.scrollHeight : 0,
                doc ? doc.offsetHeight : 0
            ));
        })();
        """

        // Give web fonts and images a moment to settle before measuring.
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.05) { [weak self] in
            guard let self = self, let webView = self.webView else { return }

            webView.evaluateJavaScript(js) { [weak self] result, _ in
                guard let self = self else { return }

                let measured = (result as? NSNumber)?.doubleValue ?? 0

                self.finishRendering(
                    true,
                    height: measured > 0 ? CGFloat(measured) : ToastMetrics.fallbackHtmlHeight,
                    maxHeight: maxHeight
                )
            }
        }
    }

    private func finishRendering(_ success: Bool, height: CGFloat, maxHeight: CGFloat) {
        // A slow render that lands after we've already shown the toast at its
        // fallback height still gets to correct that height.
        if hasFinishedRendering {
            guard success, applyHeight(height, maxHeight: maxHeight) else { return }

            onContentResize?()

            return
        }

        hasFinishedRendering = true

        renderTimeoutTimer?.invalidate()
        renderTimeoutTimer = nil

        if success {
            _ = applyHeight(height, maxHeight: maxHeight)
        }

        let completion = renderCompletion
        renderCompletion = nil
        completion?(success)
    }

    /// Resize the toast to fit its content. Returns whether anything changed.
    @discardableResult
    private func applyHeight(_ height: CGFloat, maxHeight: CGFloat) -> Bool {
        let clamped = min(max(height, 32), maxHeight)

        guard abs(clamped - bounds.height) > 1 else { return false }

        bounds = CGRect(x: 0, y: 0, width: bounds.width, height: clamped)
        webView?.frame = bounds

        return true
    }

    // MARK: Gestures

    private func addGestureRecognizers() {
        let tap = UITapGestureRecognizer(target: self, action: #selector(handleTap))
        tap.delegate = self
        addGestureRecognizer(tap)

        let pan = UIPanGestureRecognizer(target: self, action: #selector(handlePan))
        pan.delegate = self
        addGestureRecognizer(pan)
    }

    func gestureRecognizerShouldBegin(_ gestureRecognizer: UIGestureRecognizer) -> Bool {
        return canInteract?() ?? true
    }

    @objc private func handleTap() {
        onDismiss?(self)
    }

    @objc private func handlePan(_ gesture: UIPanGestureRecognizer) {
        let translation = gesture.translation(in: superview)
        let velocity = gesture.velocity(in: superview)

        // Toasts are dragged off towards the edge they're anchored to, or
        // sideways in either direction.
        let dismissingDown = ToastManager.shared.stackPosition == .bottom

        switch gesture.state {
        case .began, .changed:
            let following = dismissingDown ? max(translation.y, 0) : min(translation.y, 0)
            let resisting = (translation.y - following) / 4

            transform = CGAffineTransform(translationX: translation.x, y: following + resisting)

        case .ended, .cancelled, .failed:
            let travelled = dismissingDown ? translation.y : -translation.y
            let flung = dismissingDown ? velocity.y : -velocity.y

            let shouldDismiss = travelled > 44
                || flung > 900
                || abs(translation.x) > 90
                || abs(velocity.x) > 900

            if shouldDismiss {
                ToastManager.shared.dismiss(self, direction: CGVector(dx: translation.x, dy: translation.y))
            } else {
                ToastManager.shared.layoutStack(animated: true)
            }

        default:
            break
        }
    }
}

// MARK: - Web View Navigation Handler

/// Navigation callbacks for a toast's web view. WKWebView only holds its
/// delegate weakly, so the toast keeps hold of this for as long as it needs it.
final class ToastWebNavigationHandler: NSObject, WKNavigationDelegate {
    private let completion: (Bool) -> Void

    init(completion: @escaping (Bool) -> Void) {
        self.completion = completion
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        completion(true)
    }

    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) {
        print("⚠️ Toast content failed to render: \(error.localizedDescription)")
        completion(false)
    }

    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
        print("⚠️ Toast content failed to load: \(error.localizedDescription)")
        completion(false)
    }
}

// MARK: - Toast Manager

/// Presents toasts in a visible stack: the front toast is the one being read,
/// new toasts grow into place behind it so the user can see more is coming.
final class ToastManager {
    static let shared = ToastManager()

    /// Front of the stack first.
    private var toasts: [ToastView] = []
    /// Toasts whose content is still being rendered/measured.
    private var rendering: [ToastView] = []

    /// Where the current stack lives. Toasts joining an existing stack adopt
    /// this, whatever position they asked for, so the stack never splits.
    private(set) var stackPosition: ToastPosition = .bottom

    private var window: ToastWindow?
    private var dismissTimer: Timer?
    private var timedToastId: String?

    private init() {}

    // MARK: Presenting

    func show(_ configuration: ToastConfiguration) {
        onMain { [weak self] in
            self?.present(configuration)
        }
    }

    /// Plain text toast with the default styling, for callers that predate
    /// the configuration-based API (Dialog.Toast, plugins).
    func show(message: String, duration: TimeInterval? = nil) {
        show(ToastConfiguration(message: message, duration: duration))
    }

    private func present(_ configuration: ToastConfiguration) {
        guard let window = ensureWindow() else { return }

        // The stack is full: drop this toast rather than queueing it up behind
        // toasts the user hasn't even seen yet.
        guard toasts.count + rendering.count < ToastMetrics.maxStack else {
            print("🍞 Toast dropped, stack is full (\(ToastMetrics.maxStack)): \(configuration.id)")
            return
        }

        // An empty stack takes the position it's given; an existing stack keeps
        // the position it already has.
        if toasts.isEmpty && rendering.isEmpty {
            stackPosition = configuration.position
        }

        let toast = ToastView(configuration: configuration)
        toast.canInteract = { [weak self, weak toast] in
            guard let self = self, let toast = toast else { return false }
            return self.toasts.first === toast
        }
        toast.onDismiss = { [weak self] toast in
            self?.dismiss(toast, direction: nil)
        }
        toast.onContentResize = { [weak self] in
            self?.layoutStack(animated: true)
        }

        rendering.append(toast)

        toast.render(in: window) { [weak self, weak toast] success in
            guard let self = self, let toast = toast else { return }

            // Dismissing a toast while it renders takes it out of the list,
            // which is how we know it should never reach the stack.
            guard let index = self.rendering.firstIndex(where: { $0 === toast }) else { return }

            self.rendering.remove(at: index)

            guard success else {
                self.resetStackPositionIfEmpty()
                return
            }

            self.insert(toast)
        }
    }

    private func insert(_ toast: ToastView) {
        guard let window = window, let container = window.rootViewController?.view else { return }

        // Anything that arrived while this toast was rendering could have
        // filled the stack.
        guard toasts.count < ToastMetrics.maxStack else {
            print("🍞 Toast dropped, stack filled while rendering: \(toast.id)")
            resetStackPositionIfEmpty()
            return
        }

        toasts.append(toast)

        container.addSubview(toast)
        // New toasts sit behind the ones already on screen.
        container.sendSubviewToBack(toast)

        let index = toasts.count - 1
        let target = layout(for: index, count: toasts.count, in: window)

        toast.center = target.center
        toast.alpha = 0
        toast.transform = target.transform.scaledBy(x: 0.86, y: 0.86)

        UIView.animate(
            withDuration: 0.45,
            delay: 0,
            usingSpringWithDamping: 0.78,
            initialSpringVelocity: 0.4,
            options: [.allowUserInteraction, .beginFromCurrentState]
        ) {
            toast.alpha = 1
            toast.transform = target.transform
            self.layoutStack(animated: false)
        }

        startTimerIfNeeded()
    }

    // MARK: Dismissing

    func dismiss(id: String) {
        onMain { [weak self] in
            guard let self = self else { return }

            if let toast = self.toasts.first(where: { $0.id == id }) {
                self.dismiss(toast, direction: nil)
                return
            }

            // Still rendering — drop it before it ever reaches the stack.
            self.rendering.removeAll { $0.id == id }
            self.resetStackPositionIfEmpty()
        }
    }

    func dismissAll() {
        onMain { [weak self] in
            guard let self = self else { return }

            self.rendering.removeAll()

            for toast in self.toasts {
                self.dismiss(toast, direction: nil)
            }
        }
    }

    func dismiss(_ toast: ToastView, direction: CGVector?) {
        guard let index = toasts.firstIndex(where: { $0 === toast }) else { return }

        toasts.remove(at: index)

        if timedToastId == toast.id {
            cancelTimer()
        }

        let edgeOffset: CGFloat = stackPosition == .bottom ? 24 : -24
        let travel = direction.map { vector -> CGAffineTransform in
            let magnitude = max(abs(vector.dx), abs(vector.dy))
            let scale = magnitude > 0 ? 900 / magnitude : 1

            return CGAffineTransform(translationX: vector.dx * scale, y: vector.dy * scale)
        } ?? CGAffineTransform(translationX: 0, y: edgeOffset).scaledBy(x: 0.9, y: 0.9)

        UIView.animate(
            withDuration: 0.28,
            delay: 0,
            options: [.curveEaseIn, .allowUserInteraction, .beginFromCurrentState]
        ) {
            toast.alpha = 0
            toast.transform = travel
            self.layoutStack(animated: false)
        } completion: { _ in
            toast.removeFromSuperview()
            self.teardownWindowIfEmpty()
        }

        startTimerIfNeeded()
    }

    // MARK: Timing

    /// Only the front toast counts down: whatever is stacked behind it waits
    /// its turn, so nothing disappears before it's been read.
    private func startTimerIfNeeded() {
        guard let front = toasts.first else {
            cancelTimer()
            return
        }

        guard timedToastId != front.id else { return }

        cancelTimer()

        let duration = front.configuration.duration ?? autoDuration(for: front.configuration)

        // A zero duration means "stay until dismissed".
        guard duration > 0 else { return }

        timedToastId = front.id
        dismissTimer = Timer.scheduledTimer(withTimeInterval: duration, repeats: false) { [weak self, weak front] _ in
            guard let self = self, let front = front else { return }
            self.dismiss(front, direction: nil)
        }
    }

    private func cancelTimer() {
        dismissTimer?.invalidate()
        dismissTimer = nil
        timedToastId = nil
    }

    private func autoDuration(for configuration: ToastConfiguration) -> TimeInterval {
        guard let message = configuration.message else {
            return ToastMetrics.longDuration
        }

        // Roughly 3 words a second, on top of a base duration, clamped to
        // something between 2 and 10 seconds.
        let wordCount = message.split(separator: " ").count
        let readingTime = Double(wordCount) / 3.0

        return min(max(2.0 + readingTime, 2.0), 10.0)
    }

    // MARK: Layout

    /// Position every toast in the stack. The front toast sits closest to its
    /// edge and shifts a little further towards it as the stack grows; the
    /// ones behind peek out, each slightly smaller than the one in front.
    func layoutStack(animated: Bool) {
        guard let window = window else { return }

        let apply = { [weak self] in
            guard let self = self else { return }

            for (index, toast) in self.toasts.enumerated() {
                let target = self.layout(for: index, count: self.toasts.count, in: window)
                toast.center = target.center
                toast.transform = target.transform
            }
        }

        guard animated else {
            apply()
            return
        }

        UIView.animate(
            withDuration: 0.35,
            delay: 0,
            usingSpringWithDamping: 0.82,
            initialSpringVelocity: 0.2,
            options: [.allowUserInteraction, .beginFromCurrentState],
            animations: apply
        )
    }

    private func layout(
        for index: Int,
        count: Int,
        in window: UIWindow
    ) -> (center: CGPoint, transform: CGAffineTransform) {
        let insets = window.safeAreaInsets
        let bounds = window.bounds

        // The whole stack creeps towards its edge as it grows, so the front
        // toast nudges over to make room for the one growing in behind it.
        let shift = min(CGFloat(max(count - 1, 0)), CGFloat(ToastMetrics.maxStack - 1)) * ToastMetrics.nudge
        let frontInset = max(ToastMetrics.baseInset - shift, ToastMetrics.minInset)
        let inset = frontInset + (CGFloat(index) * ToastMetrics.peek)

        let scale = max(1 - (CGFloat(index) * ToastMetrics.scaleStep), 0.5)
        let toast = toasts[index]
        let scaledHeight = toast.bounds.height * scale

        let centerY: CGFloat
        switch stackPosition {
        case .bottom:
            let edge = bounds.height - insets.bottom - inset
            centerY = edge - (scaledHeight / 2)
        case .top:
            let edge = insets.top + inset
            centerY = edge + (scaledHeight / 2)
        }

        return (
            center: CGPoint(x: bounds.midX, y: centerY),
            transform: CGAffineTransform(scaleX: scale, y: scale)
        )
    }

    // MARK: Window

    private func ensureWindow() -> ToastWindow? {
        if let window = window {
            return window
        }

        let scenes = UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }

        guard let scene = scenes.first(where: { $0.activationState == .foregroundActive }) ?? scenes.first else {
            print("⚠️ Toast could not find a window scene to present in")
            return nil
        }

        let window = ToastWindow(windowScene: scene)
        window.backgroundColor = .clear
        // Above the app, below system alerts.
        window.windowLevel = .alert - 1

        let root = UIViewController()
        let container = ToastRootView()
        container.backgroundColor = .clear
        container.onLayout = { [weak self] in
            self?.layoutStack(animated: false)
        }
        root.view = container

        window.rootViewController = root
        window.isHidden = false

        self.window = window

        return window
    }

    private func teardownWindowIfEmpty() {
        guard toasts.isEmpty, rendering.isEmpty else { return }

        resetStackPositionIfEmpty()

        window?.isHidden = true
        window = nil
    }

    private func resetStackPositionIfEmpty() {
        guard toasts.isEmpty, rendering.isEmpty else { return }

        cancelTimer()
        stackPosition = .bottom
    }

    private func onMain(_ work: @escaping () -> Void) {
        // Bridge calls from the queue worker arrive on a background pthread,
        // and UIKit has to run on the main thread.
        if Thread.isMainThread {
            work()
        } else {
            DispatchQueue.main.async(execute: work)
        }
    }
}

// MARK: - Legacy Entry Point

/// Backwards compatible helper for plain text toasts.
func showToast(message: String, duration: TimeInterval? = nil) {
    ToastManager.shared.show(
        ToastConfiguration(message: message, duration: duration)
    )
}
