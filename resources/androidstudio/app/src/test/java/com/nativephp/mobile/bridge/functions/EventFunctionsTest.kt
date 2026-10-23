package com.nativephp.mobile.bridge.functions

import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class EventFunctionsTest {
    private val globalEvent = "__global_event"
    private val serializedEvent = "Tzo4OiJzdGRDbGFzcyI6MDp7fQ=="
    private val signature = "3b1f9c0e7a2d4e6f8091a2b3c4d5e6f70a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d"
    private val writes = mutableListOf<Pair<String, String>>()
    private var queueAccepts = true

    @Test
    fun queuedEventIsWrittenOnceAndAnswersDelivered() {
        val reply = broadcast().execute(mapOf("payload" to signedPayload()))

        assertEquals(mapOf("success" to true, "delivered" to true), reply)
        assertTheSignedEventWasWrittenOnce()
    }

    @Test
    fun eventTheWriteReportsAsDroppedAnswersNotDelivered() {
        queueAccepts = false

        val reply = broadcast().execute(mapOf("payload" to signedPayload()))

        assertEquals(mapOf("success" to true, "delivered" to false), reply)
        assertTheSignedEventWasWrittenOnce()
    }

    @Test
    fun aDroppedEventIsNotWrittenAgainOnALaterCall() {
        val broadcast = broadcast()
        queueAccepts = false
        broadcast.execute(mapOf("payload" to JSONObject().put("data", "first")))

        queueAccepts = true
        broadcast.execute(mapOf("payload" to JSONObject().put("data", "second")))

        assertEquals(
            listOf(
                globalEvent to "{\"data\":\"first\"}",
                globalEvent to "{\"data\":\"second\"}",
            ),
            writes,
        )
    }

    @Test
    fun mapPayloadIsWrittenUnchanged() {
        val reply = broadcast().execute(
            mapOf("payload" to mapOf("data" to serializedEvent, "sig" to signature)),
        )

        assertEquals(mapOf("success" to true, "delivered" to true), reply)
        assertTheSignedEventWasWrittenOnce()
    }

    // A page in a web view can call the bridge with a name of its own
    // choosing, and it must never become the name of the frame.
    @Test
    fun eventParameterIsIgnored() {
        val broadcast = broadcast()

        broadcast.execute(mapOf("event" to "__deeplink", "payload" to signedPayload()))
        broadcast.execute(
            mapOf("event" to "App\\Events\\OrderShipped", "payload" to signedPayload()),
        )

        assertEquals(listOf(globalEvent, globalEvent), writes.map { it.first })
    }

    @Test
    fun emptyObjectPayloadIsWritten() {
        val reply = broadcast().execute(mapOf("payload" to JSONObject()))

        assertEquals(mapOf("success" to true, "delivered" to true), reply)
        assertEquals(listOf(globalEvent to "{}"), writes)
    }

    @Test
    fun missingPayloadAnswersTheErrorAndWritesNothing() {
        val reply = broadcast().execute(emptyMap())

        assertEquals(mapOf("success" to false, "error" to "missing payload"), reply)
        assertTrue(writes.isEmpty())
    }

    @Test
    fun stringPayloadAnswersTheErrorAndWritesNothing() {
        val reply = broadcast().execute(
            mapOf("payload" to "{\"data\":\"$serializedEvent\",\"sig\":\"$signature\"}"),
        )

        assertEquals(mapOf("success" to false, "error" to "missing payload"), reply)
        assertTrue(writes.isEmpty())
    }

    @Test
    fun listPayloadAnswersTheErrorAndWritesNothing() {
        val reply = broadcast().execute(
            mapOf("payload" to JSONArray().put(signedPayload())),
        )

        assertEquals(mapOf("success" to false, "error" to "missing payload"), reply)
        assertTrue(writes.isEmpty())
    }

    // The payload PHP sends, as the bridge router hands it over: a nested
    // JSON object arrives as an org.json JSONObject, not as a Map.
    private fun signedPayload(): JSONObject =
        JSONObject().put("data", serializedEvent).put("sig", signature)

    // Native must not touch what PHP signed: one write, under the fixed
    // name, holding both keys with the values they came in with.
    private fun assertTheSignedEventWasWrittenOnce() {
        val (name, payloadJson) = writes.single()
        val written = JSONObject(payloadJson)

        assertEquals(globalEvent, name)
        assertEquals(2, written.length())
        assertEquals(serializedEvent, written.getString("data"))
        assertEquals(signature, written.getString("sig"))
    }

    // A fake for the JNI queue write: it records what it was handed
    // and answers with the flag the test sets (queued or dropped).
    private fun broadcast() = EventFunctions.Broadcast(
        postToQueue = { name, payloadJson ->
            writes += name to payloadJson
            queueAccepts
        },
    )
}
