package com.nativephp.mobile.bridge.functions

import android.os.Debug
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.ui.nativerender.FrameTracker
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

    /** Toggle the live FPS / p99 / jank overlay independently of the
     *  heavier [PerformanceTracker]. Wired from PHP boot via the
     *  `nativephp.fps_overlay` config key so devs can flip it via env
     *  without touching native code. */
    class SetFpsOverlayEnabled : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val enabled = parameters["enabled"] as? Boolean ?: false
            FrameTracker.enabled = enabled
            return mapOf("success" to true, "fps_overlay_enabled" to enabled)
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

    /**
     * Current process memory (totalPss in bytes) + delta from a baseline
     * captured on first call. Pass `reset_baseline: true` to re-capture.
     * Mirrors iOS `MemoryProbe.swift`.
     */
    class Memory : BridgeFunction {
        companion object {
            // -1 = not yet set. Single-process so a plain object field is
            // fine; same lifecycle as the rest of PerformanceTracker state.
            @Volatile
            var baselineBytes: Long = -1L
        }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (parameters["reset_baseline"] as? Boolean == true) {
                baselineBytes = -1L
            }
            val info = Debug.MemoryInfo()
            Debug.getMemoryInfo(info)
            // totalPss reports kilobytes; convert to bytes for parity with iOS.
            val current = info.totalPss.toLong() * 1024L
            if (baselineBytes < 0) {
                baselineBytes = current
            }
            return mapOf(
                "success" to true,
                "resident_bytes" to current,
                "baseline_bytes" to baselineBytes,
                "delta_bytes" to (current - baselineBytes),
            )
        }
    }
}
