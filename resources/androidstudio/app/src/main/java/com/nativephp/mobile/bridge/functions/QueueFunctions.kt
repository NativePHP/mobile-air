package com.nativephp.mobile.bridge.functions

import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.queue.NativeQueueCoordinator

/**
 * Functions related to background queue operations
 * Namespace: "Queue.*"
 */
object QueueFunctions {
    
    /**
     * Called by PHP when jobs are dispatched to the queue.
     * Notifies the native queue coordinator to start processing.
     *
     * Parameters:
     *   - pending: (required) int - Number of pending jobs
     *   - queue: (optional) string - Queue name (default: "default")
     */
    class JobsAvailable : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val pending = (parameters["pending"] as? Number)?.toInt() ?: 0
            val queue = parameters["queue"] as? String ?: "default"
            
            // Notify the queue coordinator
            NativeQueueCoordinator.getInstance().notifyJobsAvailable(pending, queue)
            
            return mapOf("acknowledged" to true)
        }
    }
}
