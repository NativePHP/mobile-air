package com.nativephp.mobile.ui.nativerender

import java.nio.ByteBuffer
import java.nio.ByteOrder

/**
 * Parses the binary UI tree format written by nativephp_ui.c.
 *
 * Wire format (little-endian):
 *
 * Tree:  [4]magic [4]version [4]callback_count [node]root
 * Node:  [4]id [1]type [1]has_layout [1]has_style [1]has_props
 *        [4]on_press [4]on_long_press [layout?] [style?] [props?]
 *        [2]child_count [children...]
 */
class NativeUITreeReader(data: ByteArray) {

    private val buf: ByteBuffer = ByteBuffer.wrap(data).order(ByteOrder.LITTLE_ENDIAN)

    companion object {
        private const val MAGIC = 0x4E505549  // "NPUI"
    }

    fun read(): NativeUITree? {
        if (buf.remaining() < 12) return null

        val magic = buf.int
        if (magic != MAGIC) return null

        val version = buf.int
        val callbackCount = buf.int

        val root = readNode() ?: return null
        return NativeUITree(version, callbackCount, root)
    }

    private fun readNode(): NativeUINode? {
        if (buf.remaining() < 16) return null

        val id = buf.int
        val type = buf.get().toInt() and 0xFF
        val hasLayout = buf.get().toInt() != 0
        val hasStyle = buf.get().toInt() != 0
        val hasProps = buf.get().toInt() != 0
        val onPress = buf.int
        val onLongPress = buf.int

        val layout = if (hasLayout) readLayout() else null
        val style = if (hasStyle) readStyle() else null
        val props = if (hasProps) readProps(type) else null

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

        return NodeLayout(
            width, widthMode, height, heightMode,
            paddingTop, paddingRight, paddingBottom, paddingLeft,
            marginTop, marginRight, marginBottom, marginLeft,
            flexGrow, flexShrink,
            alignSelf, alignItems, justifyContent, gap
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

    private fun readProps(nodeType: Int): NodeProps {
        val propsTag = buf.get().toInt() and 0xFF

        return when (propsTag) {
            PropsType.TEXT -> readTextProps()
            PropsType.IMAGE -> readImageProps()
            PropsType.BUTTON -> readButtonProps()
            PropsType.TEXTINPUT -> readTextInputProps()
            PropsType.TOGGLE -> readToggleProps()
            PropsType.SCROLLVIEW -> readScrollViewProps()
            else -> NodeProps.None
        }
    }

    private fun readTextProps(): NodeProps.Text {
        val text = readString()
        val fontSize = buf.float
        val fontWeight = buf.get().toInt() and 0xFF
        val color = buf.int
        val textAlign = buf.get().toInt() and 0xFF
        val maxLines = buf.short.toInt() and 0xFFFF

        return NodeProps.Text(text, fontSize, fontWeight, color, textAlign, maxLines)
    }

    private fun readImageProps(): NodeProps.Image {
        val src = readString()
        val fit = buf.get().toInt() and 0xFF
        val tintColor = buf.int

        return NodeProps.Image(src, fit, tintColor)
    }

    private fun readButtonProps(): NodeProps.Button {
        val label = readString()
        val onPress = buf.int
        val disabled = buf.get().toInt() != 0
        val color = buf.int
        val labelColor = buf.int

        return NodeProps.Button(label, onPress, disabled, color, labelColor)
    }

    private fun readTextInputProps(): NodeProps.TextInput {
        val value = readString()
        val placeholder = readString()
        val onChange = buf.int
        val onSubmit = buf.int
        val keyboard = buf.get().toInt() and 0xFF
        val secure = buf.get().toInt() != 0
        val maxLength = buf.short.toInt() and 0xFFFF
        val multiline = buf.get().toInt() != 0

        return NodeProps.TextInput(value, placeholder, onChange, onSubmit, keyboard, secure, maxLength, multiline)
    }

    private fun readToggleProps(): NodeProps.Toggle {
        val value = buf.get().toInt() != 0
        val onChange = buf.int
        val disabled = buf.get().toInt() != 0

        return NodeProps.Toggle(value, onChange, disabled)
    }

    private fun readScrollViewProps(): NodeProps.ScrollView {
        val horizontal = buf.get().toInt() != 0
        val showsIndicators = buf.get().toInt() != 0

        return NodeProps.ScrollView(horizontal, showsIndicators)
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