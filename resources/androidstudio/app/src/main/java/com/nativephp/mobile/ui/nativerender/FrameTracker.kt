package com.nativephp.mobile.ui.nativerender

import android.os.Handler
import android.os.Looper
import android.view.Choreographer
import androidx.compose.runtime.MutableState
import androidx.compose.runtime.mutableStateOf

/**
 * UI-thread frame instrumentation via `Choreographer.FrameCallback`.
 * Records the interval between display refreshes and derives FPS /
 * p99 / jank statistics over a rolling window. Parallels iOS's
 * `FrameTracker` (Swift) with the same API surface.
 *
 * This is intentionally separate from the heavier `PerformanceTracker`
 * which is wired into interaction round-trip measurement. The two have
 * different lifetimes: `FrameTracker` runs continuously (always-on
 * overlay), `PerformanceTracker` runs during scripted benchmarks.
 *
 * Stats publish at ~4Hz to drive the `PerfOverlay` composable. Reading
 * the published `MutableState<Float>` values subscribes Compose to
 * recomposition — but only at the 4Hz cadence, not per-frame, so the
 * overlay itself doesn't add render budget.
 */
object FrameTracker {
    /** Master toggle. When false, the choreographer callback unregisters
     *  and nothing is computed. Default: on. Flip off in production. */
    @Volatile
    var enabled: Boolean = true
        set(value) {
            if (field == value) return
            field = value
            if (value) start() else stop()
        }

    // Published stats — Compose reads these to drive the overlay.
    val fps: MutableState<Float> = mutableStateOf(0f)
    val p99Ms: MutableState<Float> = mutableStateOf(0f)
    val jankCount: MutableState<Int> = mutableStateOf(0)
    val frameCount: MutableState<Int> = mutableStateOf(0)

    /** Ring buffer of last N frame intervals in ms. 120 entries ≈ 2s
     *  at 60Hz, 1s at 120Hz. Long enough to smooth instantaneous noise,
     *  short enough to reflect current state. */
    private val bufferSize = 120
    private val intervals = DoubleArray(bufferSize)
    private var bufferFill = 0
    private var bufferHead = 0

    /** 16.67ms = 60Hz frame budget. Anything slower drops a frame at
     *  60Hz. ProMotion / high-refresh phones might track stricter
     *  internally, but the public "jank" stat sticks to the 60Hz
     *  threshold so the number means the same thing across devices. */
    private const val JANK_THRESHOLD_MS = 16.67

    private val mainHandler = Handler(Looper.getMainLooper())
    private var running = false
    private var lastFrameNanos: Long = 0
    private var publishRunnable: Runnable? = null

    private val frameCallback = object : Choreographer.FrameCallback {
        override fun doFrame(frameTimeNanos: Long) {
            if (!enabled) return
            if (lastFrameNanos != 0L) {
                val deltaMs = (frameTimeNanos - lastFrameNanos) / 1_000_000.0
                intervals[bufferHead] = deltaMs
                bufferHead = (bufferHead + 1) % bufferSize
                if (bufferFill < bufferSize) bufferFill++
            }
            lastFrameNanos = frameTimeNanos
            Choreographer.getInstance().postFrameCallback(this)
        }
    }

    init {
        start()
    }

    private fun start() {
        if (running) return
        running = true
        lastFrameNanos = 0
        bufferFill = 0
        bufferHead = 0

        mainHandler.post {
            Choreographer.getInstance().postFrameCallback(frameCallback)
        }

        // Publish stats at 4Hz. Recomposition rate of the overlay only
        // — not per-frame, so the overlay doesn't itself burn render
        // budget.
        publishRunnable = object : Runnable {
            override fun run() {
                publish()
                if (running) mainHandler.postDelayed(this, 250)
            }
        }.also { mainHandler.postDelayed(it, 250) }
    }

    private fun stop() {
        running = false
        mainHandler.post {
            Choreographer.getInstance().removeFrameCallback(frameCallback)
        }
        publishRunnable?.let { mainHandler.removeCallbacks(it) }
        publishRunnable = null
        bufferFill = 0
        bufferHead = 0
    }

    private fun publish() {
        if (bufferFill == 0) return

        // Snapshot the ring buffer. Don't sort in place (concurrent
        // writes from the choreographer callback would corrupt).
        val snapshot = DoubleArray(bufferFill)
        for (i in 0 until bufferFill) {
            snapshot[i] = intervals[i]
        }

        var sum = 0.0
        var jank = 0
        for (ms in snapshot) {
            sum += ms
            if (ms > JANK_THRESHOLD_MS) jank++
        }
        val avg = sum / snapshot.size
        snapshot.sort()
        val p99Index = ((snapshot.size * 0.99).toInt() - 1).coerceAtLeast(0)
        val p99 = snapshot[p99Index]

        fps.value = if (avg > 0) (1000.0 / avg).toFloat() else 0f
        p99Ms.value = p99.toFloat()
        jankCount.value = jank
        frameCount.value = snapshot.size
    }
}
