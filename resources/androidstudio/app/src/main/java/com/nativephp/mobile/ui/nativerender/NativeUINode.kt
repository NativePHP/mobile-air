package com.nativephp.mobile.ui.nativerender

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
    const val SYSTEM_BACK   = 8
    const val SLIDER_CHANGE = 9
    const val CHECKBOX_CHANGE = 10
    const val RADIO_CHANGE  = 11
    const val SELECT_CHANGE = 12
    const val TAB_CHANGE    = 13
    const val SHEET_DISMISS = 14
}

/**
 * Value type tags for self-describing props — must match nativephp_ui.h
 */
object ValType {
    const val U8           = 0
    const val U16          = 1
    const val U32          = 2
    const val I32          = 3
    const val F32          = 4
    const val BOOL         = 5
    const val STRING       = 6
    const val COLOR        = 7
    const val CALLBACK     = 8
    const val STRING_ARRAY = 9
}

/**
 * Interned prop key lookup table — must match NPUI_KEY_* in nativephp_ui.h.
 * Index 0xFF means the full string follows in the wire format.
 */
object PropKey {
    const val FALLBACK = 0xFF

    val TABLE = arrayOf(
        "text",              //  0
        "label",             //  1
        "value",             //  2
        "color",             //  3
        "on_press",          //  4
        "on_change",         //  5
        "on_submit",         //  6
        "on_dismiss",        //  7
        "disabled",          //  8
        "placeholder",       //  9
        "font_size",         // 10
        "font_weight",       // 11
        "text_align",        // 12
        "max_lines",         // 13
        "src",               // 14
        "fit",               // 15
        "tint_color",        // 16
        "label_color",       // 17
        "keyboard",          // 18
        "secure",            // 19
        "max_length",        // 20
        "multiline",         // 21
        "horizontal",        // 22
        "shows_indicators",  // 23
        "min",               // 24
        "max",               // 25
        "step",              // 26
        "track_color",       // 27
        "size",              // 28
        "name",              // 29
        "options",           // 30
        "count",             // 31
        "text_color",        // 32
        "variant",           // 33
        "headline",          // 34
        "supporting",        // 35
        "overline",          // 36
        "leading_icon",      // 37
        "trailing_icon",     // 38
        "headline_color",    // 39
        "supporting_color",  // 40
        "selected_index",    // 41
        "icon",              // 42
        "visible",           // 43
    )
}

/**
 * Generic self-describing props container.
 * Wraps a map of key-value pairs read from the V2 wire format.
 */
class GenericProps(private val map: Map<String, Any> = emptyMap()) {

    fun getString(key: String, default: String = ""): String =
        (map[key] as? String) ?: default

    fun getInt(key: String, default: Int = 0): Int =
        (map[key] as? Number)?.toInt() ?: default

    fun getFloat(key: String, default: Float = 0f): Float =
        (map[key] as? Number)?.toFloat() ?: default

    fun getBool(key: String, default: Boolean = false): Boolean =
        (map[key] as? Boolean) ?: default

    fun getColor(key: String, default: Int = 0xFF000000.toInt()): Int =
        (map[key] as? Number)?.toInt() ?: default

    fun getCallbackId(key: String): Int =
        (map[key] as? Number)?.toInt() ?: 0

    @Suppress("UNCHECKED_CAST")
    fun getStringList(key: String): List<String> =
        (map[key] as? List<String>) ?: emptyList()

    fun has(key: String): Boolean = map.containsKey(key)

    val isEmpty: Boolean get() = map.isEmpty()
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
    val type: String,
    val layout: NodeLayout?,
    val style: NodeStyle?,
    val props: GenericProps,
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
    val gap: Float,
    val safeArea: Int = 0
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
