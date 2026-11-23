import SwiftUI

/// Observable state holder for native UI rendering.
/// SwiftUI equivalent of Android's NativeUIBridge companion object.
final class NativeUIBridge: ObservableObject {
    static let shared = NativeUIBridge()

    // MARK: - Observable State

    /// Current parsed tree — observed by the SwiftUI renderer
    @Published var currentTree: NativeUITree?

    /// Whether the native UI system is active
    @Published var isActive: Bool = false {
        didSet {
            // A deep link that launched the app (cold start) can arrive
            // before native-ui takes over; replay it once the runtime that
            // handles __deeplink events is actually active.
            if isActive && !oldValue {
                DeepLinkRouter.shared.replayPendingDeepLink()
            }
        }
    }

    /// True between the start of a hot-reload event and the next tree
    /// publish from the rebooted PHP runtime. Drives the small Liquid
    /// Glass "Reloading…" pill in `ContentView`.
    @Published var isReloading: Bool = false

    /// Screen key — incremented on navigation to drive transitions
    @Published var screenKey: Int = 0

    /// Highest screen key handed out. `screenKey` goes back down when an
    /// edge swipe makes the kept backdrop the current screen again, but each
    /// new screen still gets a key no earlier screen had, so it sorts above
    /// every layer and a late key-guarded cleanup can't match it.
    private var highestScreenKey = 0

    func nextScreenKey() -> Int {
        highestScreenKey = max(highestScreenKey, screenKey) + 1
        return highestScreenKey
    }

    /// Pending transition type
    @Published var pendingTransition: String?

    /// The screen being navigated away from, kept mounted (same identity)
    /// beneath the incoming screen for the duration of the transition.
    ///
    /// This is the exit half of the two-layer swap: SwiftUI's transition
    /// system reliably animates INSERTIONS but not removals (removal
    /// transitions are captured at insertion and ignore later updates, and
    /// AnyTransition.modifier-based removals don't interpolate here —
    /// both verified frame-by-frame on-device). So the outgoing screen is
    /// never "removed" at swap time: ContentView keeps rendering it under
    /// the new screen and drives its exit (parallax drift + dim, or a
    /// static hold) with ordinary animated modifiers, then the bridge
    /// drops it ~0.6s later — invisibly, beneath the opaque new screen.
    struct OutgoingScreen {
        let tree: NativeUITree
        let key: Int
        /// The transition staged for the navigation that displaced this
        /// screen — drives the exit effect (e.g. parallax drift).
        let transition: String?
    }

    @Published var outgoingScreen: OutgoingScreen?

    /// The tabs screen beneath a stack session pushed from it, kept mounted
    /// (hidden) for as long as that session lasts, so an edge swipe on the
    /// stack's root can reveal it under the finger (`RootBackSwipe`). Only
    /// kept when PHP's router has it exactly one level below the stack's
    /// root (`stack_depth`), which is the level a back from that root lands
    /// on. When the swipe goes back, the bridge makes this view the current
    /// screen again instead of mounting a new one, so the tabs keep their
    /// state, as UIKit keeps a popped-to view controller.
    struct BackdropScreen {
        let tree: NativeUITree
        let key: Int
        let uri: String
        let depth: Int
    }

    @Published var backdropScreen: BackdropScreen?

    /// Flag set by UI.SetTransition bridge function
    var navigationPending = false

    private init() {}

    // MARK: - Navigation

    func setNavigationPending(transition: String) {
        pendingTransition = transition
        navigationPending = true
    }

    // MARK: - Event Sending (delegates to NativeElementBridge)

    static func sendPressEvent(_ callbackId: Int, nodeId: Int) {
        NativeElementBridge.sendPressEvent(callbackId, nodeId: nodeId)
    }

    static func sendLongPressEvent(_ callbackId: Int, nodeId: Int) {
        NativeElementBridge.sendLongPressEvent(callbackId, nodeId: nodeId)
    }

    static func sendTextChangeEvent(_ callbackId: Int, nodeId: Int, text: String) {
        NativeElementBridge.sendTextChangeEvent(callbackId, nodeId: nodeId, text: text)
    }

    static func sendToggleChangeEvent(_ callbackId: Int, nodeId: Int, value: Bool) {
        NativeElementBridge.sendToggleChangeEvent(callbackId, nodeId: nodeId, value: value)
    }

    static func sendSubmitEvent(_ callbackId: Int, nodeId: Int, text: String) {
        NativeElementBridge.sendSubmitEvent(callbackId, nodeId: nodeId, text: text)
    }

    static func sendSliderChangeEvent(_ callbackId: Int, nodeId: Int, value: Float) {
        NativeElementBridge.sendSliderChangeEvent(callbackId, nodeId: nodeId, value: value)
    }

    static func sendCheckboxChangeEvent(_ callbackId: Int, nodeId: Int, value: Bool) {
        NativeElementBridge.sendCheckboxChangeEvent(callbackId, nodeId: nodeId, value: value)
    }

    static func sendRadioChangeEvent(_ callbackId: Int, nodeId: Int, value: String) {
        NativeElementBridge.sendRadioChangeEvent(callbackId, nodeId: nodeId, value: value)
    }

    static func sendSelectChangeEvent(_ callbackId: Int, nodeId: Int, value: String) {
        NativeElementBridge.sendSelectChangeEvent(callbackId, nodeId: nodeId, value: value)
    }

    static func sendTabChangeEvent(_ callbackId: Int, nodeId: Int, index: Int) {
        NativeElementBridge.sendTabChangeEvent(callbackId, nodeId: nodeId, index: index)
    }

    static func sendSheetDismissEvent(_ callbackId: Int, nodeId: Int) {
        NativeElementBridge.sendSheetDismissEvent(callbackId, nodeId: nodeId)
    }

    static func sendHotReloadEvent() {
        NativeElementBridge.sendHotReloadEvent()
    }

    static func sendSystemBackEvent() {
        NativeElementBridge.sendSystemBackEvent()
    }
}
