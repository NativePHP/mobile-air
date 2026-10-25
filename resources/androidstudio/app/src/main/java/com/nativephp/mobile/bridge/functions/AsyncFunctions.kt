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
 * JavaScript in a web view can make both calls too, through
 * `/_native/api/call`. So Dispatch takes only a UUID for an id, and posts a
 * timeout under one fixed name, and Complete answers only the async lane.
 *
 * iOS twin: `Bridge/Functions/AsyncFunctions.swift`.
 */
object AsyncFunctions {

    /**
     * The event posted when a task outruns its deadline. It must equal
     * `AsyncTaskFailed::class` on the PHP side.
     */
    const val TIMEOUT_EVENT = "Native\\Mobile\\Events\\Async\\AsyncTaskFailed"

    /**
     * The shape of the id PHP makes for a task with `Str::uuid()`: hex digits
     * in groups of 8-4-4-4-12, split by hyphens. matches() takes the whole id.
     */
    private val TASK_ID = Regex("[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}")

    fun isTaskId(id: String): Boolean = TASK_ID.matches(id)

    /**
     * Start a dispatched task on the async pool.
     * Parameters:
     *   - id: string — the dispatched task id (payload already spooled to disk)
     *   - timeout: number (optional) — seconds before the task is treated as hung
     *   - timeoutEvent: string (optional) — not read; a timeout always posts
     *     [TIMEOUT_EVENT]. Older native projects read it, so PHP sends it.
     *   - timeoutPayload: object (optional) — event data to post on timeout,
     *     posted with this task's id whatever `id` it holds
     *
     * The id ends up spliced into PHP that the async lane evaluates (see
     * `native_async_run` in php_bridge.c), so anything but a UUID is refused here.
     *
     * The `success` flag in the reply is load-bearing: PHP fails the dispatch
     * (and fires `->failed()`) when it isn't true, so a task that never started
     * can't leave the UI waiting on a result that isn't coming.
     */
    class Dispatch : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String
                ?: return mapOf("success" to false, "error" to "missing id")

            if (!isTaskId(id)) {
                return mapOf("success" to false, "error" to "invalid id")
            }

            val executor = AsyncTaskExecutor.shared
            if (executor == null) {
                Log.w("AsyncTask.Dispatch", "no async executor running; task $id dropped")
                return mapOf("success" to false, "error" to "executor not running")
            }

            val timeout = (parameters["timeout"] as? Number)?.toInt() ?: 0
            val timeoutPayload = when (val payload = parameters["timeoutPayload"]) {
                is JSONObject -> JSONObject(payload.toString())
                is Map<*, *> -> JSONObject(payload.entries.associate { (k, v) -> k.toString() to v })
                else -> null
            }?.put("id", id)?.toString()

            val accepted = executor.dispatch(id, timeout, TIMEOUT_EVENT, timeoutPayload)
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
     *
     * Only a call from a thread of the async pool is taken. The event goes to
     * the runloop of a native screen under the name the call gives, and to
     * the web view too, so a call from anywhere else, such as a page, would
     * post any event.
     */
    class Complete : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (!AsyncTaskExecutor.onLaneThread) {
                return mapOf("success" to false, "error" to "not called from the async lane")
            }

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
