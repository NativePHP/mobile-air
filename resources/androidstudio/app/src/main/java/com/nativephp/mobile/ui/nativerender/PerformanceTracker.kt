package com.nativephp.mobile.ui.nativerender

import android.os.Handler
import android.os.Looper
import android.util.Log
import android.view.FrameMetrics
import android.view.Window
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Tracks end-to-end interaction latency across the full pipeline:
 *
 *   T0  Compose click handler fires (sendPressEvent)
 *   T1  postTreeUpdate() called from JNI (PHP finished processing)
 *   T2  Tree posted to main thread (mainHandler.post)
 *   T3  Compose frame actually drawn (withFrameNanos)
 *
 * Also captures per-frame Android rendering pipeline breakdown via FrameMetrics.
 *
 * Usage:
 *   PerformanceTracker.enabled = true
 *   PerformanceTracker.attachFrameMetrics(window)
 *   // ... interact with app ...
 *   val json = PerformanceTracker.exportJson()
 */
object PerformanceTracker {
    private const val TAG = "PerfTracker"

    /** Master toggle — when false, all recording is skipped (zero overhead). */
    @Volatile
    var enabled = false

    /** When true, logs each measurement to logcat in real-time. */
    @Volatile
    var logRealtime = true

    /* ── Interaction round-trip tracking ── */

    /**
     * In-flight interactions: callbackId → T0 nanoTime.
     * Set when a press event is sent, consumed when the resulting frame draws.
     */
    private val interactionStarts = ConcurrentHashMap<Int, Long>()

    /** Tracks the interaction type (press, text_change, toggle_change, etc.) per callbackId. */
    private val interactionTypes = ConcurrentHashMap<Int, String>()

    /**
     * Versioned tree update timestamps — paired to avoid races when
     * rapid interactions overlap. Each postTreeUpdate() increments the
     * version so onFrameDrawn() only uses a consistent (update, post) pair.
     */
    @Volatile
    var lastTreeUpdateNanos: Long = 0L
        private set
    @Volatile
    var lastTreePostNanos: Long = 0L
        private set
    @Volatile
    private var treeUpdateVersion: Long = 0L
    @Volatile
    private var treePostVersion: Long = 0L

    /** Completed round-trip measurements. */
    private val interactions = CopyOnWriteArrayList<InteractionMeasurement>()

    /* ── Shadow thread tracking ── */

    @Volatile var lastShadowParseNanos: Long = 0L
        private set
    @Volatile var lastShadowDiffNanos: Long = 0L
        private set

    /* ── Diff stats tracking ── */

    /** Per-frame diff measurements (time + node reuse). */
    private val diffMeasurements = CopyOnWriteArrayList<DiffMeasurement>()

    /* ── Capture window ── */

    /** When true, frame data is being captured for a specific window (e.g. scroll FPS). */
    @Volatile
    var captureWindowActive = false
        private set

    /** Frame data captured only during the capture window. */
    private val captureWindowFrames = CopyOnWriteArrayList<FrameData>()

    /* ── FrameMetrics tracking ── */

    private var frameMetricsListener: Window.OnFrameMetricsAvailableListener? = null
    private val frameData = CopyOnWriteArrayList<FrameData>()
    private val mainHandler = Handler(Looper.getMainLooper())

    /* ── Recording API ── */

    /**
     * Called from event send methods — records T0 and type for this interaction.
     */
    fun onInteractionStart(callbackId: Int, type: String = "press") {
        if (!enabled) return
        interactionStarts[callbackId] = System.nanoTime()
        interactionTypes[callbackId] = type
    }

    /**
     * Called at the top of postTreeUpdate() on the PHP/JNI thread.
     * Records when the tree update arrived from native.
     */
    fun onTreeUpdateReceived() {
        if (!enabled) return
        treeUpdateVersion++
        lastTreeUpdateNanos = System.nanoTime()
    }

    /**
     * Called inside mainHandler.post{} in postTreeUpdate().
     * Records when the Compose state was updated on the main thread.
     * Captures the current update version so onFrameDrawn can verify consistency.
     */
    fun onTreePostedToMain() {
        if (!enabled) return
        lastTreePostNanos = System.nanoTime()
        treePostVersion = treeUpdateVersion
    }

