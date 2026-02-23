package com.nativephp.mobile.ui.nativerender

import java.nio.ByteBuffer
import java.nio.ByteOrder

/**
 * Parses the V2 binary UI tree format written by nativephp_ui.c.
 *
 * Wire format (little-endian):
 *
 * Tree:  [4]magic [4]version [4]callback_count [node]root
 * Node:  [4]id [str]type [1]has_layout [1]has_style [1]has_props
 *        [4]on_press [4]on_long_press [layout?] [style?] [generic_props?]
 *        [2]child_count [children...]
 */
class NativeUITreeReader(data: ByteArray) {

    private val buf: ByteBuffer = ByteBuffer.wrap(data).order(ByteOrder.LITTLE_ENDIAN)

    companion object {
        private const val MAGIC_V2 = 0x4E505632  // "NPV2"
    }

    fun read(): NativeUITree? {
        if (buf.remaining() < 12) return null

        val magic = buf.int
        if (magic != MAGIC_V2) return null

        val version = buf.int
        val callbackCount = buf.int

        val root = readNode() ?: return null
        return NativeUITree(version, callbackCount, root)
    }

    private fun readNode(): NativeUINode? {
        if (buf.remaining() < 10) return null

        val id = buf.int
        val type = readString()
        val hasLayout = buf.get().toInt() != 0
        val hasStyle = buf.get().toInt() != 0
        val hasProps = buf.get().toInt() != 0
        val onPress = buf.int
        val onLongPress = buf.int

        val layout = if (hasLayout) readLayout() else null
        val style = if (hasStyle) readStyle() else null
        val props = if (hasProps) readGenericProps() else GenericProps()

        val childCount = buf.short.toInt() and 0xFFFF
        val children = ArrayList<NativeUINode>(childCount)
        for (i in 0 until childCount) {
            val child = readNode() ?: break
            children.add(child)
        }

        return NativeUINode(id, type, layout, style, props, onPress, onLongPress, children)
    }

    private fun readLayout(): NodeLayout {
        val width = buf.float
        val widthMode = buf.get().toInt() and 0xFF
        val height = buf.float
        val heightMode = buf.get().toInt() and 0xFF

        val paddingTop = buf.float
        val paddingRight = buf.float
        val paddingBottom = buf.float
        val paddingLeft = buf.float

        val marginTop = buf.float
        val marginRight = buf.float
        val marginBottom = buf.float
        val marginLeft = buf.float

        val flexGrow = buf.float
        val flexShrink = buf.float

        val alignSelf = buf.get().toInt() and 0xFF
        val alignItems = buf.get().toInt() and 0xFF
        val justifyContent = buf.get().toInt() and 0xFF

        val gap = buf.float
        val safeArea = buf.get().toInt() and 0xFF

        return NodeLayout(
            width, widthMode, height, heightMode,
            paddingTop, paddingRight, paddingBottom, paddingLeft,
            marginTop, marginRight, marginBottom, marginLeft,
            flexGrow, flexShrink,
            alignSelf, alignItems, justifyContent, gap,
            safeArea
        )
    }

    private fun readStyle(): NodeStyle {
        val bgColor = buf.int
        val borderRadius = buf.float
        val borderWidth = buf.float
        val borderColor = buf.int
        val opacity = buf.float
        val elevation = buf.float

        return NodeStyle(bgColor, borderRadius, borderWidth, borderColor, opacity, elevation)
    }

    private fun readGenericProps(): GenericProps {
        val propCount = buf.get().toInt() and 0xFF
        if (propCount == 0) return GenericProps()

        val map = LinkedHashMap<String, Any>(propCount)
        for (i in 0 until propCount) {
            val keyIndex = buf.get().toInt() and 0xFF
            val key = if (keyIndex != PropKey.FALLBACK && keyIndex < PropKey.TABLE.size) {
                PropKey.TABLE[keyIndex]
            } else {
                readString()
            }
            val typeTag = buf.get().toInt() and 0xFF

            val value: Any = when (typeTag) {
                ValType.U8 -> buf.get().toInt() and 0xFF
                ValType.U16 -> buf.short.toInt() and 0xFFFF
                ValType.U32 -> buf.int
                ValType.I32 -> buf.int
                ValType.F32 -> buf.float
                ValType.BOOL -> (buf.get().toInt() != 0)
                ValType.STRING -> readString()
                ValType.COLOR -> buf.int
                ValType.CALLBACK -> buf.int
                ValType.STRING_ARRAY -> {
                    val count = buf.short.toInt() and 0xFFFF
                    val list = ArrayList<String>(count)
                    for (j in 0 until count) {
                        list.add(readString())
                    }
                    list
                }
                else -> {
                    // Unknown type tag — skip u8
                    buf.get()
                    0
                }
            }

            map[key] = value
        }

        return GenericProps(map)
    }

    /** Read a length-prefixed string: [2]len [N]bytes */
    private fun readString(): String {
        val len = buf.short.toInt() and 0xFFFF
        if (len == 0) return ""
        val bytes = ByteArray(len)
        buf.get(bytes)
        return String(bytes, Charsets.UTF_8)
    }
}
