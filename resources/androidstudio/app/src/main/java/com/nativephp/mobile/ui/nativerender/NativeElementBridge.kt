package com.nativephp.mobile.ui.nativerender

import android.os.Handler
import android.os.Looper
import android.util.Log
import java.nio.ByteBuffer
import java.nio.ByteOrder
import java.util.concurrent.LinkedBlockingQueue
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicReference
import java.util.concurrent.locks.LockSupport

/**
 * Element Runtime bridge — direct JNI push architecture.
 *
 * Instead of a watcher thread polling shared memory:
 * - PHP thread calls postTreeUpdate() directly via JNI after building flat buffer
 * - Compose callbacks enqueue events into eventQueue
 * - PHP thread calls pollEvent() which blocks on the queue
 *
 * Flat node layout (160 bytes packed, little-endian):
 *   0: id (u32)           4: type_idx (u16)      6: child_count (u16)
 *   8: first_child_offset  12: on_press           16: on_long_press
 *  20: width (f32)        24: width_mode (u8)    25: height (f32)
 *  29: height_mode (u8)   30: padding[4] (4×f32) 46: margin[4] (4×f32)
 *  62: flex_grow          66: flex_shrink         70: align_self (u8)
 *  71: align_items (u8)   72: justify_content     73: gap (f32)
 *  77: safe_area (u8)
 *  --- Extended layout (flexbox) ---
 *  78: min_width          82: min_height          86: max_width
 *  90: max_height         94: flex_basis           98: flex_basis_mode (u8)
 *  99: flex_wrap (u8)    100: flex_direction (u8) 101: position_type (u8)
 * 102: position[4] (4×f32)                       118: display (u8)
 * 119: overflow (u8)     120: align_content (u8)  121: direction (u8)
 * 122: aspect_ratio      126: row_gap
 *  --- Style ---
 * 130: bg_color          134: border_radius       138: border_width
 * 142: border_color      146: opacity             150: elevation
 * 154: prop_offset       158: prop_size (u16)
 */
