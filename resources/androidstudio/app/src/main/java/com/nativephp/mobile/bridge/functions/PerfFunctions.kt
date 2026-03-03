package com.nativephp.mobile.bridge.functions

import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.nativephp.mobile.ui.nativerender.PerformanceTracker

/**
 * Bridge functions for controlling the performance tracker from PHP.
 * Namespace: "Perf.*"
 *
 * Usage from PHP:
 *   nativephp_call('Perf.Enable', '{}')
 *   nativephp_call('Perf.Disable', '{}')
 *   nativephp_call('Perf.Reset', '{}')
 *   $json = nativephp_call('Perf.Export', '{}')
 *   nativephp_call('Perf.Summary', '{}')   // logs to logcat
 */
object PerfFunctions {

    class Enable : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            PerformanceTracker.enabled = true
            PerformanceTracker.logRealtime = (parameters["log"] as? Boolean) != false
            PerformanceTracker.reset()
            return mapOf("success" to true, "enabled" to true)
        }
    }

    class Disable : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            PerformanceTracker.logSummary()
            PerformanceTracker.enabled = false
            return mapOf("success" to true, "enabled" to false)
        }
    }

    class Reset : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            PerformanceTracker.reset()
            return mapOf("success" to true)
        }
    }

    class Export : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val json = PerformanceTracker.exportJson()
            return mapOf("success" to true, "data" to json)
        }
    }

    class Summary : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            PerformanceTracker.logSummary()
            return mapOf("success" to true)
        }
    }

    class StartCaptureWindow : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            PerformanceTracker.startCaptureWindow()
            return mapOf("success" to true)
        }
    }

    class StopCaptureWindow : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            PerformanceTracker.stopCaptureWindow()
            val json = PerformanceTracker.exportCaptureWindowJson()
            return mapOf("success" to true, "data" to json)
        }
    }

    /** Toggle tree diff optimization on/off for A/B benchmarking. */
    class SetDiffEnabled : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val enabled = parameters["enabled"] as? Boolean ?: true
            NativeElementBridge.diffEnabled = enabled
            return mapOf("success" to true, "diff_enabled" to enabled)
        }
    }

    /** Simulate a press event — triggers PerformanceTracker T0 and enqueues the event for PHP. */
    class SimulatePress : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val callbackId = (parameters["callback_id"] as? Number)?.toInt() ?: return mapOf("success" to false)
            val nodeId = (parameters["node_id"] as? Number)?.toInt() ?: 0
            NativeElementBridge.sendPressEvent(callbackId, nodeId)
            return mapOf("success" to true)
        }
    }

    /** Simulate a text change event. */
    class SimulateTextChange : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val callbackId = (parameters["callback_id"] as? Number)?.toInt() ?: return mapOf("success" to false)
            val nodeId = (parameters["node_id"] as? Number)?.toInt() ?: 0
            val text = parameters["text"] as? String ?: ""
            NativeElementBridge.sendTextChangeEvent(callbackId, nodeId, text)
            return mapOf("success" to true)
        }
    }

    /** Simulate a toggle change event. */
    class SimulateToggle : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val callbackId = (parameters["callback_id"] as? Number)?.toInt() ?: return mapOf("success" to false)
            val nodeId = (parameters["node_id"] as? Number)?.toInt() ?: 0
            val value = parameters["value"] as? Boolean ?: false
            NativeElementBridge.sendToggleChangeEvent(callbackId, nodeId, value)
            return mapOf("success" to true)
        }
    }
}
