package com.nativephp.mobile.ui.nativerender

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class PendingFramesTest {
    private data class Frame(val n: Int, val detached: Boolean = false)

    private fun PendingFrames<Frame>.drain(): List<Pair<Frame, Boolean>> =
        generateSequence { take() }.toList()

    @Test
    fun everyFrameIsTakenInTheOrderItWasPublished() {
        val frames = PendingFrames<Frame>(capacity = 8) { it.copy(detached = true) }

        frames.add(Frame(1))
        frames.add(Frame(2))
        frames.add(Frame(3))

        assertEquals(listOf(1, 2, 3), frames.drain().map { it.first.n })
        assertNull(frames.take())
    }

    @Test
    fun aQueuedFrameIsDetachedWhenTheNextOneArrives() {
        val frames = PendingFrames<Frame>(capacity = 8) { it.copy(detached = true) }

        frames.add(Frame(1))
        frames.add(Frame(2))

        // The publish after next writes over frame 1's buffer, frame 2's is
        // still the producer's to keep.
        assertEquals(listOf(true, false), frames.drain().map { it.first.detached })
    }

    @Test
    fun framesOverCapacityAreDroppedAndReportedWithTheNextOne() {
        val frames = PendingFrames<Frame>(capacity = 2) { it }

        frames.add(Frame(1))
        frames.add(Frame(2))
        frames.add(Frame(3))
        frames.add(Frame(4))

        assertEquals(listOf(Frame(3) to true, Frame(4) to false), frames.drain())
    }
}
