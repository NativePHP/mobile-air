package com.nativephp.mobile.ui.nativerender

import android.os.Handler
import android.os.Looper
import android.util.Log
import java.nio.ByteBuffer
import java.nio.ByteOrder
import java.util.concurrent.LinkedBlockingQueue
import java.util.concurrent.TimeUnit

/**
 * Element Runtime bridge — direct JNI push architecture.
 *
 * Instead of a watcher thread polling shared memory:
 * - PHP thread calls postTreeUpdate() directly via JNI after building flat buffer
 * - Compose callbacks enqueue events into eventQueue
 * - PHP thread calls pollEvent() which blocks on the queue
 *
 * Flat node layout (108 bytes packed, little-endian):
 *   0: id (u32)           4: type_idx (u16)      6: child_count (u16)
 *   8: first_child_offset  12: on_press           16: on_long_press
 *  20: width (f32)        24: width_mode (u8)    25: height (f32)
 *  29: height_mode (u8)   30: align_self (u8)    31: align_items (u8)
 *  32: justify_content     33: safe_area          34: padding[4] (4×f32)
 *  50: margin[4] (4×f32)  66: flex_grow          70: flex_shrink
 *  74: gap                78: bg_color (u32)     82: border_radius
 *  86: border_width       90: border_color       94: opacity
 *  98: elevation         102: prop_offset        106: prop_size (u16)
 */
