package com.nativephp.mobile.ui.nativerender

import androidx.compose.runtime.Composable
import androidx.compose.runtime.MutableState
import androidx.compose.runtime.mutableStateOf
import kotlin.math.max
import kotlin.math.min

/**
 * Per-process registry of numeric values that live on the UI thread and
 * can be driven by gestures, animations, or other UI-thread sources.
 * PHP only seeds initial values and consumes discrete callbacks — no
 * per-frame roundtrips.
 *
 * Each value is keyed by an integer ID assigned by the PHP `SharedValue`
 * class. The wire format encodes references as `__sv:{id}` strings,
 * optionally with a formula chain (`|interp:0,200:1,0|clamp:0,1`).
 *
 * Reads happen in @Composable scope so Compose tracks the dependency
 * via `mutableStateOf` and recomposes the reader at native framerate
 * when the value changes.
 */
object SharedValueStore {
    private val values = mutableMapOf<Int, MutableState<Float>>()

    fun get(id: Int): MutableState<Float> =
        values.getOrPut(id) { mutableStateOf(0f) }

    fun set(id: Int, value: Float) {
        get(id).value = value
    }

    /** Seed an initial value if no entry exists yet. */
    fun seed(id: Int, value: Float) {
        if (!values.containsKey(id)) {
            values[id] = mutableStateOf(value)
        }
    }

    fun valueOf(id: Int): Float = get(id).value

    /**
     * Evaluate a wire-encoded reference (`__sv:42|interp:0,200:1,0`)
     * against the current store. Returns null if the string isn't a
     * `__sv:` reference.
     *
     * `initial` is the value the id materializes with when the store
     * has no entry yet — callers pass the literal snapshot PHP wrote
     * alongside the binding, so a wire-fresh id (e.g. right after a
     * re-render minted a new SharedValue) renders at its initial value
     * instead of collapsing to 0. Materializing on read (rather than
     * returning a fallback) is what subscribes the caller: the entry's
     * `MutableState` must exist for a later gesture write to recompose
     * this reader.
     *
     * Must be called from @Composable scope — the underlying
     * `mutableStateOf.value` read is what subscribes the caller for
     * recomposition.
     */
    @Composable
    fun evaluate(ref: String, initial: Float = 0f): Float? {
        if (!ref.startsWith("__sv:")) return null
        val parts = ref.split("|")
        if (parts.isEmpty()) return null

        val idStr = parts[0].removePrefix("__sv:")
        val id = idStr.toIntOrNull() ?: return null

        // Read MutableState here — subscribes the calling @Composable.
        var current: Float = values.getOrPut(id) { mutableStateOf(initial) }.value

        for (step in parts.drop(1)) {
            val seg = step.split(":")
            val op = seg.firstOrNull() ?: continue
            val args = seg.drop(1)
            current = when (op) {
                "interp" -> {
                    if (args.size != 2) current
                    else {
                        val input = args[0].split(",").mapNotNull { it.toFloatOrNull() }
                        val output = args[1].split(",").mapNotNull { it.toFloatOrNull() }
                        if (input.size == 2 && output.size == 2) interp(current, input, output)
                        else current
                    }
                }
                "clamp" -> {
                    if (args.size != 1) current
                    else {
                        val bounds = args[0].split(",").mapNotNull { it.toFloatOrNull() }
                        if (bounds.size == 2) max(bounds[0], min(bounds[1], current))
                        else current
                    }
                }
                "mul" -> args.firstOrNull()?.toFloatOrNull()?.let { current * it } ?: current
                "add" -> args.firstOrNull()?.toFloatOrNull()?.let { current + it } ?: current
                else  -> current
            }
        }

        return current
    }

    private fun interp(v: Float, input: List<Float>, output: List<Float>): Float {
        val (inLow, inHigh) = input[0] to input[1]
        val (outLow, outHigh) = output[0] to output[1]
        if (v <= inLow) return outLow
        if (v >= inHigh) return outHigh
        val t = (v - inLow) / (inHigh - inLow)
        return outLow + t * (outHigh - outLow)
    }
}