    /**
     * Called from the shadow thread after parse + diff completes.
     * Records shadow thread work time for pipeline breakdown.
     */
    fun onShadowThreadWork(parseNanos: Long, diffNanos: Long) {
        if (!enabled) return
        lastShadowParseNanos = parseNanos
        lastShadowDiffNanos = diffNanos
        if (logRealtime) {
            Log.d(TAG, String.format(
                "SHADOW parse=%.3fms diff=%.3fms total=%.3fms",
                parseNanos / 1_000_000.0, diffNanos / 1_000_000.0,
                (parseNanos + diffNanos) / 1_000_000.0
            ))
        }
    }

    /**
     * Called from NativeElementBridge after diffNode() completes.
     * Records diff time and node reuse statistics.
     */
    fun onTreeDiffed(diffTimeNanos: Long, nodesReused: Int, nodesReplaced: Int, diffEnabled: Boolean) {
        if (!enabled) return
        val total = nodesReused + nodesReplaced
        val m = DiffMeasurement(
            timestampMs = System.currentTimeMillis(),
            diffTimeMs = diffTimeNanos / 1_000_000.0,
            nodesReused = nodesReused,
            nodesReplaced = nodesReplaced,
            reuseRatio = if (total > 0) nodesReused.toDouble() / total else 0.0,
            diffEnabled = diffEnabled,
        )
        diffMeasurements.add(m)

        if (logRealtime) {
            Log.d(TAG, String.format(
                "DIFF diff=%s time=%.3fms reused=%d replaced=%d ratio=%.1f%%",
                if (diffEnabled) "ON" else "OFF",
                m.diffTimeMs, nodesReused, nodesReplaced, m.reuseRatio * 100
            ))
        }
    }

    /**
     * Called from Compose after the frame is actually drawn
     * (via LaunchedEffect + withFrameNanos).
     *
     * Completes the round-trip measurement for all in-flight interactions.
     */
    fun onFrameDrawn() {
        if (!enabled) return
        val tDraw = System.nanoTime()
        val tUpdate = lastTreeUpdateNanos
        val tPost = lastTreePostNanos
        val versionsMatch = treeUpdateVersion == treePostVersion

        if (tUpdate == 0L || tPost == 0L) return

        // Complete all in-flight interactions
        val iterator = interactionStarts.entries.iterator()
        while (iterator.hasNext()) {
            val (callbackId, tStart) = iterator.next()
            // Only count if this interaction started before the tree update
            if (tStart < tUpdate) {
                iterator.remove()
                val type = interactionTypes.remove(callbackId) ?: "press"

                // When versions match, the (tUpdate, tPost) pair is consistent
                // and we get accurate pipeline breakdown. When they don't match
                // (rapid overlapping updates), we only report total round-trip
                // and split the unknown middle evenly to avoid negative values.
                val eventDeliveryMs: Double
                val composePostMs: Double
                val framePaintMs: Double

                if (versionsMatch && tPost >= tUpdate) {
                    eventDeliveryMs = (tUpdate - tStart) / 1_000_000.0
                    composePostMs = (tPost - tUpdate) / 1_000_000.0
                    framePaintMs = (tDraw - tPost) / 1_000_000.0
                } else {
                    // Timestamps are from different updates — total is still accurate
                    eventDeliveryMs = (tUpdate - tStart) / 1_000_000.0
                    val remaining = (tDraw - tUpdate) / 1_000_000.0
                    composePostMs = remaining * 0.5
                    framePaintMs = remaining * 0.5
                }

                val m = InteractionMeasurement(
                    callbackId = callbackId,
                    interactionType = type,
                    timestampMs = System.currentTimeMillis(),
                    eventDeliveryMs = eventDeliveryMs,
                    composePostMs = composePostMs,
                    framePaintMs = framePaintMs,
                    totalRoundTripMs = (tDraw - tStart) / 1_000_000.0,
                )
                interactions.add(m)

                if (logRealtime) {
                    Log.d(TAG, String.format(
                        "INTERACTION cb=%d type=%s | event_delivery=%.2fms compose_post=%.2fms frame_paint=%.2fms | TOTAL=%.2fms%s",
                        m.callbackId, m.interactionType, m.eventDeliveryMs, m.composePostMs, m.framePaintMs, m.totalRoundTripMs,
                        if (!versionsMatch) " [approx]" else ""
                    ))
                }
            }
        }
    }

    /* ── FrameMetrics ── */

