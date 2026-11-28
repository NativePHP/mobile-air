package com.nativephp.mobile.bridge.functions

import android.app.NotificationManager
import android.content.Context
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.utils.NativeLog

/**
 * Notification work that needs no push plugin.
 * Namespace: "Notification.*"
 *
 * Push plugins implement `PushNotification.*`; these names are core's own, so
 * a plugin that registers its own `PushNotification.ClearBadge` keeps it.
 */
object NotificationFunctions {

    /**
     * Android has no API for a number on the app icon: launchers show a dot
     * while the app has notifications in a channel that allows badges. So
     * this checks the count and does nothing else.
     */
    class SetBadge : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val count = (parameters["count"] as? Number)?.toInt()
            if (count == null || count < 0) {
                throw BridgeError.InvalidParameters("count must be a whole number of 0 or more")
            }
            NativeLog.d("Notification.SetBadge") { "Android has no app icon count, ignoring $count" }
            return mapOf("success" to false, "supported" to false)
        }
    }

    /**
     * Cancel the shown notifications whose tag or group is `thread` and leave
     * the rest. An FCM message sets the tag with `android.notification.tag`.
     */
    class RemoveDelivered(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val thread = parameters["thread"] as? String
            if (thread.isNullOrEmpty()) {
                throw BridgeError.InvalidParameters("thread must be a non-empty string")
            }

            val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            val matching = manager.activeNotifications.filter {
                it.tag == thread || it.notification.group == thread
            }
            matching.forEach { manager.cancel(it.tag, it.id) }

            return mapOf("success" to true, "removed" to matching.size)
        }
    }
}
