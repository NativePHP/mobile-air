package com.nativephp.mobile.ui.nativerender

import java.nio.ByteBuffer
import java.nio.ByteOrder

/**
 * Patch operation types — must match nativephp_ui.h NPUI_PATCH_* constants.
 */
object PatchOpType {
    const val UPDATE_PROPS     = 1
    const val UPDATE_LAYOUT    = 2
    const val UPDATE_STYLE     = 3
    const val UPDATE_CALLBACKS = 4
}

/**
 * A single patch operation to apply to an existing tree.
 */
data class PatchOp(
    val type: Int,
    val nodeId: Int,
    val props: GenericProps? = null,
    val layout: NodeLayout? = null,
    val style: NodeStyle? = null,
    val onPress: Int? = null,
    val onLongPress: Int? = null,
)

/**
 * Parses the binary patch format (NPP1) written by nativephp_ui_patch().
 *
 * Wire format (little-endian):
 *   [4] magic (0x4E505031 "NPP1")
 *   [4] patch_version
 *   [2] operation_count
 *   per operation:
 *     [1] op_type
 *     [4] node_id
 *     [N] data (format depends on op_type)
 */
class NativeUIPatchReader(data: ByteArray) {

    private val buf: ByteBuffer = ByteBuffer.wrap(data).order(ByteOrder.LITTLE_ENDIAN)

    companion object {
        private const val PATCH_MAGIC = 0x4E505031  // "NPP1"
    }

    fun read(): List<PatchOp>? {
        if (buf.remaining() < 10) return null

        val magic = buf.int
        if (magic != PATCH_MAGIC) return null

        val version = buf.int  // patch_version (informational)
        val count = buf.short.toInt() and 0xFFFF

        val patches = ArrayList<PatchOp>(count)

        for (i in 0 until count) {
            if (buf.remaining() < 5) return null  // need at least op_type + node_id

            val opType = buf.get().toInt() and 0xFF
            val nodeId = buf.int

            val op = when (opType) {
                PatchOpType.UPDATE_PROPS -> {
                    PatchOp(opType, nodeId, props = readGenericProps())
                }
                PatchOpType.UPDATE_LAYOUT -> {
                    PatchOp(opType, nodeId, layout = readLayout())
                }
                PatchOpType.UPDATE_STYLE -> {
                    PatchOp(opType, nodeId, style = readStyle())
                }
                PatchOpType.UPDATE_CALLBACKS -> {
                    if (buf.remaining() < 8) return null
                    val onPress = buf.int
                    val onLongPress = buf.int
                    PatchOp(opType, nodeId, onPress = onPress, onLongPress = onLongPress)
                }
                else -> return null  // Unknown op type — abort
            }

            patches.add(op)
        }

        return patches
    }

    // Reuse the same binary reading logic as NativeUITreeReader

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
                    buf.get()
                    0
                }
            }

            map[key] = value
        }

        return GenericProps(map)
    }

    private fun readString(): String {
        val len = buf.short.toInt() and 0xFFFF
        if (len == 0) return ""
        val bytes = ByteArray(len)
        buf.get(bytes)
        return String(bytes, Charsets.UTF_8)
    }
}

/**
 * Apply a list of patch operations to an existing tree, producing a new tree.
 * Uses copy-on-write: only nodes on the path to patched nodes are recreated.
 */
fun applyPatches(tree: NativeUITree, patches: List<PatchOp>): NativeUITree {
    var newRoot = tree.root
    for (patch in patches) {
        newRoot = patchNode(newRoot, patch)
    }
    return tree.copy(root = newRoot)
}

/**
 * Recursively traverse the tree to find and patch the target node.
 * Returns a new node if anything changed, or the same instance if not.
 */
private fun patchNode(node: NativeUINode, patch: PatchOp): NativeUINode {
    if (node.id == patch.nodeId) {
        return node.copy(
            props = patch.props ?: node.props,
            layout = if (patch.layout != null) patch.layout else node.layout,
            style = if (patch.style != null) patch.style else node.style,
            onPress = patch.onPress ?: node.onPress,
            onLongPress = patch.onLongPress ?: node.onLongPress,
        )
    }

    // Recurse into children
    var changed = false
    val newChildren = node.children.map { child ->
        val patched = patchNode(child, patch)
        if (patched !== child) changed = true
        patched
    }

    return if (changed) node.copy(children = newChildren) else node
}
