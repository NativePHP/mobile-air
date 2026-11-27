import Combine
import SwiftUI
import UIKit

/// The two things PHP can ask of VoiceOver directly, through the
/// `Accessibility.Announce` and `Accessibility.Focus` bridge functions,
/// plus the announcements the renderer posts for pane titles, live
/// regions and errors. Mirrors Android's `ScreenReader`.
enum ScreenReader {
    /// A focus request waits this long for its element to be rendered.
    private static let focusWait: TimeInterval = 1

    /// A focus request waits this long for the render its handler makes.
    private static let renderWait: TimeInterval = 0.5

    /// The request waiting for that render. Main thread only.
    private static var held: FocusRequest?

    struct FocusRequest: Equatable {
        let ref: String
        let until: Date
    }

    /// The element VoiceOver should move to, by `ref`. Each node with a
    /// `ref` listens (see `NodeAccessibilityFocusModifier`), and the one
    /// that matches takes focus and clears the request.
    static let focusRequest = CurrentValueSubject<FocusRequest?, Never>(nil)

    /// Messages from the current render, posted together once it settles.
    private static var batch: [(text: String, assertive: Bool)] = []

    /// The last post, while it may still be retried. Only the latest post
    /// is kept, so an older message is never read after a newer one.
    /// Main thread only.
    private static var retry: (text: String, assertive: Bool, tries: Int, generation: Int)?
    private static var generation = 0
    /// A post still being spoken, and whether its finish belongs to a post
    /// that a newer one with the same text replaced, so it isn't counted
    /// against the newer one.
    private static var inFlight = false
    private static var skipFinish = false
    private static var finishObserver: NSObjectProtocol?

