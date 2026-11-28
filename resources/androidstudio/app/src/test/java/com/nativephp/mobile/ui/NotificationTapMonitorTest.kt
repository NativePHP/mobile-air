package com.nativephp.mobile.ui

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class NotificationTapMonitorTest {
    @Test
    fun anFcmTapCarriesItsMessageIdAndOnlyItsOwnData() {
        val payload = NotificationTapMonitor.payloadFor(
            mapOf(
                "google.message_id" to "0:1764300000%abc",
                "google.sent_time" to 1764300000000L,
                "gcm.n.analytics_data" to "x",
                "from" to "1234567890",
                "collapse_key" to "dev.app",
                "conversation" to "42",
                "url" to "/chats/42",
                "launcher_extra" to 7,
            )
        )!!.let(::JSONObject)

        assertEquals("default", payload.getString("action"))
        assertEquals("0:1764300000%abc", payload.getString("id"))
        val data = payload.getJSONObject("data")
        assertEquals(setOf("conversation", "url"), data.keys().asSequence().toSet())
        assertEquals("42", data.getString("conversation"))
    }

    @Test
    fun aLocalNotificationTapHasNoId() {
        val payload = JSONObject(
            NotificationTapMonitor.payloadFor(mapOf("notification_url" to "/reminders/3"))!!
        )

        assertTrue(payload.isNull("id"))
        assertEquals("/reminders/3", payload.getJSONObject("data").getString("notification_url"))
    }

    @Test
    fun otherIntentsAreNotTaps() {
        assertNull(NotificationTapMonitor.payloadFor(emptyMap()))
        assertNull(NotificationTapMonitor.payloadFor(mapOf("url" to "/somewhere")))
        assertNull(NotificationTapMonitor.payloadFor(mapOf("notification_url" to "")))
    }
}