    /**
     * Attach a FrameMetrics listener to the Activity window.
     * Captures per-frame Android rendering pipeline durations.
     */
    fun attachFrameMetrics(window: Window) {
        if (frameMetricsListener != null) return

        frameMetricsListener = Window.OnFrameMetricsAvailableListener { _, metrics, _ ->
            if (!enabled) return@OnFrameMetricsAvailableListener

            val total = metrics.getMetric(FrameMetrics.TOTAL_DURATION)
            val fd = FrameData(
                timestampMs = System.currentTimeMillis(),
                totalNs = total,
                inputNs = metrics.getMetric(FrameMetrics.INPUT_HANDLING_DURATION),
                animationNs = metrics.getMetric(FrameMetrics.ANIMATION_DURATION),
                layoutNs = metrics.getMetric(FrameMetrics.LAYOUT_MEASURE_DURATION),
                drawNs = metrics.getMetric(FrameMetrics.DRAW_DURATION),
                syncNs = metrics.getMetric(FrameMetrics.SYNC_DURATION),
                commandIssueNs = metrics.getMetric(FrameMetrics.COMMAND_ISSUE_DURATION),
                swapBuffersNs = metrics.getMetric(FrameMetrics.SWAP_BUFFERS_DURATION),
            )
            frameData.add(fd)
            if (captureWindowActive) {
                captureWindowFrames.add(fd)
            }

            // Log slow frames (>16ms budget)
            if (logRealtime && total > 16_000_000) {
                Log.w(TAG, String.format(
                    "SLOW FRAME total=%.1fms layout=%.1fms draw=%.1fms sync=%.1fms",
                    fd.totalNs / 1_000_000.0, fd.layoutNs / 1_000_000.0,
                    fd.drawNs / 1_000_000.0, fd.syncNs / 1_000_000.0
                ))
            }
        }

        window.addOnFrameMetricsAvailableListener(frameMetricsListener!!, mainHandler)
        Log.d(TAG, "FrameMetrics listener attached")
    }

    fun detachFrameMetrics(window: Window) {
        frameMetricsListener?.let {
            window.removeOnFrameMetricsAvailableListener(it)
            frameMetricsListener = null
            Log.d(TAG, "FrameMetrics listener detached")
        }
    }

    /* ── Capture Window ── */

    /**
     * Start a capture window — clears window frame data and begins collecting.
     * Used for scenarios like scroll FPS where we want isolated frame data.
     */
    fun startCaptureWindow() {
        captureWindowFrames.clear()
        captureWindowActive = true
        Log.d(TAG, "Capture window started")
    }

    /**
     * Stop the capture window. Frame data remains in captureWindowFrames
     * for export via exportCaptureWindowJson().
     */
    fun stopCaptureWindow() {
        captureWindowActive = false
        Log.d(TAG, "Capture window stopped — ${captureWindowFrames.size} frames captured")
    }

    /**
     * Export capture window frame data as JSON.
     */
    fun exportCaptureWindowJson(): String {
        val root = JSONObject()
        root.put("framework", "NativePHP EDGE")
        root.put("timestamp", System.currentTimeMillis())
        root.put("frame_count", captureWindowFrames.size)

        if (captureWindowFrames.isNotEmpty()) {
            val totalMs = captureWindowFrames.map { it.totalNs / 1_000_000.0 }.sorted()
            val jankCount = totalMs.count { it > 16.0 }

            val frameStats = statsJson(totalMs)
            frameStats.put("jank_count", jankCount)
            frameStats.put("jank_rate", jankCount.toDouble() / totalMs.size)
            frameStats.put("fps", if (totalMs.average() > 0) 1000.0 / totalMs.average() else 0.0)

            frameStats.put("avg_input_ms", captureWindowFrames.map { it.inputNs / 1_000_000.0 }.average())
            frameStats.put("avg_animation_ms", captureWindowFrames.map { it.animationNs / 1_000_000.0 }.average())
            frameStats.put("avg_layout_ms", captureWindowFrames.map { it.layoutNs / 1_000_000.0 }.average())
            frameStats.put("avg_draw_ms", captureWindowFrames.map { it.drawNs / 1_000_000.0 }.average())
            frameStats.put("avg_sync_ms", captureWindowFrames.map { it.syncNs / 1_000_000.0 }.average())

            root.put("frame_times_ms", frameStats)
        }

        return root.toString(2)
    }

    /* ── Export ── */

