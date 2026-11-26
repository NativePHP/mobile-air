package com.nativephp.mobile.bridge.functions

import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.PHPQueueWorker

/**
 * Bridge function PHP calls when a job is pushed (`QueueWorkerSignal::wake()`),
 * so the queue worker boots, or polls now if it is up.
 *
 * A web view can call it too, through `/_native/api/call`. All it can do with
 * that is boot the worker, which then finds nothing and shuts down again.
 *
 * iOS twin: `Bridge/PHPQueueWorker.swift` (QueueWorkerFunctions).
 */
object QueueWorkerFunctions {
    class Wake : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            // Before the deferred start() there is no worker; the marker PHP
            // wrote is what start() will find.
            PHPQueueWorker.shared?.wake()
            return mapOf("success" to true)
        }
    }
}
