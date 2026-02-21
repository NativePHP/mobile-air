package com.nativephp.mobile.ui.nativerender

/**
 * Node type constants — must match nativephp_ui.h
 */
object NodeType {
    const val COLUMN     = 0
    const val ROW        = 1
    const val STACK      = 2
    const val SCROLLVIEW = 3
    const val TEXT       = 4
    const val IMAGE      = 5
    const val BUTTON     = 6
    const val TEXTINPUT  = 7
    const val TOGGLE     = 8
    const val SPACER     = 9
    const val DIVIDER    = 10
    const val CUSTOM     = 64
}

/**
 * Props type tags — must match nativephp_ui.h
 */
object PropsType {
    const val NONE       = 0
    const val TEXT       = 1
    const val IMAGE      = 2
    const val BUTTON     = 3
    const val TEXTINPUT  = 4
    const val TOGGLE     = 5
    const val SCROLLVIEW = 6
    const val CUSTOM     = 7
}

/**
 * Size modes — must match nativephp_ui.h
 */
object SizeMode {
    const val FIXED   = 0
    const val WRAP    = 1
    const val FILL    = 2
    const val PERCENT = 3
}

/**
 * Event types — must match nativephp_ui.h
 */
object EventType {
    const val PRESS         = 0
    const val LONG_PRESS    = 1
    const val TEXT_CHANGE   = 2
    const val TOGGLE_CHANGE = 3
    const val SUBMIT        = 4
    const val FOCUS         = 5
    const val BLUR          = 6
    const val SCROLL        = 7
}

/**
 * Parsed UI tree from shared memory.
 */
data class NativeUITree(
    val version: Int,
    val callbackCount: Int,
    val root: NativeUINode
)

/**
 * A single node in the UI tree.
 */
data class NativeUINode(
    val id: Int,
    val type: Int,
    val layout: NodeLayout?,
    val style: NodeStyle?,
    val props: NodeProps?,
    val onPress: Int,
    val onLongPress: Int,
    val children: List<NativeUINode>
)

/**
 * Layout properties for a node.
 */
data class NodeLayout(
    val width: Float,
    val widthMode: Int,
    val height: Float,
    val heightMode: Int,
    val paddingTop: Float,
    val paddingRight: Float,
    val paddingBottom: Float,
    val paddingLeft: Float,
    val marginTop: Float,
    val marginRight: Float,
    val marginBottom: Float,
    val marginLeft: Float,
    val flexGrow: Float,
    val flexShrink: Float,
    val alignSelf: Int,
    val alignItems: Int,
    val justifyContent: Int,
    val gap: Float
)

/**
 * Visual style properties for a node.
 */
data class NodeStyle(
    val bgColor: Int,
    val borderRadius: Float,
    val borderWidth: Float,
    val borderColor: Int,
    val opacity: Float,
    val elevation: Float
)

/**
 * Type-specific properties — dispatched by node type.
 */
sealed class NodeProps {
    data class Text(
        val text: String,
        val fontSize: Float,
        val fontWeight: Int,
        val color: Int,
        val textAlign: Int,
        val maxLines: Int
    ) : NodeProps()

    data class Image(
        val src: String,
        val fit: Int,
        val tintColor: Int
    ) : NodeProps()

    data class Button(
        val label: String,
        val onPress: Int,
        val disabled: Boolean,
        val color: Int,
        val labelColor: Int
    ) : NodeProps()

    data class TextInput(
        val value: String,
        val placeholder: String,
        val onChange: Int,
        val onSubmit: Int,
        val keyboard: Int,
        val secure: Boolean,
        val maxLength: Int,
        val multiline: Boolean
    ) : NodeProps()

    data class Toggle(
        val value: Boolean,
        val onChange: Int,
        val disabled: Boolean
    ) : NodeProps()

    data class ScrollView(
        val horizontal: Boolean,
        val showsIndicators: Boolean
    ) : NodeProps()

    data object None : NodeProps()
}