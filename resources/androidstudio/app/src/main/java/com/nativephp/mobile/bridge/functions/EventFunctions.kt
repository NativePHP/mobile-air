package com.nativephp.mobile.bridge.functions

import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import org.json.JSONObject

/**
 * Bridge functions for PHP events that broadcast globally (`BroadcastsGlobally`).
 * Namespace: "Event.*"
 *
 * One call, a dumb courier that never interprets what it carries:
 *   - Broadcast: a PHP context on another thread (the queue worker, the async
 *     pool, a web view lane) hands over a payload, which native writes into
 *     the element event queue under one fixed name for the UI runloop to read.
 *
 * The broadcast event itself travels in the payload, serialized and signed by
 * PHP (see `Native\Mobile\Support\GlobalEventTransport`). Native neither reads
 * nor checks it: the main PHP thread verifies the signature when the event
 * arrives, and only then unserializes it.
 *
 * A close relative of `AsyncTask.Complete`, with one difference that matters:
 * what PHP hands over goes into the element event queue and nowhere else. The
 * web event sink carries device events to PHP at `/_native/api/events`, where
 * PHP fires them. Nothing that came from PHP may go back that way.
 *
 * iOS twin: `Bridge/Functions/EventFunctions.swift`.
 */
object EventFunctions {

    /**
     * The one name every frame is written under. It must equal
     * `GlobalEventTransport::EVENT_NAME` on the PHP side, where
     * the main PHP thread picks its frames out by that name.
     */
    private const val GLOBAL_EVENT_NAME = "__global_event"

    /**
     * Write a globally broadcast event from another PHP thread into the UI
     * runloop's queue.
     * Parameters:
     *   - payload: object, written along unread (PHP sends the serialized
     *     event as `data` and its signature as `sig`)
     *
     * The name of the frame is fixed, and an `event` parameter is not read. A
     * page in a web view can call this function too, so nothing can be written
     * through it that the main PHP thread accepts without a valid signature.
     *
     * The `delivered` flag in the reply is load-bearing: true means the event
     * handed over is in the queue of a live native screen session. False means
     * it was dropped (no native screen session, or the queue is full), and PHP
     * then keeps its broadcast event in the interpreter that fired it. Nothing
     * is held here for a session that starts later. The queue belongs to the
     * session, not to one screen, so a screen that opens later in the same
     * session may still read the event.
     *
     * A missing `payload`, or one that is not an object, answers `success`
     * false and writes nothing.
     *
     * The flag is the queue write's own answer. There is no separate liveness
     * check first, so the session cannot end between a check and the write.
     *
     * The queue write is a constructor parameter so a JVM unit test can stand
     * in for the JNI call behind it.
     */
    class Broadcast(
        private val postToQueue: (event: String, payloadJson: String) -> Boolean = { event, payloadJson ->
            NativeElementBridge.postNativeEventToQueue(event, payloadJson)
        },
    ) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            // Only an object is written. jsonString() passes a string through
            // as it is and turns other types into `{}`, so the type is
            // checked here, before that helper ever sees the value.
            val payload = parameters["payload"]?.takeIf { it is JSONObject || it is Map<*, *> }
                ?: return mapOf("success" to false, "error" to "missing payload")

            // Thread-safe: the queue write takes posts from any thread, and
            // this runs on whichever PHP thread fired the event. The web
            // sink is skipped, so nothing is posted back to PHP.
            val delivered = postToQueue(GLOBAL_EVENT_NAME, jsonString(payload))
            return mapOf("success" to true, "delivered" to delivered)
        }
    }
}