class NativeElementBridge private constructor() {
    companion object {
        private const val TAG = "NativeElementBridge"
        private const val FLAT_NODE_SIZE = 108

        /* ── JNI native methods (registered by bridge_jni.cpp) ── */

        @JvmStatic external fun nativeElementIsReady(): Boolean
        @JvmStatic external fun nativeElementWaitUpdate(currentVersion: Int, timeoutMs: Int): Int
        @JvmStatic external fun nativeGetFlatBuffer(): java.nio.ByteBuffer?
        @JvmStatic external fun nativeGetPropBuffer(): java.nio.ByteBuffer?
        @JvmStatic external fun nativeGetTypeTable(): Array<String>?
        @JvmStatic external fun nativeGetNodeCount(): Int
        @JvmStatic external fun nativeElementWriteEvent(type: Int, callbackId: Int, nodeId: Int, data: ByteArray?)

        private val mainHandler = Handler(Looper.getMainLooper())
        private var cachedTypeTable: Array<String>? = null

        /** Previous tree for incremental diff — reuse unchanged node references */
        private var previousTree: NativeUITree? = null

        /** Runtime toggle for tree diff — set via Perf.SetDiffEnabled */
        @Volatile
        @JvmStatic
        var diffEnabled = true

        /** Event queue — Compose callbacks enqueue, pollEvent() dequeues (blocks PHP thread) */
        private val eventQueue = LinkedBlockingQueue<NativeUIEvent>()

        /**
         * Called from JNI on the PHP thread after nativephp_element_publish()
         * builds the flat buffer. Reads the flat buffer, parses the tree,
         * and posts it to the main thread.
         *
         * This replaces the watcher thread entirely.
         */
        @JvmStatic
        fun postTreeUpdate() {
            try {
                val t0 = System.nanoTime()
                PerformanceTracker.onTreeUpdateReceived()

                // Always read fresh type table — types accumulate across pages
                // and the cached table may be stale after navigation
                val typeTable = nativeGetTypeTable()
                if (typeTable == null) {
                    Log.e(TAG, "postTreeUpdate: nativeGetTypeTable() returned null")
                    return
                }
                cachedTypeTable = typeTable

                val nodeCount = nativeGetNodeCount()
                if (nodeCount == 0) {
                    Log.w(TAG, "postTreeUpdate: nodeCount=0, skipping")
                    return
                }

                val flatDirect = nativeGetFlatBuffer()
                if (flatDirect == null) {
                    Log.e(TAG, "postTreeUpdate: nativeGetFlatBuffer() returned null (nodeCount=$nodeCount)")
                    return
                }
                val propDirect = nativeGetPropBuffer()

                val t1 = System.nanoTime()

                // Bulk-copy DirectByteBuffer → heap for fast reading
                val flatBytes = ByteArray(flatDirect.capacity())
                flatDirect.get(flatBytes)
                val flatBuf = ByteBuffer.wrap(flatBytes).order(ByteOrder.LITTLE_ENDIAN)

                val propBuf = if (propDirect != null && propDirect.capacity() > 0) {
                    val propBytes = ByteArray(propDirect.capacity())
                    propDirect.get(propBytes)
                    ByteBuffer.wrap(propBytes).order(ByteOrder.LITTLE_ENDIAN)
                } else null

                val tree = readTreeFromFlatBuffer(flatBuf, propBuf, typeTable, nodeCount)
                val t2 = System.nanoTime()

                if (tree != null) {
                    val nc = countNodes(tree.root)
                    val isNav = NativeUIBridge.navigationPending
                    if (isNav) NativeUIBridge.navigationPending = false

                    // Diff against previous tree — reuse unchanged node references
                    val prev = previousTree
                    val isDiffOn = diffEnabled
                    val diffedTree: NativeUITree
                    if (prev != null && !isNav && isDiffOn) {
                        val stats = DiffStats()
                        val t3 = System.nanoTime()
                        val diffedRoot = diffNodeWithStats(prev.root, tree.root, stats)
                        val t4 = System.nanoTime()
                        diffedTree = tree.copy(root = diffedRoot)
                        PerformanceTracker.onTreeDiffed(t4 - t3, stats.reused, stats.replaced, true)
                    } else {
                        diffedTree = tree
                        PerformanceTracker.onTreeDiffed(0, 0, nc, false)
                    }
                    previousTree = diffedTree

                    Log.d(TAG, "PERF tree: jni=${(t1-t0)/1_000_000}ms parse=${(t2-t1)/1_000_000}ms nodes=$nc types=${typeTable.size} isNav=$isNav flatSize=${flatBytes.size}")

                    mainHandler.post {
                        PerformanceTracker.onTreePostedToMain()
                        NativeUIBridge.isActive.value = true
                        val prevKey = NativeUIBridge.screenKey.intValue
                        if (isNav) NativeUIBridge.screenKey.intValue++
                        NativeUIBridge.currentTree.value = diffedTree
                        Log.d(TAG, "mainThread: tree posted, screenKey=$prevKey→${NativeUIBridge.screenKey.intValue} isNav=$isNav rootType=${diffedTree.root.type}")
                    }
                } else {
                    Log.e(TAG, "Failed to parse tree (nodeCount=$nodeCount flatSize=${flatBytes.size} typeCount=${typeTable.size} expected=${nodeCount * FLAT_NODE_SIZE})")
                }
            } catch (e: Throwable) {
                Log.e(TAG, "postTreeUpdate failed: ${e.message}", e)
            }
        }

        /**
         * Called from JNI on the PHP thread. Blocks until an event is available
         * or timeout expires.
         *
         * @return JSON event string, or null on timeout
         */
        @JvmStatic
        fun pollEvent(timeoutMs: Long): String? {
            val event = if (timeoutMs < 0) {
                eventQueue.take()
            } else {
                eventQueue.poll(timeoutMs, TimeUnit.MILLISECONDS)
            }
            return event?.toJson()
        }

        /**
         * Clear event queue (called on reset/shutdown).
         */
        fun clearEvents() {
            eventQueue.clear()
        }

        /** Reset state for new hot-reload cycle */
        fun startWatching() {
            Log.d(TAG, "startWatching() — resetting state for new cycle")
            clearEvents()
            cachedTypeTable = null
            previousTree = null
        }

        @JvmStatic
        fun stopWatching() {
            clearEvents()
            cachedTypeTable = null
            previousTree = null
            mainHandler.post {
                NativeUIBridge.isActive.value = false
                NativeUIBridge.currentTree.value = null
            }
        }

        /* ── Flat Buffer Tree Reader ── */

        private fun readTreeFromFlatBuffer(
            flatBuf: ByteBuffer,
            propBuf: ByteBuffer?,
            typeTable: Array<String>,
            nodeCount: Int
        ): NativeUITree? {
            if (nodeCount == 0) return null
            if (flatBuf.remaining() < nodeCount * FLAT_NODE_SIZE) return null

            val root = readNodeDFS(flatBuf, propBuf, typeTable) ?: return null
            return NativeUITree(0, 0, root)
        }

        private fun readNodeDFS(
            buf: ByteBuffer,
            propBuf: ByteBuffer?,
            typeTable: Array<String>
        ): NativeUINode? {
            if (buf.remaining() < FLAT_NODE_SIZE) return null

            val id = buf.int
            val typeIdx = buf.short.toInt() and 0xFFFF
            val childCount = buf.short.toInt() and 0xFFFF
            val firstChildOffset = buf.int
            val onPress = buf.int
            val onLongPress = buf.int

            val width = buf.float
            val widthMode = buf.get().toInt() and 0xFF
            val height = buf.float
            val heightMode = buf.get().toInt() and 0xFF
            val alignSelf = buf.get().toInt() and 0xFF
            val alignItems = buf.get().toInt() and 0xFF
            val justifyContent = buf.get().toInt() and 0xFF
            val safeArea = buf.get().toInt() and 0xFF

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
            val gap = buf.float

            val bgColor = buf.int
            val borderRadius = buf.float
            val borderWidth = buf.float
            val borderColor = buf.int
            val opacity = buf.float
            val elevation = buf.float

            val propOffset = buf.int
            val propSize = buf.short.toInt() and 0xFFFF

            val type = if (typeIdx < typeTable.size) typeTable[typeIdx] else "column"

            val layout = NodeLayout(
                width, widthMode, height, heightMode,
                paddingTop, paddingRight, paddingBottom, paddingLeft,
                marginTop, marginRight, marginBottom, marginLeft,
                flexGrow, flexShrink,
                alignSelf, alignItems, justifyContent, gap, safeArea
            )

            val style = NodeStyle(bgColor, borderRadius, borderWidth, borderColor, opacity, elevation)

            val props = if (propSize > 0 && propBuf != null) {
                readPropsFromBuffer(propBuf, propOffset, propSize)
            } else {
                GenericProps()
            }

            val children = ArrayList<NativeUINode>(childCount)
            for (i in 0 until childCount) {
                val child = readNodeDFS(buf, propBuf, typeTable) ?: break
                children.add(child)
            }

            return NativeUINode(id, type, layout, style, props, onPress, onLongPress, children)
        }

        /* ── Props Reader ── */

        private fun readPropsFromBuffer(propBuf: ByteBuffer, offset: Int, size: Int): GenericProps {
            if (size == 0) return GenericProps()
            try {
                val slice = propBuf.duplicate()
                slice.position(offset)
                slice.limit(offset + size)
                slice.order(ByteOrder.LITTLE_ENDIAN)
                return readGenericProps(slice)
            } catch (e: Exception) {
                Log.w(TAG, "Failed to read props at offset=$offset size=$size: ${e.message}")
                return GenericProps()
            }
        }

        private fun readGenericProps(buf: ByteBuffer): GenericProps {
            val propCount = buf.get().toInt() and 0xFF
            if (propCount == 0) return GenericProps()

            val map = LinkedHashMap<String, Any>(propCount)
            for (i in 0 until propCount) {
                if (buf.remaining() < 2) break

                val keyIndex = buf.get().toInt() and 0xFF
                val key = if (keyIndex != PropKey.FALLBACK && keyIndex < PropKey.TABLE.size) {
                    PropKey.TABLE[keyIndex]
                } else {
                    readString(buf)
                }
                val typeTag = buf.get().toInt() and 0xFF

                val value: Any = when (typeTag) {
                    ValType.U8 -> buf.get().toInt() and 0xFF
                    ValType.U16 -> buf.short.toInt() and 0xFFFF
                    ValType.U32 -> buf.int
                    ValType.I32 -> buf.int
                    ValType.F32 -> buf.float
                    ValType.BOOL -> (buf.get().toInt() != 0)
                    ValType.STRING -> readString(buf)
                    ValType.COLOR -> buf.int
                    ValType.CALLBACK -> buf.int
                    ValType.STRING_ARRAY -> {
                        val count = buf.short.toInt() and 0xFFFF
                        val list = ArrayList<String>(count)
                        for (j in 0 until count) {
                            list.add(readString(buf))
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

        private fun readString(buf: ByteBuffer): String {
            val len = buf.short.toInt() and 0xFFFF
            if (len == 0) return ""
            val bytes = ByteArray(len)
            buf.get(bytes)
            return String(bytes, Charsets.UTF_8)
        }

        /* ── Event Sending Helpers ── */

        fun sendPressEvent(callbackId: Int, nodeId: Int, x: Float = 0f, y: Float = 0f) {
            PerformanceTracker.onInteractionStart(callbackId, "press")
            val buf = ByteBuffer.allocate(8).order(ByteOrder.LITTLE_ENDIAN)
            buf.putFloat(x)
            buf.putFloat(y)
            nativeElementWriteEvent(EventType.PRESS, callbackId, nodeId, buf.array())
        }

        fun sendLongPressEvent(callbackId: Int, nodeId: Int, x: Float = 0f, y: Float = 0f) {
            val buf = ByteBuffer.allocate(8).order(ByteOrder.LITTLE_ENDIAN)
            buf.putFloat(x)
            buf.putFloat(y)
            nativeElementWriteEvent(EventType.LONG_PRESS, callbackId, nodeId, buf.array())
        }

        fun sendTextChangeEvent(callbackId: Int, nodeId: Int, text: String) {
            PerformanceTracker.onInteractionStart(callbackId, "text_change")
            val textBytes = text.toByteArray(Charsets.UTF_8)
            val buf = ByteBuffer.allocate(2 + textBytes.size).order(ByteOrder.LITTLE_ENDIAN)
            buf.putShort(textBytes.size.toShort())
            buf.put(textBytes)
            nativeElementWriteEvent(EventType.TEXT_CHANGE, callbackId, nodeId, buf.array())
        }

        fun sendToggleChangeEvent(callbackId: Int, nodeId: Int, value: Boolean) {
            PerformanceTracker.onInteractionStart(callbackId, "toggle_change")
            val buf = ByteBuffer.allocate(1).order(ByteOrder.LITTLE_ENDIAN)
            buf.put(if (value) 1.toByte() else 0.toByte())
            nativeElementWriteEvent(EventType.TOGGLE_CHANGE, callbackId, nodeId, buf.array())
        }

        fun sendSubmitEvent(callbackId: Int, nodeId: Int, text: String) {
            val textBytes = text.toByteArray(Charsets.UTF_8)
            val buf = ByteBuffer.allocate(2 + textBytes.size).order(ByteOrder.LITTLE_ENDIAN)
            buf.putShort(textBytes.size.toShort())
            buf.put(textBytes)
            nativeElementWriteEvent(EventType.SUBMIT, callbackId, nodeId, buf.array())
        }

        fun sendSystemBackEvent() {
            nativeElementWriteEvent(EventType.SYSTEM_BACK, 0, 0, null)
        }

        fun sendSliderChangeEvent(callbackId: Int, nodeId: Int, value: Float) {
            PerformanceTracker.onInteractionStart(callbackId, "slider_change")
            val buf = ByteBuffer.allocate(4).order(ByteOrder.LITTLE_ENDIAN)
            buf.putFloat(value)
            nativeElementWriteEvent(EventType.SLIDER_CHANGE, callbackId, nodeId, buf.array())
        }

        fun sendCheckboxChangeEvent(callbackId: Int, nodeId: Int, value: Boolean) {
            PerformanceTracker.onInteractionStart(callbackId, "checkbox_change")
            val buf = ByteBuffer.allocate(1).order(ByteOrder.LITTLE_ENDIAN)
            buf.put(if (value) 1.toByte() else 0.toByte())
            nativeElementWriteEvent(EventType.CHECKBOX_CHANGE, callbackId, nodeId, buf.array())
        }

        fun sendRadioChangeEvent(callbackId: Int, nodeId: Int, value: String) {
            PerformanceTracker.onInteractionStart(callbackId, "radio_change")
            val textBytes = value.toByteArray(Charsets.UTF_8)
            val buf = ByteBuffer.allocate(2 + textBytes.size).order(ByteOrder.LITTLE_ENDIAN)
            buf.putShort(textBytes.size.toShort())
            buf.put(textBytes)
            nativeElementWriteEvent(EventType.RADIO_CHANGE, callbackId, nodeId, buf.array())
        }

        fun sendTabChangeEvent(callbackId: Int, nodeId: Int, index: Int) {
            PerformanceTracker.onInteractionStart(callbackId, "tab_change")
            val buf = ByteBuffer.allocate(2).order(ByteOrder.LITTLE_ENDIAN)
            buf.putShort(index.toShort())
            nativeElementWriteEvent(EventType.TAB_CHANGE, callbackId, nodeId, buf.array())
        }

        fun sendSheetDismissEvent(callbackId: Int, nodeId: Int) {
            nativeElementWriteEvent(EventType.SHEET_DISMISS, callbackId, nodeId, null)
        }

        fun sendHotReloadEvent() {
            val ready = nativeElementIsReady()
            Log.d(TAG, "sendHotReloadEvent: elementReady=$ready")
            if (!ready) {
                Log.e(TAG, "sendHotReloadEvent: element NOT ready — event will be dropped")
            }
            nativeElementWriteEvent(EventType.HOT_RELOAD, 0, 0, null)
        }

        fun sendSelectChangeEvent(callbackId: Int, nodeId: Int, value: String) {
            PerformanceTracker.onInteractionStart(callbackId, "select_change")
            val textBytes = value.toByteArray(Charsets.UTF_8)
            val buf = ByteBuffer.allocate(2 + textBytes.size).order(ByteOrder.LITTLE_ENDIAN)
            buf.putShort(textBytes.size.toShort())
            buf.put(textBytes)
            nativeElementWriteEvent(EventType.SELECT_CHANGE, callbackId, nodeId, buf.array())
        }

        /* ── Tree Diff — reuse unchanged node references ── */

        class DiffStats(var reused: Int = 0, var replaced: Int = 0)

        /**
         * Recursively diff old and new trees with stats tracking.
         * Returns old node reference when the subtree is identical
         * (reference equality = fast Compose skip).
         */
        private fun diffNodeWithStats(old: NativeUINode, new: NativeUINode, stats: DiffStats): NativeUINode {
            // Structural mismatch — use new node entirely
            if (old.id != new.id || old.type != new.type || old.children.size != new.children.size) {
                stats.replaced += countNodes(new)
                return new
            }

            // Recursively diff children
            var allChildrenReused = true
            val diffedChildren = if (new.children.isEmpty()) {
                new.children
            } else {
                val list = ArrayList<NativeUINode>(new.children.size)
                for (i in new.children.indices) {
                    val diffed = diffNodeWithStats(old.children[i], new.children[i], stats)
                    if (diffed !== old.children[i]) allChildrenReused = false
                    list.add(diffed)
                }
                list
            }

            // Check if this node's own fields changed
            val fieldsMatch = old.layout == new.layout &&
                    old.style == new.style &&
                    old.onPress == new.onPress &&
                    old.onLongPress == new.onLongPress &&
                    old.props == new.props

            // Entire subtree identical — reuse old reference
            if (fieldsMatch && allChildrenReused) {
                stats.reused++
                return old
            }

            // This node is replaced (children already counted recursively)
            stats.replaced++

            // Fields same but some children changed — old fields + diffed children
            if (fieldsMatch) {
                return old.copy(children = diffedChildren)
            }

            // Fields changed — new values + diffed children
            return new.copy(children = diffedChildren)
        }

        /* ── Utilities ── */

        private fun countNodes(node: NativeUINode): Int {
            return 1 + node.children.sumOf { countNodes(it) }
        }
    }
}

/**
 * UI event data class for the event queue.
 */
data class NativeUIEvent(
    val type: Int,
    val callbackId: Int,
    val nodeId: Int,
    val data: ByteArray? = null,
    val timestamp: Long = System.currentTimeMillis()
) {
    fun toJson(): String {
        val sb = StringBuilder("{")
        sb.append("\"type\":$type")
        sb.append(",\"callback_id\":$callbackId")
        sb.append(",\"node_id\":$nodeId")
        sb.append(",\"timestamp\":$timestamp")
        // Additional data parsing would go here if needed
        sb.append("}")
        return sb.toString()
    }
}
