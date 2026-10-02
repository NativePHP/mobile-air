package com.nativephp.mobile.ui.nativerender

import androidx.compose.runtime.Stable

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
    const val HOT_RELOAD    = 15
    const val SHUTDOWN      = 16
    const val NATIVE        = 20
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
 * Parses color strings (hex and CSS named colors) to ARGB int.
 * Supports: #RGB, #RRGGBB, #AARRGGBB, and all 148 CSS named colors.
 */
object ColorParser {
    private val namedColors = mapOf(
        "aliceblue" to 0xFFF0F8FF.toInt(), "antiquewhite" to 0xFFFAEBD7.toInt(),
        "aqua" to 0xFF00FFFF.toInt(), "aquamarine" to 0xFF7FFFD4.toInt(),
        "azure" to 0xFFF0FFFF.toInt(), "beige" to 0xFFF5F5DC.toInt(),
        "bisque" to 0xFFFFE4C4.toInt(), "black" to 0xFF000000.toInt(),
        "blanchedalmond" to 0xFFFFEBCD.toInt(), "blue" to 0xFF0000FF.toInt(),
        "blueviolet" to 0xFF8A2BE2.toInt(), "brown" to 0xFFA52A2A.toInt(),
        "burlywood" to 0xFFDEB887.toInt(), "cadetblue" to 0xFF5F9EA0.toInt(),
        "chartreuse" to 0xFF7FFF00.toInt(), "chocolate" to 0xFFD2691E.toInt(),
        "coral" to 0xFFFF7F50.toInt(), "cornflowerblue" to 0xFF6495ED.toInt(),
        "cornsilk" to 0xFFFFF8DC.toInt(), "crimson" to 0xFFDC143C.toInt(),
        "cyan" to 0xFF00FFFF.toInt(), "darkblue" to 0xFF00008B.toInt(),
        "darkcyan" to 0xFF008B8B.toInt(), "darkgoldenrod" to 0xFFB8860B.toInt(),
        "darkgray" to 0xFFA9A9A9.toInt(), "darkgreen" to 0xFF006400.toInt(),
        "darkgrey" to 0xFFA9A9A9.toInt(), "darkkhaki" to 0xFFBDB76B.toInt(),
        "darkmagenta" to 0xFF8B008B.toInt(), "darkolivegreen" to 0xFF556B2F.toInt(),
        "darkorange" to 0xFFFF8C00.toInt(), "darkorchid" to 0xFF9932CC.toInt(),
        "darkred" to 0xFF8B0000.toInt(), "darksalmon" to 0xFFE9967A.toInt(),
        "darkseagreen" to 0xFF8FBC8F.toInt(), "darkslateblue" to 0xFF483D8B.toInt(),
        "darkslategray" to 0xFF2F4F4F.toInt(), "darkslategrey" to 0xFF2F4F4F.toInt(),
        "darkturquoise" to 0xFF00CED1.toInt(), "darkviolet" to 0xFF9400D3.toInt(),
        "deeppink" to 0xFFFF1493.toInt(), "deepskyblue" to 0xFF00BFFF.toInt(),
        "dimgray" to 0xFF696969.toInt(), "dimgrey" to 0xFF696969.toInt(),
        "dodgerblue" to 0xFF1E90FF.toInt(), "firebrick" to 0xFFB22222.toInt(),
        "floralwhite" to 0xFFFFFAF0.toInt(), "forestgreen" to 0xFF228B22.toInt(),
        "fuchsia" to 0xFFFF00FF.toInt(), "gainsboro" to 0xFFDCDCDC.toInt(),
        "ghostwhite" to 0xFFF8F8FF.toInt(), "gold" to 0xFFFFD700.toInt(),
        "goldenrod" to 0xFFDAA520.toInt(), "gray" to 0xFF808080.toInt(),
        "green" to 0xFF008000.toInt(), "greenyellow" to 0xFFADFF2F.toInt(),
        "grey" to 0xFF808080.toInt(), "honeydew" to 0xFFF0FFF0.toInt(),
        "hotpink" to 0xFFFF69B4.toInt(), "indianred" to 0xFFCD5C5C.toInt(),
        "indigo" to 0xFF4B0082.toInt(), "ivory" to 0xFFFFFFF0.toInt(),
        "khaki" to 0xFFF0E68C.toInt(), "lavender" to 0xFFE6E6FA.toInt(),
        "lavenderblush" to 0xFFFFF0F5.toInt(), "lawngreen" to 0xFF7CFC00.toInt(),
        "lemonchiffon" to 0xFFFFFACD.toInt(), "lightblue" to 0xFFADD8E6.toInt(),
        "lightcoral" to 0xFFF08080.toInt(), "lightcyan" to 0xFFE0FFFF.toInt(),
        "lightgoldenrodyellow" to 0xFFFAFAD2.toInt(), "lightgray" to 0xFFD3D3D3.toInt(),
        "lightgreen" to 0xFF90EE90.toInt(), "lightgrey" to 0xFFD3D3D3.toInt(),
        "lightpink" to 0xFFFFB6C1.toInt(), "lightsalmon" to 0xFFFFA07A.toInt(),
        "lightseagreen" to 0xFF20B2AA.toInt(), "lightskyblue" to 0xFF87CEFA.toInt(),
        "lightslategray" to 0xFF778899.toInt(), "lightslategrey" to 0xFF778899.toInt(),
        "lightsteelblue" to 0xFFB0C4DE.toInt(), "lightyellow" to 0xFFFFFFE0.toInt(),
        "lime" to 0xFF00FF00.toInt(), "limegreen" to 0xFF32CD32.toInt(),
        "linen" to 0xFFFAF0E6.toInt(), "magenta" to 0xFFFF00FF.toInt(),
        "maroon" to 0xFF800000.toInt(), "mediumaquamarine" to 0xFF66CDAA.toInt(),
        "mediumblue" to 0xFF0000CD.toInt(), "mediumorchid" to 0xFFBA55D3.toInt(),
        "mediumpurple" to 0xFF9370DB.toInt(), "mediumseagreen" to 0xFF3CB371.toInt(),
        "mediumslateblue" to 0xFF7B68EE.toInt(), "mediumspringgreen" to 0xFF00FA9A.toInt(),
        "mediumturquoise" to 0xFF48D1CC.toInt(), "mediumvioletred" to 0xFFC71585.toInt(),
        "midnightblue" to 0xFF191970.toInt(), "mintcream" to 0xFFF5FFFA.toInt(),
        "mistyrose" to 0xFFFFE4E1.toInt(), "moccasin" to 0xFFFFE4B5.toInt(),
        "navajowhite" to 0xFFFFDEAD.toInt(), "navy" to 0xFF000080.toInt(),
        "oldlace" to 0xFFFDF5E6.toInt(), "olive" to 0xFF808000.toInt(),
        "olivedrab" to 0xFF6B8E23.toInt(), "orange" to 0xFFFFA500.toInt(),
        "orangered" to 0xFFFF4500.toInt(), "orchid" to 0xFFDA70D6.toInt(),
        "palegoldenrod" to 0xFFEEE8AA.toInt(), "palegreen" to 0xFF98FB98.toInt(),
        "paleturquoise" to 0xFFAFEEEE.toInt(), "palevioletred" to 0xFFDB7093.toInt(),
        "papayawhip" to 0xFFFFEFD5.toInt(), "peachpuff" to 0xFFFFDAB9.toInt(),
        "peru" to 0xFFCD853F.toInt(), "pink" to 0xFFFFC0CB.toInt(),
        "plum" to 0xFFDDA0DD.toInt(), "powderblue" to 0xFFB0E0E6.toInt(),
        "purple" to 0xFF800080.toInt(), "rebeccapurple" to 0xFF663399.toInt(),
        "red" to 0xFFFF0000.toInt(), "rosybrown" to 0xFFBC8F8F.toInt(),
        "royalblue" to 0xFF4169E1.toInt(), "saddlebrown" to 0xFF8B4513.toInt(),
        "salmon" to 0xFFFA8072.toInt(), "sandybrown" to 0xFFF4A460.toInt(),
        "seagreen" to 0xFF2E8B57.toInt(), "seashell" to 0xFFFFF5EE.toInt(),
        "sienna" to 0xFFA0522D.toInt(), "silver" to 0xFFC0C0C0.toInt(),
        "skyblue" to 0xFF87CEEB.toInt(), "slateblue" to 0xFF6A5ACD.toInt(),
        "slategray" to 0xFF708090.toInt(), "slategrey" to 0xFF708090.toInt(),
        "snow" to 0xFFFFFAFA.toInt(), "springgreen" to 0xFF00FF7F.toInt(),
        "steelblue" to 0xFF4682B4.toInt(), "tan" to 0xFFD2B48C.toInt(),
        "teal" to 0xFF008080.toInt(), "thistle" to 0xFFD8BFD8.toInt(),
        "tomato" to 0xFFFF6347.toInt(), "transparent" to 0x00000000,
        "turquoise" to 0xFF40E0D0.toInt(), "violet" to 0xFFEE82EE.toInt(),
        "wheat" to 0xFFF5DEB3.toInt(), "white" to 0xFFFFFFFF.toInt(),
        "whitesmoke" to 0xFFF5F5F5.toInt(), "yellow" to 0xFFFFFF00.toInt(),
        "yellowgreen" to 0xFF9ACD32.toInt()
    )

