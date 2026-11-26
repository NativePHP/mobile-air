package com.nativephp.mobile.ui.nativerender

import java.nio.ByteBuffer
import java.nio.ByteOrder
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Test

class NativeElementBridgeFrameTest {
    private val types = arrayOf("column", "text")

    /** One 161-byte record: id, type, child count, zeros, flags. */
    private data class Rec(val id: Int, val type: Int = 0, val children: Int = 0, val reuse: Boolean = false)

    private fun frame(vararg recs: Rec): ByteBuffer {
        val buf = ByteBuffer.allocate(recs.size * 161).order(ByteOrder.LITTLE_ENDIAN)
        for ((i, r) in recs.withIndex()) {
            val at = i * 161
            buf.putInt(at, r.id)
            buf.putShort(at + 4, r.type.toShort())
            buf.putShort(at + 6, r.children.toShort())
            buf.put(at + 160, if (r.reuse) 1 else 0)
        }
        return buf
    }

    private fun read(buf: ByteBuffer, count: Int, previous: NativeUITree?, misses: IntArray? = null) =
        NativeElementBridge.readTreeFromFlatBuffer(buf, null, types, count, previous, misses)

    @Test
    fun aMissedReuseDropsOnlyThatNode() {
        val misses = IntArray(1)
        val tree = read(
            frame(Rec(1, children = 3), Rec(2, 1), Rec(99, reuse = true), Rec(4, 1)),
            4, previous = null, misses,
        )

        assertNotNull(tree)
        assertEquals(listOf(2, 4), tree!!.root.children.map { it.id })
        assertEquals(1, misses[0])
    }

    @Test
    fun aReuseMarkerSplicesThePreviousFramesOwnNode() {
        val first = read(frame(Rec(1, children = 2), Rec(2, 1), Rec(3, 1)), 3, previous = null)!!
        val second = read(
            frame(Rec(1, children = 3), Rec(5, 1), Rec(2, reuse = true), Rec(3, reuse = true)),
            4, previous = first,
        )!!

        assertEquals(listOf(5, 2, 3), second.root.children.map { it.id })
        assertSame(first.root.children[0], second.root.children[1])
        assertSame(first.root.children[1], second.root.children[2])
    }

    @Test
    fun aRowFromAnEarlierFrameIsFoundWhenEveryFrameIsParsed() {
        // Frame A brings in row 7. Frame B reuses it. Parsing A first (as the
        // shadow thread now always does) keeps the row; skipping A loses it.
        val zero = read(frame(Rec(1, children = 1), Rec(2, 1)), 2, previous = null)!!
        val a = read(frame(Rec(1, children = 2), Rec(7, 1), Rec(2, reuse = true)), 3, previous = zero)!!
        val bBytes = { frame(Rec(1, children = 2), Rec(7, reuse = true), Rec(2, reuse = true)) }

        assertEquals(listOf(7, 2), read(bBytes(), 3, previous = a)!!.root.children.map { it.id })
        assertEquals(listOf(2), read(bBytes(), 3, previous = zero)!!.root.children.map { it.id })
    }

    @Test
    fun theQueueDrainsEveryFrameInOrder() {
        val queue = ShadowQueue<Int>()
        queue.push(1)
        queue.push(2)
        queue.push(3)

        assertEquals(listOf(1, 2, 3), queue.drain())
        assertTrue(queue.drain().isEmpty())
    }

    @Test
    fun theMainSlotPostsOnceAndKeepsANavigationItReplaces() {
        val slot = MainSlot<Settled>()
        val tree = read(frame(Rec(1)), 1, previous = null)!!
        val nav = Settled(tree, nativeChromeContinuation = false, isFreshStackMount = true, isNav = true)
        val plain = Settled(tree, nativeChromeContinuation = false, isFreshStackMount = false, isNav = false)

        assertTrue(slot.offer(nav, Settled::mergeInto))
        assertFalse(slot.offer(plain, Settled::mergeInto))

        val taken = slot.take()!!
        assertTrue(taken.isNav)
        assertTrue(taken.isFreshStackMount)
        assertNull(slot.take())
        assertTrue(slot.offer(plain, Settled::mergeInto))
    }
}
