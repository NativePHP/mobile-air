package com.nativephp.mobile.bridge.functions

import android.util.Log
import com.nativephp.mobile.bridge.AsyncTaskExecutor
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import org.json.JSONObject

/**
 * Bridge functions for the async task lane (`AsyncTask::dispatch()`).
 * Namespace: "AsyncTask.*"
 *
 * Two calls, both dumb couriers — neither interprets the task's work or result:
 *   - Dispatch: the UI PHP context asks native to start a background run.
 *   - Complete: the background PHP context reports the outcome, which native
 *     relays into the UI runloop as a native event.
 *
 * The task payload itself moves via a temp file on the shared filesystem (see
 * `Native\Mobile\Support\AsyncTaskTransport`), so no payload crosses here.
 *
 * iOS twin: `Bridge/Functions/AsyncFunctions.swift`.
 */
object AsyncFunctions {

    /**
     * Start a dispatched task on the async pool.
     * Parameters:
     *   - id: string — the dispatched task id (payload already spooled to disk)
     *   - timeout: number (optional) — seconds before the task is treated as hung
     *   - timeoutEvent: string (optional) — event FQCN to post on timeout
     *   - timeoutPayload: object (optional) — event data to post on timeout
     *
     * The `success` flag in the reply is load-bearing: PHP fails the dispatch
     * (and fires `->failed()`) when it isn't true, so a task that never started
     * can't leave the UI waiting on a result that isn't coming.
     */
    class Dispatch : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String
                ?: return mapOf("success" to false, "error" to "missing id")

            val executor = AsyncTaskExecutor.shared
            if (executor == null) {
                Log.w("AsyncTask.Dispatch", "no async executor running; task $id dropped")
                return mapOf("success" to false, "error" to "executor not running")
            }

            val timeout = (parameters["timeout"] as? Number)?.toInt() ?: 0
            val timeoutEvent = parameters["timeoutEvent"] as? String
            val timeoutPayload = parameters["timeoutPayload"]?.let { jsonString(it) }

            val accepted = executor.dispatch(id, timeout, timeoutEvent, timeoutPayload)
            if (!accepted) {
                return mapOf("success" to false, "error" to "task not accepted")
            }

            return mapOf("success" to true)
        }
    }

    /**
     * Deliver a task's outcome back to the UI runloop.
     * Parameters:
     *   - event: string — the outcome event FQCN (AsyncTaskFinished / AsyncTaskFailed)
     *   - payload: object — event data (always carries `id`)
     */
    class Complete : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val event = parameters["event"] as? String
                ?: return mapOf("success" to false, "error" to "missing event")

            val payloadJson = jsonString(parameters["payload"])

            // Thread-safe: sendNativeEvent is the event producer and may be
            // called from any thread (here, the async pool thread).
            NativeElementBridge.sendNativeEvent(event, payloadJson)
            return mapOf("success" to true)
        }
    }
}

/** Re-serialize a decoded bridge parameter back to a JSON object string. */
private fun jsonString(value: Any?): String = when (value) {
    is JSONObject -> value.toString()
    is String -> value
    is Map<*, *> -> JSONObject(value.entries.associate { (k, v) -> k.toString() to v }).toString()
    else -> "{}"
}