    fun parse(value: String, default: Int = 0xFF000000.toInt()): Int {
        if (value.isBlank()) return default

        // Check named colors first
        val named = namedColors[value.lowercase()]
        if (named != null) return named

        // Hex parsing
        val cleaned = value.removePrefix("#")
        return try {
            when (cleaned.length) {
                3 -> {
                    val r = cleaned[0].digitToInt(16)
                    val g = cleaned[1].digitToInt(16)
                    val b = cleaned[2].digitToInt(16)
                    (0xFF shl 24) or (r * 17 shl 16) or (g * 17 shl 8) or (b * 17)
                }
                6 -> (0xFF000000 or cleaned.toLong(16)).toInt()
                8 -> cleaned.toLong(16).toInt()
                else -> default
            }
        } catch (_: Exception) {
            default
        }
    }
}

/**
 * Generic self-describing props container.
 * Wraps a map of key-value pairs read from the V2 wire format.
 */
class GenericProps(private val map: Map<String, Any> = emptyMap()) {

    /** Backing map — used to fold a responsive variant's prop delta over the base. */
    val raw: Map<String, Any> get() = map

    fun getString(key: String, default: String = ""): String =
        (map[key] as? String) ?: default

    fun getInt(key: String, default: Int = 0): Int =
        (map[key] as? Number)?.toInt() ?: default

