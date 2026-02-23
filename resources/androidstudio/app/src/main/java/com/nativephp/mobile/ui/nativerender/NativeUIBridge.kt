package com.nativephp.mobile.ui.nativerender

import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.compose.runtime.mutableStateOf
import java.nio.ByteBuffer
import java.nio.ByteOrder

/**
 * JNI bridge to the shared memory region created by nativephp_ui.c.
 *
 * Provides:
 *  - Tree watching: background thread that blocks on condvar until PHP publishes a new tree
 *  - Event writing: sends UI events (press, text change, etc.) back to PHP
 *  - Compose state: exposes the current tree as observable state for the renderer
 */
class NativeUIBridge private constructor() {
    companion object {
        private const val TAG = "NativeUIBridge"

        /* ── JNI native methods (registered by bridge_jni.cpp) ── */

        @JvmStatic external fun nativeIsUIReady(): Boolean
        @JvmStatic external fun nativeGetTreeVersion(): Int
        @JvmStatic external fun nativeGetTreeBuffer(): ByteArray?
        @JvmStatic external fun nativeWaitTreeUpdate(currentVersion: Int, timeoutMs: Int): Int
        @JvmStatic external fun nativeWriteEvent(type: Int, callbackId: Int, nodeId: Int, data: ByteArray?)

        /* ── Observable state ── */

        /** Current parsed tree — observed by the Compose renderer */
        val currentTree = mutableStateOf<NativeUITree?>(null)

        /** Whether the native UI system is active */
        val isActive = mutableStateOf(false)

        private var watcherThread: Thread? = null
        private val mainHandler = Handler(Looper.getMainLooper())

        /**
         * Start watching for tree updates from PHP.
         * Spawns a background thread that blocks on the condvar until a new tree is published.
         */
        fun startWatching() {
            if (watcherThread?.isAlive == true) {
                Log.d(TAG, "Watcher already running")
                return
            }

            Log.i(TAG, "startWatching() called — spawning watcher thread")

            watcherThread = Thread({
                try {
                    Log.i(TAG, "Tree watcher started")
                    var lastVersion = 0
                    var pollCount = 0

                    // Wait for UI region to be initialized by PHP
                    while (!Thread.currentThread().isInterrupted) {
                        if (nativeIsUIReady()) break
                        pollCount++
                        if (pollCount % 200 == 0) {
                            Log.d(TAG, "Still waiting for UI region... (poll #$pollCount)")
                        }
                        try { Thread.sleep(50) } catch (_: InterruptedException) { break }
                    }

                    if (Thread.currentThread().isInterrupted || !nativeIsUIReady()) {
                        Log.i(TAG, "Tree watcher interrupted before UI ready (polls=$pollCount)")
                        return@Thread
                    }

                    Log.i(TAG, "UI region detected after $pollCount polls — watching for tree updates")
                    mainHandler.post { isActive.value = true }

                    while (!Thread.currentThread().isInterrupted) {
                        // Block until tree version changes (100ms timeout for shutdown check)
                        val newVersion = nativeWaitTreeUpdate(lastVersion, 100)

                        when {
                            newVersion == -1 -> {
                                // Shutdown signaled
                                Log.i(TAG, "Shutdown signaled")
                                break
                            }
                            newVersion == 0 -> {
                                // Timeout — check if region is still valid
                                if (!nativeIsUIReady()) {
                                    Log.i(TAG, "UI region gone — stopping watcher")
                                    break
                                }
                                continue
                            }
                            newVersion != lastVersion -> {
                                lastVersion = newVersion
                                val buffer = nativeGetTreeBuffer()
                                if (buffer != null) {
                                    val tree = NativeUITreeReader(buffer).read()
                                    if (tree != null) {
                                        Log.d(TAG, "Tree v$newVersion parsed: ${countNodes(tree.root)} nodes")
                                        mainHandler.post { currentTree.value = tree }
                                    } else {
                                        Log.w(TAG, "Failed to parse tree v$newVersion")
                                    }
                                }
                            }
                        }
                    }

                    mainHandler.post {
                        isActive.value = false
                        currentTree.value = null
                    }
                    Log.i(TAG, "Tree watcher stopped")
                } catch (e: UnsatisfiedLinkError) {
                    Log.e(TAG, "JNI method not linked: ${e.message}", e)
                } catch (e: Throwable) {
                    Log.e(TAG, "Watcher thread crashed: ${e.message}", e)
                }
            }, "npui-tree-watcher").apply {
                isDaemon = true
                start()
            }
        }

        /**
         * Stop the tree watcher thread.
         */
        fun stopWatching() {
            val thread = watcherThread
            watcherThread = null
            thread?.interrupt()
            try {
                thread?.join(500)
            } catch (_: InterruptedException) { }
            isActive.value = false
            currentTree.value = null
        }

        /* ── Event Sending Helpers ── */

        fun sendPressEvent(callbackId: Int, nodeId: Int, x: Float = 0f, y: Float = 0f) {
            val buf = ByteBuffer.allocate(8).order(ByteOrder.LITTLE_ENDIAN)
            buf.putFloat(x)
            buf.putFloat(y)
            nativeWriteEvent(EventType.PRESS, callbackId, nodeId, buf.array())
        }

        fun sendLongPressEvent(callbackId: Int, nodeId: Int, x: Float = 0f, y: Float = 0f) {
            val buf = ByteBuffer.allocate(8).order(ByteOrder.LITTLE_ENDIAN)
            buf.putFloat(x)
            buf.putFloat(y)
            nativeWriteEvent(EventType.LONG_PRESS, callbackId, nodeId, buf.array())
        }

        fun sendTextChangeEvent(callbackId: Int, nodeId: Int, text: String) {
            val textBytes = text.toByteArray(Charsets.UTF_8)
            val buf = ByteBuffer.allocate(2 + textBytes.size).order(ByteOrder.LITTLE_ENDIAN)
            buf.putShort(textBytes.size.toShort())
            buf.put(textBytes)
            nativeWriteEvent(EventType.TEXT_CHANGE, callbackId, nodeId, buf.array())
        }

        fun sendToggleChangeEvent(callbackId: Int, nodeId: Int, value: Boolean) {
            val buf = ByteBuffer.allocate(1).order(ByteOrder.LITTLE_ENDIAN)
            buf.put(if (value) 1.toByte() else 0.toByte())
            nativeWriteEvent(EventType.TOGGLE_CHANGE, callbackId, nodeId, buf.array())
        }

        fun sendSubmitEvent(callbackId: Int, nodeId: Int, text: String) {
            val textBytes = text.toByteArray(Charsets.UTF_8)
            val buf = ByteBuffer.allocate(2 + textBytes.size).order(ByteOrder.LITTLE_ENDIAN)
            buf.putShort(textBytes.size.toShort())
            buf.put(textBytes)
            nativeWriteEvent(EventType.SUBMIT, callbackId, nodeId, buf.array())
        }

        fun sendSystemBackEvent() {
            nativeWriteEvent(EventType.SYSTEM_BACK, 0, 0, null)
        }

        fun sendSliderChangeEvent(callbackId: Int, nodeId: Int, value: Float) {
            val buf = ByteBuffer.allocate(4).order(ByteOrder.LITTLE_ENDIAN)
            buf.putFloat(value)
            nativeWriteEvent(EventType.SLIDER_CHANGE, callbackId, nodeId, buf.array())
        }

        fun sendCheckboxChangeEvent(callbackId: Int, nodeId: Int, value: Boolean) {
            val buf = ByteBuffer.allocate(1).order(ByteOrder.LITTLE_ENDIAN)
            buf.put(if (value) 1.toByte() else 0.toByte())
            nativeWriteEvent(EventType.CHECKBOX_CHANGE, callbackId, nodeId, buf.array())
        }

        fun sendRadioChangeEvent(callbackId: Int, nodeId: Int, value: String) {
            val textBytes = value.toByteArray(Charsets.UTF_8)
            val buf = ByteBuffer.allocate(2 + textBytes.size).order(ByteOrder.LITTLE_ENDIAN)
            buf.putShort(textBytes.size.toShort())
            buf.put(textBytes)
            nativeWriteEvent(EventType.RADIO_CHANGE, callbackId, nodeId, buf.array())
        }

        fun sendTabChangeEvent(callbackId: Int, nodeId: Int, index: Int) {
            val buf = ByteBuffer.allocate(2).order(ByteOrder.LITTLE_ENDIAN)
            buf.putShort(index.toShort())
            nativeWriteEvent(EventType.TAB_CHANGE, callbackId, nodeId, buf.array())
        }

        fun sendSheetDismissEvent(callbackId: Int, nodeId: Int) {
            nativeWriteEvent(EventType.SHEET_DISMISS, callbackId, nodeId, null)
        }

        fun sendSelectChangeEvent(callbackId: Int, nodeId: Int, value: String) {
            val textBytes = value.toByteArray(Charsets.UTF_8)
            val buf = ByteBuffer.allocate(2 + textBytes.size).order(ByteOrder.LITTLE_ENDIAN)
            buf.putShort(textBytes.size.toShort())
            buf.put(textBytes)
            nativeWriteEvent(EventType.SELECT_CHANGE, callbackId, nodeId, buf.array())
        }

        /* ── Utilities ── */

        private fun countNodes(node: NativeUINode): Int {
            return 1 + node.children.sumOf { countNodes(it) }
        }
    }
}