package com.nativephp.mobile.bridge.functions

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * A page in a web view can call both async functions through
 * `/_native/api/call`, so neither may take what it is sent on trust.
 */
class AsyncFunctionsTest {
    private val uuid = "0b5d6a8e-3c1f-4e2a-9b7d-1f2e3d4c5b6a"

    @Test
    fun aUuidIsATaskId() {
        assertTrue(AsyncFunctions.isTaskId(uuid))
        assertTrue(AsyncFunctions.isTaskId(uuid.uppercase()))
    }

    @Test
    fun nothingElseIsATaskId() {
        listOf(
            "",
            "it's",
            "$uuid'",
            "$uuid\n",
            " $uuid",
            uuid.dropLast(1),
            uuid.replace('-', '+'),
            uuid.replace("-", ""),
            uuid.dropLast(1) + "g",
            "1-1-1-1-1",
        ).forEach { assertFalse("\"$it\" passed as a task id", AsyncFunctions.isTaskId(it)) }
    }

    @Test
    fun dispatchRefusesAnIdThatIsNotAUuid() {
        val reply = AsyncFunctions.Dispatch().execute(mapOf("id" to "it's"))

        assertEquals(mapOf("success" to false, "error" to "invalid id"), reply)
    }

    @Test
    fun completeRefusesACallFromOutsideTheAsyncLane() {
        // The test runs on a thread the async pool never started. Posting
        // would reach JNI and throw here, so a reply means nothing was posted.
        val reply = AsyncFunctions.Complete().execute(
            mapOf("event" to "__deeplink", "payload" to mapOf("uri" to "/anywhere")),
        )

        assertEquals(mapOf("success" to false, "error" to "not called from the async lane"), reply)
    }
}
