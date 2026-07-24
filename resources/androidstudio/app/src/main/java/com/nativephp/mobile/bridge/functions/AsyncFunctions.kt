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

            executor.dispatch(id)
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

            val payloadJson = when (val payload = parameters["payload"]) {
                is JSONObject -> payload.toString()
                is String -> payload
                is Map<*, *> -> JSONObject(payload.entries.associate { (k, v) -> k.toString() to v }).toString()
                else -> "{}"
            }

            // Thread-safe: sendNativeEvent is the event producer and may be
            // called from any thread (here, the async pool thread).
            NativeElementBridge.sendNativeEvent(event, payloadJson)
            return mapOf("success" to true)
        }
    }
}
