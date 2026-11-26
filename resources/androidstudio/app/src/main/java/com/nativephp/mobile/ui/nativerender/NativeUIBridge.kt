package com.nativephp.mobile.ui.nativerender

import android.util.Log
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
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

        /** Public revision for observing every mounted tree publication, including equal trees. */
        val treePublicationId = mutableLongStateOf(0L)

        internal fun publishTree(tree: NativeUITree) {
            currentTree.value = tree
            treePublicationId.longValue++
        }

        /** Whether the native UI system is active */
        val isActive = mutableStateOf(false)

        /**
         * True between the start of a hot-reload event and the next
         * tree publish from the rebooted PHP runtime. Drives the
         * small Material 3 "Reloading…" pill in the root activity.
         * Mirrors iOS's `NativeUIBridge.isReloading`.
         */
        val isReloading = mutableStateOf(false)

        /** Screen key — incremented on navigation to drive AnimatedContent */
        val screenKey = mutableIntStateOf(0)

        /** Pending transition type (slide_forward, slide_back, crossfade) */
        val pendingTransition = mutableStateOf<String?>(null)

        /**
         * The transition PHP staged for the latest navigation publish, and a
         * counter that moves on with every one.
         *
         * `pendingTransition` is never cleared, so on its own it can't say
         * whether a change on screen came from the navigation that staged it
         * or from something later (a redirect, a local pop). Native-chrome
         * renderers compare this counter with the one they last consumed to
         * know the staged value is fresh. See ChromeMorphTracker.
         */
        val navigationSerial = mutableLongStateOf(0L)
        val navigationTransition = mutableStateOf<String?>(null)

        /** Main thread: records a navigation publish, just before its tree. */
        internal fun publishNavigation(transition: String?) {
            navigationTransition.value = transition
            navigationSerial.longValue++
        }

        /** Flag set by UI.SetTransition bridge function, consumed by element bridge */
        @Volatile
        internal var navigationPending = false

        /** Creates this object's Compose state; call on the main thread before the first composition. */
        fun createState() {}

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
