package com.nativephp.mobile.ui.nativerender

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class PendingFramesTest {
    private data class Frame(val n: Int)

    private fun PendingFrames<Frame>.drain(): List<Pair<Frame, Boolean>> =
        generateSequence { take() }.toList()

    @Test
    fun everyFrameIsTakenInTheOrderItWasPublished() {
        val frames = PendingFrames<Frame>(capacity = 8)

        frames.add(Frame(1))
        frames.add(Frame(2))
        frames.add(Frame(3))

        assertEquals(listOf(1, 2, 3), frames.drain().map { it.first.n })
        assertNull(frames.take())
    }

    @Test
    fun framesOverCapacityAreDroppedAndReportedWithTheNextOne() {
        val frames = PendingFrames<Frame>(capacity = 2)

        frames.add(Frame(1))
        frames.add(Frame(2))
        frames.add(Frame(3))
        frames.add(Frame(4))

        assertEquals(listOf(Frame(3) to true, Frame(4) to false), frames.drain())
    }
}
