package com.nativephp.mobile.ui.nativerender

import android.util.Log
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf

/**
 * Observable state holder for native UI rendering.
 *
 * No longer manages threads or shared memory — NativeElementBridge
 * handles tree updates and posts them here for the Compose renderer.
 */
class NativeUIBridge private constructor() {
    companion object {
        private const val TAG = "NativeUIBridge"

        /* ── Observable state ── */

        /** Current parsed tree — observed by the Compose renderer */
        val currentTree = mutableStateOf<NativeUITree?>(null)

        /** Whether the native UI system is active */
        val isActive = mutableStateOf(false)

        /** Screen key — incremented on navigation to drive AnimatedContent */
        val screenKey = mutableIntStateOf(0)

        /** Pending transition type (slide_forward, slide_back, crossfade) */
        val pendingTransition = mutableStateOf<String?>(null)

        /** Flag set by UI.SetTransition bridge function, consumed by element bridge */
        @Volatile
        internal var navigationPending = false

        /**
         * Called by UI.SetTransition bridge function to queue a navigation animation.
         */
        fun setNavigationPending(transition: String) {
            pendingTransition.value = transition
            navigationPending = true
        }

        /* ── Event Sending Helpers ── */
        /* All events delegate to NativeElementBridge */

        fun sendPressEvent(callbackId: Int, nodeId: Int, x: Float = 0f, y: Float = 0f) {
            NativeElementBridge.sendPressEvent(callbackId, nodeId, x, y)
        }

        fun sendLongPressEvent(callbackId: Int, nodeId: Int, x: Float = 0f, y: Float = 0f) {
            NativeElementBridge.sendLongPressEvent(callbackId, nodeId, x, y)
        }

        fun sendTextChangeEvent(callbackId: Int, nodeId: Int, text: String) {
            NativeElementBridge.sendTextChangeEvent(callbackId, nodeId, text)
        }

        fun sendToggleChangeEvent(callbackId: Int, nodeId: Int, value: Boolean) {
            NativeElementBridge.sendToggleChangeEvent(callbackId, nodeId, value)
        }

        fun sendSubmitEvent(callbackId: Int, nodeId: Int, text: String) {
            NativeElementBridge.sendSubmitEvent(callbackId, nodeId, text)
        }

        fun sendSystemBackEvent() {
            NativeElementBridge.sendSystemBackEvent()
        }

        fun sendSliderChangeEvent(callbackId: Int, nodeId: Int, value: Float) {
            NativeElementBridge.sendSliderChangeEvent(callbackId, nodeId, value)
        }

        fun sendCheckboxChangeEvent(callbackId: Int, nodeId: Int, value: Boolean) {
            NativeElementBridge.sendCheckboxChangeEvent(callbackId, nodeId, value)
        }

        fun sendRadioChangeEvent(callbackId: Int, nodeId: Int, value: String) {
            NativeElementBridge.sendRadioChangeEvent(callbackId, nodeId, value)
        }

        fun sendTabChangeEvent(callbackId: Int, nodeId: Int, index: Int) {
            NativeElementBridge.sendTabChangeEvent(callbackId, nodeId, index)
        }

        fun sendSheetDismissEvent(callbackId: Int, nodeId: Int) {
            NativeElementBridge.sendSheetDismissEvent(callbackId, nodeId)
        }

        fun sendHotReloadEvent() {
            NativeElementBridge.sendHotReloadEvent()
        }

        fun sendSelectChangeEvent(callbackId: Int, nodeId: Int, value: String) {
            NativeElementBridge.sendSelectChangeEvent(callbackId, nodeId, value)
        }

        /* ── Legacy compat stubs (called by MainActivity) ── */

        fun startWatching() {
            Log.d(TAG, "startWatching() — no-op, watcher removed")
        }

        fun stopWatching() {
            isActive.value = false
            currentTree.value = null
        }
    }
}