    /// A polite announcement waits for VoiceOver to finish what it is
    /// saying; an assertive one interrupts. Each post ends the one before
    /// it, reported as unspoken, so messages from the same render (a live
    /// update and an error) are joined into one post. VoiceOver also drops
    /// a post made while it handles a button's activation or a focus move,
    /// so it is posted a moment later, and again (twice at most) when
    /// VoiceOver reports that it wasn't spoken: an error set in the same
    /// render as a focus move is dropped by both. Nothing is posted while
    /// VoiceOver is off.
    static func announce(_ message: String, assertive: Bool) {
        guard UIAccessibility.isVoiceOverRunning, !message.isEmpty else { return }
        observeFinishedAnnouncements()
        if batch.isEmpty {
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.15) { postBatch() }
        }
        if !batch.contains(where: { $0.text == message }) { batch.append((message, assertive)) }
    }

    private static func postBatch() {
        guard !batch.isEmpty else { return }
        let text = batch.map { message in
            let trimmed = message.text.trimmingCharacters(in: .whitespacesAndNewlines)
            return trimmed.last.map { ".!?…。\"”)".contains($0) } == true ? trimmed : trimmed + "."
        }.joined(separator: " ")
        let assertive = batch.contains { $0.assertive }
        batch = []
        skipFinish = inFlight && retry?.text == text
        generation += 1
        let current = generation
        retry = (text, assertive, 2, current)
        post(text, assertive: assertive)
        DispatchQueue.main.asyncAfter(deadline: .now() + 5) {
            if retry?.generation == current { retry = nil }
        }
    }

    private static func post(_ message: String, assertive: Bool) {
        let attributes: [NSAttributedString.Key: Any] = assertive
            ? [.accessibilitySpeechAnnouncementPriority: UIAccessibilityPriority.high.rawValue]
            : [.accessibilitySpeechQueueAnnouncement: true]
        inFlight = true
        UIAccessibility.post(notification: .announcement, argument: NSAttributedString(string: message, attributes: attributes))
    }

    private static func observeFinishedAnnouncements() {
        guard finishObserver == nil else { return }
        finishObserver = NotificationCenter.default.addObserver(
            forName: UIAccessibility.announcementDidFinishNotification, object: nil, queue: .main
        ) { note in
            let value = note.userInfo?[UIAccessibility.announcementStringValueUserInfoKey]
            guard let text = (value as? String) ?? (value as? NSAttributedString)?.string else { return }
            if skipFinish, text == retry?.text {
                skipFinish = false
                return
            }
            inFlight = false
            guard let entry = retry, entry.text == text else { return }
            let spoken = note.userInfo?[UIAccessibility.announcementWasSuccessfulUserInfoKey] as? Bool ?? true
            // Bounded, so a message the user interrupted on purpose isn't
            // read at them again and again.
            if spoken || entry.tries == 0 {
                retry = nil
                return
            }
            retry = (entry.text, entry.assertive, entry.tries - 1, entry.generation)
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.5) {
                // A newer post replaced this one in the meantime.
                guard retry?.generation == entry.generation else { return }
                post(text, assertive: entry.assertive)
            }
        }
    }

    /// Modal containers on screen. `.isModal` keeps VoiceOver from their
    /// siblings, but the navigation and tab bars are UIKit views outside
    /// the SwiftUI tree, so they are hidden here while one is shown.
    private static var modals = 0

    /// The bars hidden for the modals, so only those are shown again.
    private static var hiddenBars: [WeakView] = []

    private struct WeakView {
        weak var view: UIView?
    }

    static func modalDidAppear() {
        modals += 1
        observeSceneActivation()
        if modals == 1 { hideBars() }
    }

    private static var activationObserver: NSObjectProtocol?

    /// A modal shown while its scene was inactive (launching, under a
    /// system alert) found no scene to walk, so walk again on activation.
    private static func observeSceneActivation() {
        guard activationObserver == nil else { return }
        activationObserver = NotificationCenter.default.addObserver(
            forName: UIScene.didActivateNotification, object: nil, queue: .main
        ) { _ in
            if modals > 0 { hideBars() }
        }
    }

    static func modalDidDisappear() {
        guard modals > 0 else { return }
        modals -= 1
        if modals == 0 { showBars() }
    }

    /// Whether `Accessibility::focus()` is about to move VoiceOver, so a
    /// modal that appears in the same render leaves focus to it.
    static var isFocusPending: Bool {
        held != nil || (focusRequest.value.map { $0.until > Date() } ?? false)
    }

    /// The bars of the scene in front, the one the modal is drawn in.
    private static func hideBars() {
        func walk(_ view: UIView) {
            if view is UINavigationBar || view is UITabBar {
                if !view.accessibilityElementsHidden {
                    view.accessibilityElementsHidden = true
                    hiddenBars.append(WeakView(view: view))
                }
                return
            }
            view.subviews.forEach(walk)
        }
        for case let scene as UIWindowScene in UIApplication.shared.connectedScenes
        where scene.activationState == .foregroundActive {
            scene.windows.forEach(walk)
        }
    }

    private static func showBars() {
        hiddenBars.forEach { $0.view?.accessibilityElementsHidden = false }
        hiddenBars = []
    }

    /// Moves VoiceOver once the handler that asked has rendered, so the
    /// element is read in its new state (with the error the handler just
    /// gave it, say), or as it is when no render comes.
    static func focus(ref: String) {
        let request = FocusRequest(ref: ref, until: Date().addingTimeInterval(focusWait))
        held = request
        DispatchQueue.main.asyncAfter(deadline: .now() + renderWait) { ScreenReader.release(request) }
    }

    /// Called on the main thread when a tree reaches SwiftUI. A turn of the
    /// run loop lets SwiftUI apply it before the request goes out.
    static func treeDidUpdate() {
        guard let request = held else { return }
        DispatchQueue.main.async { ScreenReader.release(request) }
    }

    private static func release(_ request: FocusRequest) {
        guard held == request else { return }
        held = nil
        focusRequest.send(request)
        // A request no node claimed (its `ref` never appeared) is dropped
        // once it expires, so it doesn't count as pending for ever.
        DispatchQueue.main.asyncAfter(deadline: .now() + max(0, request.until.timeIntervalSinceNow)) {
            if focusRequest.value == request { focusRequest.send(nil) }
        }
    }

    /// Whether the node with this `ref` should take focus now, clearing the
    /// request when it does.
    static func claimFocus(ref: String, request: FocusRequest?) -> Bool {
        guard let request, request.ref == ref else { return false }
        focusRequest.send(nil)
        return request.until > Date()
    }
}

/// Moves VoiceOver to this node when `Accessibility::focus()` names its
/// `ref`, either now or when the node first appears within a second of the
/// request (the handler that asked usually renders it). Does nothing while
/// VoiceOver is off, and leaves keyboard focus alone.
struct NodeAccessibilityFocusModifier: ViewModifier {
    let ref: String
    @AccessibilityFocusState private var focused: Bool

    func body(content: Content) -> some View {
        content
            .accessibilityFocused($focused)
            .onAppear {
                if ScreenReader.claimFocus(ref: ref, request: ScreenReader.focusRequest.value) { focused = true }
            }
            .onReceive(ScreenReader.focusRequest) { request in
                if ScreenReader.claimFocus(ref: ref, request: request) { focused = true }
            }
    }
}
