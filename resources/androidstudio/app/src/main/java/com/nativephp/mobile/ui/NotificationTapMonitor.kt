package com.nativephp.mobile.ui

import android.content.Intent
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.nativephp.mobile.utils.NativeLog
import org.json.JSONObject

/**
 * Tells PHP when the user opens a notification, as
 * `Native\Mobile\Events\Notification\NotificationTapped`.
 *
 * A tap opens MainActivity with the notification's intent. That is a tap on a
 * notification FCM displayed (its extras carry `google.message_id`) or on one
 * whose intent carries a `notification_url` extra, as local notifications
 * and foreground pushes do. Action buttons go to the component their plugin
 * gave them, so they never reach here.
 *
 * A tap that launches the app arrives before PHP has booted, so it is held
 * until the first screen is live ([flush]), like a cold-start deep link. A
 * tap while the app is running is sent at once.
 *
 * MainActivity calls [hold] when it is created, [handle] for every intent
 * it is created or resumed with, and [flush] once its first content is up.
 * Main thread only.
 */
object NotificationTapMonitor {
    private const val EVENT = "Native\\Mobile\\Events\\Notification\\NotificationTapped"

    /** FCM's own keys, which say how the message was sent, not what it is about. */
    private val RESERVED_KEYS = setOf("from", "collapse_key")
    private val RESERVED_PREFIXES = listOf("google.", "gcm.")

    private var pending: String? = null
    private var ready = false

    /** A new MainActivity is starting: hold taps until its first content is up. */
    fun hold() {
        ready = false
    }

    /**
     * Read a notification tap from [intent], if it is one. [recreated] is true
     * when the activity is rebuilt with an intent it has already handled: a
     * configuration change, or a launch from Recents, which replays the
     * original intent.
     */
    fun handle(intent: Intent?, recreated: Boolean) {
        if (intent == null || recreated ||
            (intent.flags and Intent.FLAG_ACTIVITY_LAUNCHED_FROM_HISTORY) != 0) {
            return
        }

        val extras = intent.extras ?: return
        val values = extras.keySet().associateWith { extras.get(it) }
        val payload = payloadFor(values) ?: return

        if (ready) {
            send(payload)
        } else {
            // Only the newest tap counts: the user opened that one last.
            pending = payload
        }
    }

    /** The first screen is live: deliver a held tap, and send later ones at once. */
    fun flush() {
        ready = true
        pending?.let(::send)
        pending = null
    }

    /**
     * The event payload for a notification's intent extras, or null when
     * they are not a notification tap.
     */
    fun payloadFor(extras: Map<String, Any?>): String? {
        val messageId = extras["google.message_id"] as? String
        if (messageId == null && (extras["notification_url"] as? String).isNullOrEmpty()) {
            return null
        }

        val data = JSONObject()
        for ((key, value) in extras) {
            if (key in RESERVED_KEYS || RESERVED_PREFIXES.any { key.startsWith(it) }) {
                continue
            }
            // FCM data and notification_url are strings. Other extras a
            // launcher or plugin adds may not be, and only strings say the
            // same thing on both platforms.
            if (value is String) {
                data.put(key, value)
            }
        }

        return JSONObject()
            .put("action", "default")
            .put("id", messageId ?: JSONObject.NULL)
            .put("data", data)
            .toString()
    }

    private fun send(payload: String) {
        NativeLog.d("NotificationTap") { "🔔 Notification tapped: $payload" }
        NativeElementBridge.sendNativeEvent(EVENT, payload)
    }
}