class NativeElementBridge private constructor() {
    companion object {
        private const val TAG = "NativeElementBridge"
        // Wire-format node stride. Mirrors iOS's `nodeSize` and the
        // `nphp_element` struct on the PHP-extension side. If the producer
        // emits a different stride, the binaries are out of sync and we
        // hard-fail rather than guess at a legacy layout.
        //
        // v1 = 160 bytes. v2 (Phase 2) = 161 — appended `uint8_t flags` at
        // offset 160 for REUSE markers and future per-node bits.
        private const val NODE_SIZE = 161

        /** Offset of the flags byte appended in format_version 2. */
        private const val NODE_FLAGS_OFFSET = 160

        /** Phase 2 — bit 0 in nphp_flat_node_t::flags. Marks a subtree as
         *  identical to the previous frame; this reader splices the prior
         *  node by id instead of parsing absent children. */
        private const val NODE_FLAG_REUSE = 0x01

        /* ── JNI native methods (registered by bridge_jni.cpp) ── */

        @JvmStatic external fun nativeElementIsReady(): Boolean
        @JvmStatic external fun nativeElementWaitUpdate(currentVersion: Int, timeoutMs: Int): Int
        @JvmStatic external fun nativeGetFlatBuffer(): java.nio.ByteBuffer?
        @JvmStatic external fun nativeGetPropBuffer(): java.nio.ByteBuffer?
        @JvmStatic external fun nativeGetTypeTable(): Array<String>?
        @JvmStatic external fun nativeGetNodeCount(): Int
        @JvmStatic external fun nativeElementWriteEvent(type: Int, callbackId: Int, nodeId: Int, data: ByteArray?)

        /* Phase 0 — wire-format version + runtime feature flags
         * (NPHP_FORMAT_VERSION / NPHP_FLAG_* in nphp_element.h). */
        @JvmStatic external fun nativeGetFormatVersion(): Int
        @JvmStatic external fun nativeGetRuntimeFlags(): Int
        @JvmStatic external fun nativeSetRuntimeFlags(flags: Int)

        /**
         * Wire-format version this reader was compiled against. Must match
         * what the PHP extension reports via nativeGetFormatVersion(); a
         * mismatch means the libphp.a in the .so is out of sync with this
         * reader and parsing would render garbage (§5.4). Bump this in
         * lockstep with NPHP_FORMAT_VERSION in nphp_element.h.
         *
         * v2 (Phase 2) — appended `flags` byte to flat node (stride 161).
         * v3 — event-channel framing widened uint16 → uint32 (header data_size
         *      + body string length prefixes); lifts the 64KB native→PHP cap.
         */
        private const val EXPECTED_FORMAT_VERSION = 3

        /** Latched at startWatching(). 0 until then. Readable for telemetry. */
        @JvmStatic
        @Volatile
        var runtimeFlags: Int = 0
            private set

        /** One-shot guard for the format-version check. Reset on stopWatching()
         *  so a new region registration (hot reload) re-verifies. We can't put
         *  the check only in startWatching() because postTreeUpdate() has an
         *  auto-start path that bypasses it. */
        @Volatile
        private var versionChecked: Boolean = false

        /** Set true if a producer/reader format mismatch was detected. Once set,
         *  postTreeUpdate() short-circuits — parsing the flat buffer with a
         *  mismatched layout would render garbage (§5.4). */
        @Volatile
        private var versionMismatch: Boolean = false

        private val mainHandler = Handler(Looper.getMainLooper())
        private var cachedTypeTable: Array<String>? = null

        /** Previous tree for incremental diff — reuse unchanged node references (shadow thread only) */
        private var previousTree: NativeUITree? = null

        /**
         * Per-URI previous tree for native chrome stacks / tabs. Keyed
         * on the `current_uri` prop carried by `native_root_stack` /
         * `native_root_tabs`. Used so a publish that returns to a URI
         * we've seen before (e.g. popping back to a stack root, or
         * switching tabs) can diff against the LAST tree at that URI —
         * unchanged subtrees keep their refs and Compose's `key()` /
         * structural diffing avoids rebuilding them mid-animation.
         */
        private val nativeChromePrevTrees = mutableMapOf<String, NativeUITree>()

        /** Runtime toggle for tree diff — set via Perf.SetDiffEnabled */
        @Volatile
        @JvmStatic
        var diffEnabled = true

        /** Event queue — Compose callbacks enqueue, pollEvent() dequeues (blocks PHP thread) */
        private val eventQueue = LinkedBlockingQueue<NativeUIEvent>()

        /* ── Shadow Thread ── */

        /** Pending update — AtomicReference for lock-free coalescing */
        private val pendingUpdate = AtomicReference<ShadowUpdate?>(null)

        /** Shadow thread reference */
        private var shadowThread: Thread? = null

        /** Shadow thread run flag */
        @Volatile
        private var shadowRunning = false

        /**
         * Called from JNI on the PHP thread after nativephp_element_publish().
         * Copies raw buffers and signals the shadow thread — returns immediately
         * to unblock PHP. Parsing and diffing happen on the shadow thread.
         */
        @JvmStatic
        fun postTreeUpdate() {
            // Phase 0 — one-shot wire-format check. Both register-region (which
            // calls startWatching via JNI) and the auto-start path inside this
            // function converge here, so checking here is the only spot that
            // catches every code path. On mismatch we latch versionMismatch and
            // short-circuit every subsequent publish: parsing the flat buffer
            // with a mismatched layout would render garbage (§5.4).
            if (!versionChecked) {
                val producerVersion = nativeGetFormatVersion()
                if (producerVersion != EXPECTED_FORMAT_VERSION) {
                    Log.e(
                        TAG,
                        "FORMAT VERSION MISMATCH — reader expects $EXPECTED_FORMAT_VERSION " +
                            "but PHP extension reports $producerVersion. " +
                            "Rebuild libphp.a and this reader from matching sources " +
                            "(REFACTOR-native-ui-performance.md §5.4). Dropping all tree updates."
                    )
                    versionMismatch = true
                } else {
                    runtimeFlags = nativeGetRuntimeFlags()
                    Log.i(
                        TAG,
                        "postTreeUpdate: first publish — format_version=$producerVersion " +
                            "runtime_flags=0x${"%08x".format(runtimeFlags)}"
                    )
                }
                versionChecked = true
            }
            if (versionMismatch) return

            try {
                val t0 = System.nanoTime()
                PerformanceTracker.onTreeUpdateReceived()

                // JNI calls must happen on PHP thread (reads native memory PHP owns)
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

                // Phase 3 — when NPHP_FLAG_DOUBLE_BUFFER is on, skip the
                // heap copy entirely. The producer alternates between
                // buffer A and B per publish, so the buffer we're about
                // to parse is guaranteed to be untouched until the
                // publish AFTER the next one — plenty of time for the
                // shadow thread to finish parse, assuming parse < 2×
                // average publish interval. Re-read runtime_flags here
                // (not the latched startWatching value) so the toggle
                // takes effect on the next frame.
                //
                // When the flag is off, fall back to the original byte[]
                // copy — single-buffer + zero-copy is use-after-overwrite
                // unsafe.
                val zeroCopy = (nativeGetRuntimeFlags() and 0x04) != 0

                val flatBuf = if (zeroCopy) {
                    flatDirect.order(ByteOrder.LITTLE_ENDIAN)
                } else {
                    val flatBytes = ByteArray(flatDirect.capacity())
                    flatDirect.get(flatBytes)
                    ByteBuffer.wrap(flatBytes).order(ByteOrder.LITTLE_ENDIAN)
                }

                val propBuf = if (propDirect != null && propDirect.capacity() > 0) {
                    if (zeroCopy) {
                        propDirect.order(ByteOrder.LITTLE_ENDIAN)
                    } else {
                        val propBytes = ByteArray(propDirect.capacity())
                        propDirect.get(propBytes)
                        ByteBuffer.wrap(propBytes).order(ByteOrder.LITTLE_ENDIAN)
                    }
                } else null

                // Snapshot navigation flag on PHP thread (avoid race with next update)
                val isNav = NativeUIBridge.navigationPending
                if (isNav) NativeUIBridge.navigationPending = false

                val t1 = System.nanoTime()
                Log.d(TAG, "postTreeUpdate: jni+copy=${(t1-t0)/1_000_000}ms nodes=$nodeCount → shadow thread")

                // Ensure shadow thread is running (self-healing if it was stopped)
                if (shadowThread == null || shadowThread?.isAlive != true) {
                    Log.w(TAG, "postTreeUpdate: shadow thread not running — auto-starting")
                    shadowRunning = true
                    shadowThread = Thread({ shadowThreadLoop() }, "NativeUI-Shadow").also {
                        it.priority = Thread.MAX_PRIORITY - 1
                        it.start()
                    }
                }

                // Hand off to shadow thread — overwrites any pending update (coalescing)
                val update = ShadowUpdate(flatBuf, propBuf, typeTable, nodeCount, isNav, t0, t1)
                pendingUpdate.set(update)
                shadowThread?.let { LockSupport.unpark(it) }
            } catch (e: Throwable) {
                Log.e(TAG, "postTreeUpdate failed: ${e.message}", e)
            }
        }

        /**
         * Shadow thread main loop. Parks when idle, wakes on signal from
         * postTreeUpdate(). Grabs the latest pending update (coalescing),
         * parses the tree, diffs against previous, posts to main thread.
         */
        private fun shadowThreadLoop() {
            Log.d(TAG, "Shadow thread started")
            while (shadowRunning) {
                val update = pendingUpdate.getAndSet(null)
                if (update == null) {
                    LockSupport.park()
                    continue
                }

                try {
                    val tParseStart = System.nanoTime()
                    val tree = readTreeFromFlatBuffer(update.flatBuf, update.propBuf, update.typeTable, update.nodeCount)
                    val tParseEnd = System.nanoTime()

                    if (tree != null) {
                        val nc = countNodes(tree.root)
                        val prev = previousTree
                        val isDiffOn = diffEnabled

                        // Detect native-chrome continuation — same root sentinel
                        // type across publishes. Drives both diff baseline
                        // selection and screenKey suppression.
                        val prevRootType = prev?.root?.type
                        val newRootType = tree.root.type
                        val nativeChromeContinuation =
                            (prevRootType == "native_root_stack" && newRootType == "native_root_stack") ||
                            (prevRootType == "native_root_tabs"  && newRootType == "native_root_tabs")

                        // A `native_root_stack` renderer is about to COLD-mount
                        // (previous publish was a different root sentinel —
                        // tabs, WebView, or nothing). NavigationCoordinator is
                        // a singleton that survives renderer teardown, so it
                        // still holds the PREVIOUS stack session's rootUri and
                        // per-URI tree cache. Without a reset, the new stack's
                        // first publish falls through receive()'s seed/root
                        // checks into the PUSH branch — stacking the new screen
                        // on a stale root — and every subsequent pop animates
                        // through the OLD page. Reset on main below, atomically
                        // with the tree swap.
                        val isFreshStackMount =
                            newRootType == "native_root_stack" && prevRootType != "native_root_stack"

                        val newUri = tree.root.props.getString("current_uri", "")
                        val diffedTree: NativeUITree

                        if (prev != null && !update.isNav && isDiffOn) {
                            // State change — diff against immediate previous.
                            val stats = DiffStats()
                            val tDiffStart = System.nanoTime()
                            val diffedRoot = diffNodeWithStats(prev.root, tree.root, stats)
                            val tDiffEnd = System.nanoTime()
                            diffedTree = tree.copy(root = diffedRoot)
                            PerformanceTracker.onTreeDiffed(tDiffEnd - tDiffStart, stats.reused, stats.replaced, true)
                            PerformanceTracker.onShadowThreadWork(tParseEnd - tParseStart, tDiffEnd - tDiffStart)
                        } else if (update.isNav && nativeChromeContinuation && isDiffOn) {
                            // Nav within native chrome — prefer the prev tree at
                            // the SAME URI when available (pop-back to a cached
                            // URI re-uses every unchanged ref). Fall back to the
                            // immediate previous (tab switch / push within the
                            // same chrome — shared chrome subtrees still match).
                            // `prev` is non-null here — nativeChromeContinuation
                            // requires prev?.root?.type to match a sentinel.
                            val baseline = if (newUri.isNotEmpty()) {
                                nativeChromePrevTrees[newUri] ?: prev
                            } else {
                                prev
                            }
                            val stats = DiffStats()
                            val tDiffStart = System.nanoTime()
                            val diffedRoot = diffNodeWithStats(baseline.root, tree.root, stats)
                            val tDiffEnd = System.nanoTime()
                            diffedTree = tree.copy(root = diffedRoot)
                            PerformanceTracker.onTreeDiffed(tDiffEnd - tDiffStart, stats.reused, stats.replaced, true)
                            PerformanceTracker.onShadowThreadWork(tParseEnd - tParseStart, tDiffEnd - tDiffStart)
                        } else {
                            diffedTree = tree
                            PerformanceTracker.onTreeDiffed(0, 0, nc, false)
                            PerformanceTracker.onShadowThreadWork(tParseEnd - tParseStart, 0)
                        }
                        previousTree = diffedTree

                        // Track per-URI for native chrome so future publishes
                        // back to the same URI can diff against it.
                        if (newRootType == "native_root_stack" || newRootType == "native_root_tabs") {
                            val uri = diffedTree.root.props.getString("current_uri", "")
                            if (uri.isNotEmpty()) {
                                nativeChromePrevTrees[uri] = diffedTree
                            }
                        }

                        Log.d(TAG, "PERF shadow: jni+copy=${(update.t1-update.t0)/1_000_000}ms parse=${(tParseEnd-tParseStart)/1_000_000}ms nodes=$nc types=${update.typeTable.size} isNav=${update.isNav} cont=$nativeChromeContinuation")

                        val isNav = update.isNav
                        mainHandler.post {
                            PerformanceTracker.onTreePostedToMain()
                            NativeUIBridge.isActive.value = true
                            val prevKey = NativeUIBridge.screenKey.intValue
                            // Suppress screen-level screenKey bump when both
                            // trees are the same kind of native chrome — the
                            // NavigationStack / TabView already animates the
                            // push / pop / tab switch internally, and bumping
                            // screenKey would trigger the AnimatedContent at
                            // NativeUIContent's root, replacing the system
                            // animation with a slide overlay.
                            if (isFreshStackMount) NavigationCoordinator.reset()
                            if (isNav && !nativeChromeContinuation) NativeUIBridge.screenKey.intValue++
                            NativeUIBridge.currentTree.value = diffedTree
                            // First publish after a hot-reload dismisses
                            // the "Reloading…" pill and clears the
                            // tree-preservation flag. Both are set by
                            // MainActivity::startHotReloadWatcher at the
                            // top of the reboot sequence.
                            if (NativeUIBridge.isReloading.value) {
                                NativeUIBridge.isReloading.value = false
                                preserveTreeOnStop = false
                            }
                            Log.d(TAG, "mainThread: tree posted, screenKey=$prevKey→${NativeUIBridge.screenKey.intValue} isNav=$isNav rootType=${diffedTree.root.type}")
                        }
                    } else {
                        Log.e(TAG, "Shadow: failed to parse tree (nodeCount=${update.nodeCount})")
                    }
                } catch (e: Throwable) {
                    Log.e(TAG, "Shadow thread processing failed: ${e.message}", e)
                }
            }
            Log.d(TAG, "Shadow thread stopped")
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
            Log.d(TAG, "startWatching() — resetting state, starting shadow thread")

            // Stop existing shadow thread before starting a new one
            val oldThread = shadowThread
            if (oldThread != null && oldThread.isAlive) {
                shadowRunning = false
                LockSupport.unpark(oldThread)
                oldThread.join(2000)
                if (oldThread.isAlive) {
                    Log.w(TAG, "Old shadow thread still alive after 2s — proceeding anyway")
                }
            }

            clearEvents()
            cachedTypeTable = null
            previousTree = null
            nativeChromePrevTrees.clear()
            pendingUpdate.set(null)

            // Start shadow thread
            shadowRunning = true
            shadowThread = Thread({ shadowThreadLoop() }, "NativeUI-Shadow").also {
                it.priority = Thread.MAX_PRIORITY - 1
                it.start()
            }
        }

        /**
         * Flag set by the hot-reload watcher around a PHP shutdown/
         * reboot cycle. When true, `stopWatching` skips clearing
         * `NativeUIBridge.isActive` / `currentTree` so SwiftUI's
         * equivalent (Compose's `if (nativeUIActive)` overlay) keeps
         * showing the previous tree across the reboot. Required to
         * avoid a window where `isActive` flips false and the watcher
         * misroutes a follow-up save to the WebView branch — which
         * doesn't write `.hot_restart` and breaks the reload chain.
         */
        @JvmStatic
        @Volatile
        var preserveTreeOnStop = false

        @JvmStatic
        fun stopWatching() {
            // Stop shadow thread
            shadowRunning = false
            shadowThread?.let { LockSupport.unpark(it) }
            shadowThread = null
            pendingUpdate.set(null)

            clearEvents()
            cachedTypeTable = null
            previousTree = null

            // Phase 0 — re-verify wire format on next region registration.
            // A hot-reload cycle can swap libphp.a out from under us.
            versionChecked = false
            versionMismatch = false
            if (!preserveTreeOnStop) {
                mainHandler.post {
                    NativeUIBridge.isActive.value = false
                    NativeUIBridge.currentTree.value = null
                    // Don't clear sideNavNode — the new page's RenderSideNav will overwrite it.
                    // Clearing it mid-navigation causes a null→non-null flicker that glitches the drawer.
                }
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

            val perNode = flatBuf.remaining() / nodeCount
            if (perNode < NODE_SIZE) {
                Log.e(TAG, "ERROR node size $perNode < $NODE_SIZE — rebuild PHP binaries with $NODE_SIZE-byte struct (flat=${flatBuf.remaining()} nodes=$nodeCount)")
                return null
            }

            if (flatBuf.remaining() < nodeCount * NODE_SIZE) return null

            // Phase 2 — build an id → node index of the previous tree so
            // REUSE markers in this frame can splice prior subtrees in O(1).
            // Built lazily / cheaply: a single DFS walk over the prev tree.
            val prevIndex = buildIdNodeIndex(previousTree)

            val root = readNodeDFS(flatBuf, propBuf, typeTable, NODE_SIZE, prevIndex) ?: return null
            return NativeUITree(0, 0, root)
        }

        /** Phase 2 — flatten the previous tree into an id → node map so
         *  the parser can splice REUSE subtrees in O(1) by id. Empty on
         *  the first frame and after a hot reload / region reset. */
        private fun buildIdNodeIndex(tree: NativeUITree?): Map<Int, NativeUINode> {
            if (tree == null) return emptyMap()
            val out = HashMap<Int, NativeUINode>(64)
            fun walk(n: NativeUINode) {
                out[n.id] = n
                for (c in n.children) walk(c)
            }
            walk(tree.root)
            return out
        }

        private fun readNodeDFS(
            buf: ByteBuffer,
            propBuf: ByteBuffer?,
            typeTable: Array<String>,
            stride: Int,
            prevIndex: Map<Int, NativeUINode>
        ): NativeUINode? {
            if (buf.remaining() < stride) return null

            // Phase 2 — peek the flag byte at offset 160 (absolute read,
            // doesn't advance position). If REUSE, we don't need to parse
            // any fields beyond the id; splice the prior subtree from the
            // index and skip ahead.
            val nodeStart = buf.position()
            val flags = buf.get(nodeStart + NODE_FLAGS_OFFSET).toInt() and 0xFF
            if (flags and NODE_FLAG_REUSE != 0) {
                val reuseId = buf.int                       // advances by 4
                buf.position(nodeStart + stride)            // skip the rest of this node
                val prior = prevIndex[reuseId]
                if (prior != null) {
                    return prior
                }
                // PHP shouldn't emit REUSE for an id that wasn't in the
                // previous tree — that's a forceFullFrame oversight. Log
                // and drop this subtree; renders an empty hole as a
                // visible "something went wrong" signal.
                Log.w(TAG, "REUSE flag set for id=$reuseId but no prior node in index — skipping")
                return null
            }

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

            val minWidth = buf.float; val minHeight = buf.float
            val maxWidth = buf.float; val maxHeight = buf.float
            val flexBasis = buf.float
            val flexBasisMode = buf.get().toInt() and 0xFF
            val flexWrap = buf.get().toInt() and 0xFF
            val flexDirection = buf.get().toInt() and 0xFF
            val positionType = buf.get().toInt() and 0xFF
            val positionTop = buf.float; val positionRight = buf.float
            val positionBottom = buf.float; val positionLeft = buf.float
            val display = buf.get().toInt() and 0xFF
            val overflow = buf.get().toInt() and 0xFF
            val alignContent = buf.get().toInt() and 0xFF
            val direction = buf.get().toInt() and 0xFF
            val aspectRatio = buf.float; val rowGap = buf.float

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
                alignSelf, alignItems, justifyContent, gap, safeArea,
                minWidth, minHeight, maxWidth, maxHeight,
                flexBasis, flexBasisMode, flexWrap, flexDirection, positionType,
                positionTop, positionRight, positionBottom, positionLeft,
                display, overflow, alignContent, direction,
                aspectRatio, rowGap
            )

            val style = NodeStyle(bgColor, borderRadius, borderWidth, borderColor, opacity, elevation)

            val props = if (propSize > 0 && propBuf != null) {
                readPropsFromBuffer(propBuf, propOffset, propSize)
            } else {
                GenericProps()
            }

            // Phase 2 — sequential field reads above consume 160 bytes
            // (id..propSize), but the node stride is 161 (flag byte at 160).
            // Skip the flag byte before recursing into children so their
            // first u32 lands on the next node's id, not the flag byte.
            buf.position(nodeStart + stride)

            val children = ArrayList<NativeUINode>(childCount)
            for (i in 0 until childCount) {
                val child = readNodeDFS(buf, propBuf, typeTable, stride, prevIndex) ?: break
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
            val buf = ByteBuffer.allocate(4 + textBytes.size).order(ByteOrder.LITTLE_ENDIAN)
            buf.putInt(textBytes.size)
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
            val buf = ByteBuffer.allocate(4 + textBytes.size).order(ByteOrder.LITTLE_ENDIAN)
            buf.putInt(textBytes.size)
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
            val buf = ByteBuffer.allocate(4 + textBytes.size).order(ByteOrder.LITTLE_ENDIAN)
            buf.putInt(textBytes.size)
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

        /**
         * Wake the PHP runloop out of `nativephp_element_wait_event` and
         * make it exit cleanly (no hot-restart state). Required before
         * waiting on persistent-runtime shutdown: the runloop occupies the
         * single PHP executor thread, so a shutdown task queued behind it
         * never runs until the loop exits — with a foreground service
         * keeping the process alive past onDestroy, that was a permanent
         * main-thread hang (ANR).
         */
        fun sendShutdownEvent() {
            nativeElementWriteEvent(EventType.SHUTDOWN, 0, 0, null)
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
            val buf = ByteBuffer.allocate(4 + textBytes.size).order(ByteOrder.LITTLE_ENDIAN)
            buf.putInt(textBytes.size)
            buf.put(textBytes)
            nativeElementWriteEvent(EventType.SELECT_CHANGE, callbackId, nodeId, buf.array())
        }

        /**
         * Inject a native event into the element event queue.
         * This wakes up nativephp_element_wait_event() on the PHP side.
         * Data format: two length-prefixed UTF-8 strings (event name, payload JSON).
         */
        fun sendNativeEvent(eventName: String, payloadJson: String) {
            val nameBytes = eventName.toByteArray(Charsets.UTF_8)
            val payloadBytes = payloadJson.toByteArray(Charsets.UTF_8)
            val buf = ByteBuffer.allocate(4 + nameBytes.size + 4 + payloadBytes.size)
                .order(ByteOrder.LITTLE_ENDIAN)
            buf.putInt(nameBytes.size)
            buf.put(nameBytes)
            buf.putInt(payloadBytes.size)
            buf.put(payloadBytes)
            nativeElementWriteEvent(EventType.NATIVE, 0, 0, buf.array())
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
 * Snapshot of raw buffer data passed from PHP thread to shadow thread.
 * Heap-copied ByteBuffers are safe to read on any thread.
 */
private data class ShadowUpdate(
    val flatBuf: ByteBuffer,
    val propBuf: ByteBuffer?,
    val typeTable: Array<String>,
    val nodeCount: Int,
    val isNav: Boolean,
    val t0: Long,   // nanoTime at postTreeUpdate entry
    val t1: Long,   // nanoTime after JNI + buffer copy
)

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
