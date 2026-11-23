package com.nativephp.mobile.ui.nativerender

import java.nio.ByteBuffer
import java.nio.ByteOrder
import org.junit.Assert.assertEquals
import org.junit.Assert.assertSame
import org.junit.Test

class FlatBufferReaderTest {
    private data class FlatNode(val id: Int, val children: Int = 0, val reuse: Boolean = false)

    /** A frame as the extension writes it: 161-byte nodes in DFS order, no props. */
    private fun frame(vararg nodes: FlatNode): ByteBuffer {
        val buf = ByteBuffer.allocate(nodes.size * 161).order(ByteOrder.LITTLE_ENDIAN)
        nodes.forEachIndexed { i, node ->
            buf.putInt(i * 161, node.id)
            buf.putShort(i * 161 + 6, node.children.toShort())
            buf.put(i * 161 + 160, if (node.reuse) 1 else 0)
        }
        return buf
    }

    private fun read(buf: ByteBuffer, nodeCount: Int, prevIndex: Map<Int, NativeUINode>) =
        NativeElementBridge.readTreeFromFlatBuffer(buf, null, arrayOf("column"), nodeCount, prevIndex)

    @Test
    fun aReuseMarkerSplicesTheNodeWithItsIdFromThePreviousTree() {
        val previous = read(frame(FlatNode(1, children = 1), FlatNode(2)), 2, emptyMap())!!.root

        val tree = read(
            frame(FlatNode(1, children = 2), FlatNode(2, reuse = true), FlatNode(3)),
            3,
            mapOf(1 to previous, 2 to previous.children[0]),
        )!!

        assertEquals(listOf(2, 3), tree.root.children.map { it.id })
        assertSame(previous.children[0], tree.root.children[0])
    }

    @Test(expected = MissingReuseException::class)
    fun aFrameWithAReuseMarkerTheReaderCannotResolveIsRejected() {
        // Node 2's children are a REUSE marker for a node the reader doesn't
        // have and node 3. Read past the marker, node 3 would land under the
        // root instead of under node 2.
        read(
            frame(
                FlatNode(1, children = 2),
                FlatNode(2, children = 2),
                FlatNode(99, reuse = true),
                FlatNode(3),
                FlatNode(4),
            ),
            5,
            emptyMap(),
        )
    }
}
