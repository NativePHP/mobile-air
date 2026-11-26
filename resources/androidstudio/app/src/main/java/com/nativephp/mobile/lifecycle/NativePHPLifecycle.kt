package com.nativephp.mobile.lifecycle

import android.os.Handler
import android.os.Looper
import android.util.Log
import com.nativephp.mobile.utils.NativeLog

/**
 * Singleton event bus for NativePHP lifecycle events.
 *
 * Plugins can subscribe to these events to receive callbacks when
 * important app lifecycle events occur (similar to iOS NotificationCenter).
 *
 * Available events:
 * - "didRegisterForRemoteNotifications" - FCM token received (data: ["token": String])
 * - "didFailToRegisterForRemoteNotifications" - FCM registration failed (data: ["error": String])
 * - "didReceiveRemoteNotification" - Push notification received (data: notification payload)
 * - "onCreate" - Activity created (data: empty)
 * - "onResume" - Activity resumed (data: empty)
 * - "onPause" - Activity paused (data: empty)
 * - "onDestroy" - Activity destroyed (data: empty)
 * - "onNewIntent" - Deep link received (data: ["url": String])
 * - "onPermissionResult" - Permission request result (data: ["permission": String, "granted": Boolean])
 *
 * Usage in plugins:
 * ```kotlin
 * NativePHPLifecycle.on("didRegisterForRemoteNotifications") { data ->
 *     val token = data["token"] as? String
 *     // Handle token...
 * }
 * ```
 */
object NativePHPLifecycle {
    private const val TAG = "NativePHPLifecycle"

    private val listeners = mutableMapOf<String, MutableList<(Map<String, Any>) -> Unit>>()
    private val mainHandler = Handler(Looper.getMainLooper())

    /**
     * Subscribe to a lifecycle event.
     *
     * @param event The event name to listen for
     * @param callback The callback to invoke when the event fires
     * @return A subscription ID that can be used to unsubscribe
     */
    @Synchronized
    fun on(event: String, callback: (Map<String, Any>) -> Unit): String {
        listeners.getOrPut(event) { mutableListOf() }.add(callback)
        val subscriptionId = "${event}_${System.identityHashCode(callback)}"
        NativeLog.d(TAG) { "📢 Subscribed to event: $event (id: $subscriptionId)" }
        return subscriptionId
    }

    /**
     * Unsubscribe from a lifecycle event.
     *
     * @param event The event name
     * @param callback The callback to remove
     */
    @Synchronized
    fun off(event: String, callback: (Map<String, Any>) -> Unit) {
        listeners[event]?.remove(callback)
        NativeLog.d(TAG) { "🔇 Unsubscribed from event: $event" }
    }

    /**
     * Remove all listeners for a specific event.
     *
     * @param event The event name
     */
    @Synchronized
    fun offAll(event: String) {
        listeners.remove(event)
        NativeLog.d(TAG) { "🔇 Removed all listeners for event: $event" }
    }

    /**
     * Post an event to all subscribers.
     * Callbacks are invoked on the main thread.
     *
     * @param event The event name
     * @param data The event data (default: empty map)
     */
    fun post(event: String, data: Map<String, Any> = emptyMap()) {
        NativeLog.d(TAG) { "📤 Posting event: $event with data: $data" }

        val callbacks = synchronized(this) {
            listeners[event]?.toList() ?: emptyList()
        }

        if (callbacks.isEmpty()) {
            NativeLog.d(TAG) { "📭 No listeners for event: $event" }
            return
        }

        // Ensure callbacks run on main thread
        if (Looper.myLooper() == Looper.getMainLooper()) {
            callbacks.forEach { callback ->
                try {
                    callback(data)
                } catch (e: Exception) {
                    Log.e(TAG, "❌ Error in callback for event $event", e)
                }
            }
        } else {
            mainHandler.post {
                callbacks.forEach { callback ->
                    try {
                        callback(data)
                    } catch (e: Exception) {
                        Log.e(TAG, "❌ Error in callback for event $event", e)
                    }
                }
            }
        }
    }

    /**
     * Check if there are any listeners for an event.
     *
     * @param event The event name
     * @return True if there are listeners
     */
    @Synchronized
    fun hasListeners(event: String): Boolean {
        return listeners[event]?.isNotEmpty() == true
    }

    /**
     * Clear all listeners. Useful for cleanup.
     */
    @Synchronized
    fun clear() {
        listeners.clear()
        NativeLog.d(TAG) { "🧹 Cleared all listeners" }
    }

    // Convenience constants for event names (mirrors iOS Notification.Name pattern)
    object Events {
        const val DID_REGISTER_FOR_REMOTE_NOTIFICATIONS = "didRegisterForRemoteNotifications"
        const val DID_FAIL_TO_REGISTER_FOR_REMOTE_NOTIFICATIONS = "didFailToRegisterForRemoteNotifications"
        const val DID_RECEIVE_REMOTE_NOTIFICATION = "didReceiveRemoteNotification"
        const val ON_CREATE = "onCreate"
        const val ON_RESUME = "onResume"
        const val ON_PAUSE = "onPause"
        const val ON_DESTROY = "onDestroy"
        const val ON_NEW_INTENT = "onNewIntent"
        const val ON_PERMISSION_RESULT = "onPermissionResult"
        const val ON_CONFIGURATION_CHANGED = "onConfigurationChanged"
    }
}