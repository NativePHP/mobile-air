import SwiftUI

/// Observable state holder for native UI rendering.
/// SwiftUI equivalent of Android's NativeUIBridge companion object.
final class NativeUIBridge: ObservableObject {
    static let shared = NativeUIBridge()

    // MARK: - Observable State

    /// Current parsed tree — observed by the SwiftUI renderer
    @Published var currentTree: NativeUITree?

    /// Whether the native UI system is active
    @Published var isActive: Bool = false

    /// Screen key — incremented on navigation to drive transitions
    @Published var screenKey: Int = 0

    /// Pending transition type
    @Published var pendingTransition: String?

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
}