    /**
     * Export all collected data as structured JSON for charting.
     *
     * Format matches Flutter DeviceLab / RN benchmark output style:
     * {
     *   "framework": "NativePHP EDGE",
     *   "interactions": { "average": ..., "p50": ..., "p95": ..., ... },
     *   "frame_times": { "average": ..., "p50": ..., "jank_count": ..., ... },
     *   "raw_interactions": [...],
     *   "raw_frames": [...]
     * }
     */
    fun exportJson(): String {
        val root = JSONObject()
        root.put("framework", "NativePHP EDGE")
        root.put("timestamp", System.currentTimeMillis())
        root.put("interaction_count", interactions.size)
        root.put("frame_count", frameData.size)

        // Interaction summary stats
        if (interactions.isNotEmpty()) {
            val totals = interactions.map { it.totalRoundTripMs }.sorted()
            val deliveries = interactions.map { it.eventDeliveryMs }.sorted()
            val posts = interactions.map { it.composePostMs }.sorted()
            val paints = interactions.map { it.framePaintMs }.sorted()

            root.put("interaction_latency_ms", statsJson(totals))
            root.put("event_delivery_ms", statsJson(deliveries))
            root.put("compose_post_ms", statsJson(posts))
            root.put("frame_paint_ms", statsJson(paints))

            // Group stats by interaction type
            val byType = JSONObject()
            interactions.groupBy { it.interactionType }.forEach { (type, items) ->
                val typeTotals = items.map { it.totalRoundTripMs }.sorted()
                val typeObj = statsJson(typeTotals)
                typeObj.put("event_delivery_avg", items.map { it.eventDeliveryMs }.average())
                typeObj.put("compose_post_avg", items.map { it.composePostMs }.average())
                typeObj.put("frame_paint_avg", items.map { it.framePaintMs }.average())
                byType.put(type, typeObj)
            }
            root.put("interaction_by_type", byType)

            // Interaction-level jank — accurate for automated benchmarks where
            // global FrameMetrics includes non-interaction "setup" frames.
            val paintJankCount = interactions.count { it.framePaintMs > 16.67 }
            val tripJankCount = interactions.count { it.totalRoundTripMs > 16.67 }
            root.put("interaction_jank", JSONObject().apply {
                put("paint_jank_count", paintJankCount)
                put("paint_jank_rate", paintJankCount.toDouble() / interactions.size)
                put("trip_jank_count", tripJankCount)
                put("trip_jank_rate", tripJankCount.toDouble() / interactions.size)
                put("effective_fps", if (totals.average() > 0) 1000.0 / totals.average() else 0.0)
            })
        }

        // Frame timing summary stats
        if (frameData.isNotEmpty()) {
            val totalMs = frameData.map { it.totalNs / 1_000_000.0 }.sorted()
            val jankCount = totalMs.count { it > 16.0 }

            val frameStats = statsJson(totalMs)
            frameStats.put("jank_count", jankCount)
            frameStats.put("jank_rate", jankCount.toDouble() / totalMs.size)
            frameStats.put("fps", if (totalMs.average() > 0) 1000.0 / totalMs.average() else 0.0)

            // Sub-metric averages
            frameStats.put("avg_input_ms", frameData.map { it.inputNs / 1_000_000.0 }.average())
            frameStats.put("avg_animation_ms", frameData.map { it.animationNs / 1_000_000.0 }.average())
            frameStats.put("avg_layout_ms", frameData.map { it.layoutNs / 1_000_000.0 }.average())
            frameStats.put("avg_draw_ms", frameData.map { it.drawNs / 1_000_000.0 }.average())
            frameStats.put("avg_sync_ms", frameData.map { it.syncNs / 1_000_000.0 }.average())

            root.put("frame_times_ms", frameStats)
        }

        // Diff stats
        if (diffMeasurements.isNotEmpty()) {
            val diffTimes = diffMeasurements.map { it.diffTimeMs }.sorted()
            val reuseRatios = diffMeasurements.map { it.reuseRatio }
            val diffEnabled = diffMeasurements.lastOrNull()?.diffEnabled ?: true

            val diffStats = JSONObject()
            diffStats.put("diff_enabled", diffEnabled)
            diffStats.put("frame_count", diffMeasurements.size)
            diffStats.put("diff_time_ms", statsJson(diffTimes))
            diffStats.put("avg_reuse_ratio", reuseRatios.average())
            diffStats.put("avg_nodes_reused", diffMeasurements.map { it.nodesReused.toDouble() }.average())
            diffStats.put("avg_nodes_replaced", diffMeasurements.map { it.nodesReplaced.toDouble() }.average())
            root.put("diff_stats", diffStats)
        }

        // Raw data arrays for detailed charting
        val rawInteractions = JSONArray()
        interactions.forEach { m ->
            rawInteractions.put(JSONObject().apply {
                put("callback_id", m.callbackId)
                put("type", m.interactionType)
                put("timestamp", m.timestampMs)
                put("event_delivery_ms", m.eventDeliveryMs)
                put("compose_post_ms", m.composePostMs)
                put("frame_paint_ms", m.framePaintMs)
                put("total_ms", m.totalRoundTripMs)
            })
        }
        root.put("raw_interactions", rawInteractions)

        val rawFrames = JSONArray()
        frameData.forEach { f ->
            rawFrames.put(JSONObject().apply {
                put("timestamp", f.timestampMs)
                put("total_ms", f.totalNs / 1_000_000.0)
                put("input_ms", f.inputNs / 1_000_000.0)
                put("layout_ms", f.layoutNs / 1_000_000.0)
                put("draw_ms", f.drawNs / 1_000_000.0)
                put("sync_ms", f.syncNs / 1_000_000.0)
            })
        }
        root.put("raw_frames", rawFrames)

        return root.toString(2)
    }

