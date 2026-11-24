package com.nativephp.mobile.bridge.functions

import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.PHPQueueWorker

/**
 * Bridge functions for the background queue worker (`PHPQueueWorker`).
 * Namespace: "Queue.*"
 *
 * iOS twin: `Bridge/Functions/QueueFunctions.swift`.
 */
object QueueFunctions {

    /**
     * Start the background queue worker on demand (in lazy mode) or interrupt
     * its idle sleep immediately when PHP pushes a job onto the queue.
     *
     * Takes no parameters and runs no caller-supplied input; no-ops when the
     * queue worker is disabled in bundle_meta.json.
     */
    class Wake : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val woken = PHPQueueWorker.wakeShared()
            return mapOf("success" to true, "woken" to woken)
        }
    }
}