    fun getFloat(key: String, default: Float = 0f): Float =
        (map[key] as? Number)?.toFloat() ?: default

    fun getBool(key: String, default: Boolean = false): Boolean =
        (map[key] as? Boolean) ?: default

    fun getColor(key: String, default: Int = 0xFF000000.toInt()): Int {
        val v = map[key] ?: return default
        if (v is Number) return v.toInt()
        if (v is String) return ColorParser.parse(v, default)
        return default
    }

    fun getCallbackId(key: String): Int =
        (map[key] as? Number)?.toInt() ?: 0

    @Suppress("UNCHECKED_CAST")
    fun getStringList(key: String): List<String> =
        (map[key] as? List<String>) ?: emptyList()

    fun has(key: String): Boolean = map.containsKey(key)

    /**
     * Copy with a single key overridden. Content transitions use this to
     * render the EXITING AnimatedContent frame with the text it was showing —
     * by transition time the node itself already carries the new text.
     */
    fun with(key: String, value: Any): GenericProps = GenericProps(map + (key to value))

    val isEmpty: Boolean get() = map.isEmpty()

    override fun equals(other: Any?): Boolean {
        if (this === other) return true
        if (other !is GenericProps) return false
        return map == other.map
    }

    override fun hashCode(): Int = map.hashCode()
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
 *
 * Identity contract — mirrors iOS's `NodeView: Equatable` with `===`. The
 * tree differ (`diffNodeWithStats`) returns the *previous* node reference
 * when a subtree is structurally identical across publishes; refs only
 * change when something in the subtree actually changed. Marking
 * `NativeUINode` `@Stable` and using reference identity for `equals` lets
 * Compose skip recomposition of `NodeView(node)` whenever the diff handed
 * back the same ref — same skip semantic SwiftUI gets on iOS.
 *
 * Structural-equality consumers (the diff itself, debugging, tests) compare
 * subordinate types directly (`old.layout == new.layout`, `old.props ==
 * new.props`, etc.), so overriding equals here doesn't break them. There
 * are no `Set<NativeUINode>` / `Map<NativeUINode, _>` users in the
 * codebase that would silently change semantics.
 */
@Stable
data class NativeUINode(
    val id: Int,
    val type: String,
    val layout: NodeLayout?,
    val style: NodeStyle?,
    val props: GenericProps,
    val onPress: Int,
    val onLongPress: Int,
    val children: List<NativeUINode>,
    /**
     * Responsive alternatives (`md:` / `lg:` classes), sorted by min width,
     * each already folded over the base — see [NodeVariant]. Empty for the
     * vast majority of nodes.
     */
    val variants: List<NodeVariant> = emptyList()
) {
    override fun equals(other: Any?): Boolean = this === other
    override fun hashCode(): Int = System.identityHashCode(this)

    /**
     * True when any DIRECT child carries variants. Containers (FlexContainer
     * and the plugin column / row renderers) read `children[i].layout` to
     * build flex modifiers before each child's own NodeView runs, so
     * [resolved] resolves one level of children too — this flag keeps that a
     * single branch for the common variant-free subtree.
     */
    @Transient val hasResponsiveChildren: Boolean = children.any { it.variants.isNotEmpty() }

    // Main-thread cache of resolved() results keyed by which variant won for
    // this node and for each responsive child, so node identity stays stable
    // across recompositions between resizes.
    @Transient private val resolvedCache = HashMap<String, NativeUINode>()

    /**
     * The node as it should render at [width] dp of window: the widest
     * variant whose min fits (or `this` when none does), with each
     * responsive DIRECT child resolved the same way. Nodes with no variants
     * anywhere in reach — the common case — return `this` after one branch.
     * Variants are cumulative (mobile-first), so the winner already carries
     * every narrower breakpoint's overrides.
     */
    fun resolved(width: Float): NativeUINode {
        if (variants.isEmpty() && !hasResponsiveChildren) return this

        val winner = winningVariant(width)
        val key = StringBuilder().append(winner)
        var resolvedChildren = children
        var childrenChanged = false
        if (hasResponsiveChildren) {
            val copy = ArrayList(children)
            children.forEachIndexed { index, child ->
                if (child.variants.isNotEmpty()) {
                    key.append(',').append(child.winningVariant(width))
                    val resolvedChild = child.resolved(width)
                    if (resolvedChild !== child) {
                        copy[index] = resolvedChild
                        childrenChanged = true
                    }
                }
            }
            if (childrenChanged) resolvedChildren = copy
        }
        if (winner < 0 && !childrenChanged) return this
        val cacheKey = key.toString()
        resolvedCache[cacheKey]?.let { return it }

        val v = if (winner >= 0) variants[winner] else null
        val node = NativeUINode(
            id, type, v?.layout ?: layout, v?.style ?: style, v?.props ?: props,
            onPress, onLongPress, resolvedChildren
        )
        resolvedCache[cacheKey] = node
        return node
    }

    /** Index of the widest variant whose min fits [width], or -1. */
    fun winningVariant(width: Float): Int {
        var winner = -1
        variants.forEachIndexed { index, v -> if (width >= v.minWidth) winner = index }
        return winner
    }
}

/**
 * One breakpoint alternative for a node, already folded over the base node
 * and every narrower breakpoint. Built once at decode time from the
 * `_variants` prop PHP emits (a JSON list of `{min, layout?, style?, props?}`
 * deltas keyed by the same wire names the packed node uses), so
 * [NativeUINode.resolved] is a comparison, not a merge.
 */
data class NodeVariant(
    val minWidth: Float,
    val layout: NodeLayout?,
    val style: NodeStyle?,
    val props: GenericProps
) {
    companion object {
        fun parse(props: GenericProps, baseLayout: NodeLayout?, baseStyle: NodeStyle?): List<NodeVariant> {
            if (!props.has("_variants")) return emptyList()
            val entries = try {
                org.json.JSONArray(props.getString("_variants"))
            } catch (_: Exception) {
                return emptyList()
            }
            val out = ArrayList<NodeVariant>(entries.length())
            var layout = baseLayout
            var style = baseStyle
            val propMap = HashMap(props.raw)
            for (i in 0 until entries.length()) {
                val entry = entries.optJSONObject(i) ?: continue
                if (!entry.has("min")) continue
                entry.optJSONObject("layout")?.let { layout = (layout ?: NodeLayout.EMPTY).applying(it) }
                entry.optJSONObject("style")?.let { style = (style ?: NodeStyle.EMPTY).applying(it) }
                entry.optJSONObject("props")?.let { delta ->
                    for (key in delta.keys()) {
                        val value = delta.get(key)
                        if (value != org.json.JSONObject.NULL) propMap[key] = value
                    }
                }
                out.add(NodeVariant(entry.getDouble("min").toFloat(), layout, style, GenericProps(HashMap(propMap))))
            }
            out.sortBy { it.minWidth }
            return out
        }
    }
}

/**
 * A copy with a PHP layout-array delta applied. Keys and value encodings
 * mirror `NativeElementCollector::buildLayoutArray`, which is also what the
 * packed node was built from: sizes are a number (fixed), `"fill"`, `"wrap"`
 * or `"N%"`; padding / margin / position are a number or a
 * `[top, right, bottom, left]` list.
 */
fun NodeLayout.applying(d: org.json.JSONObject): NodeLayout {
    fun f(key: String, current: Float): Float = if (d.has(key) && d.opt(key) is Number) (d.get(key) as Number).toFloat() else current
    fun i(key: String, current: Int): Int = if (d.has(key) && d.opt(key) is Number) (d.get(key) as Number).toInt() else current
    fun size(key: String, value: Float, mode: Int): Pair<Float, Int> {
        if (!d.has(key)) return value to mode
        val raw = d.get(key)
        if (raw is Number) return raw.toFloat() to SizeMode.FIXED
        val s = raw as? String ?: return value to mode
        return when {
            s == "fill" -> 0f to SizeMode.FILL
            s == "wrap" -> 0f to SizeMode.WRAP
            s.endsWith("%") -> (s.dropLast(1).toFloatOrNull() ?: return value to mode) to SizeMode.PERCENT
            else -> (s.toFloatOrNull() ?: return value to mode) to SizeMode.FIXED
        }
    }
    fun edges(key: String, t: Float, r: Float, b: Float, l: Float): FloatArray {
        if (!d.has(key)) return floatArrayOf(t, r, b, l)
        val raw = d.get(key)
        if (raw is Number) { val v = raw.toFloat(); return floatArrayOf(v, v, v, v) }
        val list = raw as? org.json.JSONArray ?: return floatArrayOf(t, r, b, l)
        if (list.length() != 4) return floatArrayOf(t, r, b, l)
        return FloatArray(4) { list.optDouble(it, 0.0).toFloat() }
    }

    val (w, wm) = size("width", width, widthMode)
    val (h, hm) = size("height", height, heightMode)
    val p = edges("padding", paddingTop, paddingRight, paddingBottom, paddingLeft)
    val m = edges("margin", marginTop, marginRight, marginBottom, marginLeft)
    val pos = edges("position", positionTop, positionRight, positionBottom, positionLeft)

    return copy(
        width = w, widthMode = wm, height = h, heightMode = hm,
        paddingTop = p[0], paddingRight = p[1], paddingBottom = p[2], paddingLeft = p[3],
        marginTop = m[0], marginRight = m[1], marginBottom = m[2], marginLeft = m[3],
        flexGrow = f("flex_grow", flexGrow), flexShrink = f("flex_shrink", flexShrink),
        alignSelf = i("align_self", alignSelf), alignItems = i("align_items", alignItems),
        justifyContent = i("justify_content", justifyContent), gap = f("gap", gap),
        safeArea = i("safe_area", safeArea),
        minWidth = f("min_width", minWidth), minHeight = f("min_height", minHeight),
        maxWidth = f("max_width", maxWidth), maxHeight = f("max_height", maxHeight),
        flexBasis = f("flex_basis", flexBasis),
        flexBasisMode = if (d.has("flex_basis")) SizeMode.FIXED else flexBasisMode,
        flexWrap = i("flex_wrap", flexWrap), flexDirection = i("flex_direction", flexDirection),
        positionType = i("position_type", positionType),
        positionTop = pos[0], positionRight = pos[1], positionBottom = pos[2], positionLeft = pos[3],
        display = i("display", display), overflow = i("overflow", overflow),
        alignContent = i("align_content", alignContent), direction = i("direction", direction),
        aspectRatio = f("aspect_ratio", aspectRatio), rowGap = f("row_gap", rowGap)
    )
}

/** A copy with a PHP style-array delta applied (colours arrive as hex strings). */
fun NodeStyle.applying(d: org.json.JSONObject): NodeStyle {
    fun f(key: String, current: Float): Float = if (d.has(key) && d.opt(key) is Number) (d.get(key) as Number).toFloat() else current
    fun color(key: String, current: Int): Int {
        if (!d.has(key)) return current
        return when (val raw = d.get(key)) {
            is Number -> raw.toInt()
            is String -> ColorParser.parse(raw, current)
            else -> current
        }
    }
    return copy(
        bgColor = color("bg_color", bgColor),
        borderRadius = f("border_radius", borderRadius),
        borderWidth = f("border_width", borderWidth),
        borderColor = color("border_color", borderColor),
        opacity = f("opacity", opacity),
        elevation = f("elevation", elevation)
    )
}

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
    val safeArea: Int = 0,
    // Extended layout fields (flexbox)
    val minWidth: Float = 0f,
    val minHeight: Float = 0f,
    val maxWidth: Float = 0f,
    val maxHeight: Float = 0f,
    val flexBasis: Float = 0f,
    val flexBasisMode: Int = 0,
    val flexWrap: Int = 0,
    val flexDirection: Int = 0,
    val positionType: Int = 0,
    val positionTop: Float = 0f,
    val positionRight: Float = 0f,
    val positionBottom: Float = 0f,
    val positionLeft: Float = 0f,
    val display: Int = 0,
    val overflow: Int = 0,
    val alignContent: Int = 0,
    val direction: Int = 0,
    val aspectRatio: Float = 0f,
    val rowGap: Float = 0f
) {
    companion object {
        val EMPTY = NodeLayout(
            width = 0f, widthMode = SizeMode.WRAP, height = 0f, heightMode = SizeMode.WRAP,
            paddingTop = 0f, paddingRight = 0f, paddingBottom = 0f, paddingLeft = 0f,
            marginTop = 0f, marginRight = 0f, marginBottom = 0f, marginLeft = 0f,
            flexGrow = 0f, flexShrink = 0f, alignSelf = 0, alignItems = 0, justifyContent = 0, gap = 0f
        )
    }
}

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
) {
    companion object {
        val EMPTY = NodeStyle(0, 0f, 0f, 0, 1f, 0f)
    }
}