    /** Reset all collected data. */
    fun reset() {
        interactionStarts.clear()
        interactionTypes.clear()
        interactions.clear()
        frameData.clear()
        diffMeasurements.clear()
        captureWindowFrames.clear()
        captureWindowActive = false
        lastTreeUpdateNanos = 0L
        lastTreePostNanos = 0L
        lastShadowParseNanos = 0L
        lastShadowDiffNanos = 0L
        treeUpdateVersion = 0L
        treePostVersion = 0L
        Log.d(TAG, "Reset — all data cleared")
    }

    /** Quick summary for logcat. */
    fun logSummary() {
        if (interactions.isEmpty() && frameData.isEmpty()) {
            Log.d(TAG, "No data collected")
            return
        }

        if (interactions.isNotEmpty()) {
            val totals = interactions.map { it.totalRoundTripMs }
            Log.d(TAG, String.format(
                "INTERACTIONS: n=%d avg=%.2fms p50=%.2fms p95=%.2fms p99=%.2fms worst=%.2fms",
                totals.size,
                totals.average(),
                percentile(totals.sorted(), 50),
                percentile(totals.sorted(), 95),
                percentile(totals.sorted(), 99),
                totals.max()
            ))
        }

        if (frameData.isNotEmpty()) {
            val totalMs = frameData.map { it.totalNs / 1_000_000.0 }
            val jankCount = totalMs.count { it > 16.0 }
            Log.d(TAG, String.format(
                "FRAMES: n=%d avg=%.2fms p95=%.2fms jank=%d (%.1f%%) fps=%.1f",
                totalMs.size,
                totalMs.average(),
                percentile(totalMs.sorted(), 95),
                jankCount,
                jankCount * 100.0 / totalMs.size,
                if (totalMs.average() > 0) 1000.0 / totalMs.average() else 0.0
            ))
        }
    }

    /* ── Helpers ── */

    private fun statsJson(sorted: List<Double>): JSONObject {
        return JSONObject().apply {
            put("count", sorted.size)
            put("average", sorted.average())
            put("min", sorted.first())
            put("max", sorted.last())
            put("p50", percentile(sorted, 50))
            put("p90", percentile(sorted, 90))
            put("p95", percentile(sorted, 95))
            put("p99", percentile(sorted, 99))
        }
    }

    private fun percentile(sorted: List<Double>, p: Int): Double {
        if (sorted.isEmpty()) return 0.0
        val index = (sorted.size * p / 100.0).toInt().coerceIn(0, sorted.size - 1)
        return sorted[index]
    }
}

/** Single interaction round-trip measurement. */
data class InteractionMeasurement(
    val callbackId: Int,
    val interactionType: String = "press",
    val timestampMs: Long,
    /** Compose click → JNI postTreeUpdate (includes PHP processing) */
    val eventDeliveryMs: Double,
    /** JNI thread → main thread handler dispatch */
    val composePostMs: Double,
    /** Main thread state update → frame drawn */
    val framePaintMs: Double,
    /** End-to-end: click → frame drawn */
    val totalRoundTripMs: Double,
)

/** Per-frame diff statistics. */
data class DiffMeasurement(
    val timestampMs: Long,
    val diffTimeMs: Double,
    val nodesReused: Int,
    val nodesReplaced: Int,
    val reuseRatio: Double,
    val diffEnabled: Boolean,
)

/** Single frame's Android rendering pipeline breakdown. */
data class FrameData(
    val timestampMs: Long,
    val totalNs: Long,
    val inputNs: Long,
    val animationNs: Long,
    val layoutNs: Long,
    val drawNs: Long,
    val syncNs: Long,
    val commandIssueNs: Long,
    val swapBuffersNs: Long,
)