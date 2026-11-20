package com.nativephp.mobile.ui.nativerender

/**
 * Frames PHP published that the shadow thread hasn't parsed yet, oldest
 * first.
 *
 * Every frame is parsed, in order. Under subtree memo a frame's REUSE
 * markers point at nodes of the frame before it, so a frame skipped by the
 * reader leaves the next one splicing in nodes it never built: rows land
 * under the wrong parent, or old content stays.
 *
 * Each frame holds its own copy of PHP's buffers (see
 * NativeElementBridge.postTreeUpdate), so it can wait here for as long as it
 * takes. When [capacity] frames are already waiting, they are dropped
 * instead, and [take] reports it with the next frame.
 */
internal class PendingFrames<T : Any>(
    private val capacity: Int,
) {
    private val frames = ArrayDeque<T>()
    private var dropped = false

    @Synchronized
    fun add(frame: T) {
        if (frames.size >= capacity) {
            frames.clear()
            dropped = true
        }
        frames.addLast(frame)
    }

    /** The oldest waiting frame, and whether frames were dropped before it. */
    @Synchronized
    fun take(): Pair<T, Boolean>? {
        val frame = frames.removeFirstOrNull() ?: return null
        val droppedBefore = dropped
        dropped = false

        return frame to droppedBefore
    }

    @Synchronized
    fun clear() {
        frames.clear()
        dropped = false
    }
}
