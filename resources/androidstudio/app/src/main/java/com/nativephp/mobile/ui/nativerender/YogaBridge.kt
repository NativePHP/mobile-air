package com.nativephp.mobile.ui.nativerender

import android.graphics.Paint
import android.graphics.Rect
import android.util.DisplayMetrics
import android.util.Log
import java.nio.ByteBuffer

/**
 * Kotlin bridge to Yoga C++ layout engine via JNI.
 *
 * Takes a parsed NativeUITree and computes Yoga layout,
 * annotating each node with a ComputedLayout (x, y, w, h).
 */
object YogaBridge {
    private const val TAG = "YogaBridge"

    /** Feature flag — when false, Yoga is skipped entirely */
    @Volatile
    @JvmStatic
    var yogaEnabled = true

    /** Cached safe area insets in pixels — set from main thread when window insets change */
    @Volatile
    var safeAreaTopPx: Int = 0

    @Volatile
    var safeAreaBottomPx: Int = 0

    /**
     * Native JNI method — computes Yoga layout from flat buffer.
     */
    @JvmStatic
    external fun nativeComputeLayout(
        flatBuffer: ByteArray,
        nodeCount: Int,
        viewportWidth: Float,
        viewportHeight: Float,
        density: Float,
        typeTable: Array<String>,
        measurements: FloatArray?
    ): FloatArray?

    /**
     * Compute Yoga layout for a tree and return an annotated copy with ComputedLayout on each node.
     */
    fun computeLayout(
        tree: NativeUITree,
        flatBytes: ByteArray,
        nodeCount: Int,
        typeTable: Array<String>,
        metrics: DisplayMetrics
    ): NativeUITree {
        if (!yogaEnabled) return tree

        // Yoga C bridge requires 160-byte flat nodes. If PHP writes 108-byte nodes, skip.
        val perNode = if (nodeCount > 0) flatBytes.size / nodeCount else 0
        if (perNode < 160) return tree

        val t0 = System.nanoTime()

        // Inject safe area insets as Yoga padding for ANY node with the safe_area flag.
        // This must happen before Yoga compute so layout accounts for safe area correctly,
        // especially inside scroll views where post-layout adjustments cause clipping.
        // Byte 77 = safe_area flag in 160-byte flat node layout.
        val density = metrics.density
        val topDp = safeAreaTopPx / density
        val bottomDp = safeAreaBottomPx / density

        if (topDp > 0 || bottomDp > 0) {
            val buf = java.nio.ByteBuffer.wrap(flatBytes).order(java.nio.ByteOrder.LITTLE_ENDIAN)
            for (i in 0 until nodeCount) {
                val offset = i * 160
                if (offset + 160 > flatBytes.size) break
                val safeArea = flatBytes[offset + 77].toInt() and 0xFF
                if (safeArea != 0) {
                    val existingTop = buf.getFloat(offset + 30)
                    val existingBottom = buf.getFloat(offset + 38)
                    buf.putFloat(offset + 30, existingTop + topDp)
                    buf.putFloat(offset + 38, existingBottom + bottomDp)
                }
            }
        }

        // Phase 3: Pre-measure leaf nodes
        val measurements = FloatArray(nodeCount * 2)
        val viewportDp = metrics.widthPixels / metrics.density
        preMeasure(tree.root, measurements, intArrayOf(0), viewportDp, metrics.density)

        val t1 = System.nanoTime()

        val results = try {
            nativeComputeLayout(
                flatBytes, nodeCount,
                metrics.widthPixels.toFloat(),
                metrics.heightPixels.toFloat(),
                metrics.density,
                typeTable,
                measurements
            )
        } catch (e: Throwable) {
            Log.e(TAG, "nativeComputeLayout failed: ${e.message}", e)
            null
        }

        if (results == null || results.size < nodeCount * 4) {
            Log.w(TAG, "Yoga compute returned null or insufficient data")
            return tree
        }

        val t2 = System.nanoTime()

        // Walk tree in DFS pre-order and attach computed layouts
        var idx = 0
        fun annotateNode(node: NativeUINode): NativeUINode {
            if (idx >= nodeCount) return node

            val computed = ComputedLayout(
                x = results[idx * 4 + 0],
                y = results[idx * 4 + 1],
                width = results[idx * 4 + 2],
                height = results[idx * 4 + 3]
            )
            idx++

            val annotatedChildren = if (node.children.isEmpty()) {
                node.children
            } else {
                node.children.map { annotateNode(it) }
            }

            return node.copy(children = annotatedChildren, computed = computed)
        }

        val annotatedRoot = annotateNode(tree.root)

        val t3 = System.nanoTime()
        Log.d(TAG, "PERF measure=${(t1-t0)/1_000_000}ms compute=${(t2-t1)/1_000_000}ms annotate=${(t3-t2)/1_000_000}ms nodes=$nodeCount")

        return tree.copy(root = annotatedRoot)
    }

    /** Walk tree in DFS pre-order and fill measurements with intrinsic sizes. */
    private fun preMeasure(node: NativeUINode, measurements: FloatArray, indexHolder: IntArray, maxWidthDp: Float, density: Float) {
        val idx = indexHolder[0]
        if (idx * 2 + 1 >= measurements.size) return

        val (w, h) = measureNode(node, maxWidthDp, density)
        measurements[idx * 2] = w
        measurements[idx * 2 + 1] = h
        indexHolder[0] = idx + 1

        for (child in node.children) {
            preMeasure(child, measurements, indexHolder, maxWidthDp, density)
        }
    }

    /** Measure intrinsic size for a node in dp. Returns (width, height). */
    private fun measureNode(node: NativeUINode, maxWidthDp: Float, density: Float): Pair<Float, Float> {
        return when (node.type) {
            "text" -> measureText(node, maxWidthDp, density)
            "button" -> measureButton(node, maxWidthDp, density)
            "icon" -> {
                val size = node.props.getFloat("size", 24f)
                Pair(size, size)
            }
            "toggle" -> {
                val label = node.props.getString("label")
                if (label.isEmpty()) Pair(52f, 32f)
                else {
                    val (tw, th) = measureString(label, 16f, maxWidthDp - 60f, density)
                    Pair(tw + 60f, maxOf(th, 32f))  // 52 switch + 8 spacing
                }
            }
            "checkbox" -> {
                val label = node.props.getString("label")
                if (label.isEmpty()) Pair(24f, 24f)
                else {
                    val (tw, th) = measureString(label, 16f, maxWidthDp - 32f, density)
                    Pair(tw + 32f, maxOf(th, 24f))
                }
            }
            "text_input" -> Pair(0f, 48f)
            "slider" -> Pair(0f, 32f)
            "progress_bar" -> Pair(0f, 4f)
            "activity_indicator" -> {
                val size = node.props.getInt("size")
                when (size) {
                    1 -> Pair(48f, 48f)   // large
                    2 -> Pair(16f, 16f)   // small
                    else -> Pair(28f, 28f) // default
                }
            }
            "divider", "line", "horizontal_divider" -> Pair(0f, 1f)
            "spacer" -> Pair(0f, 0f)
            "image" -> {
                val w = if (node.layout?.widthMode == SizeMode.FIXED) node.layout.width else 0f
                val h = if (node.layout?.heightMode == SizeMode.FIXED) node.layout.height else 0f
                Pair(w, h)
            }
            "badge" -> {
                val count = node.props.getInt("count")
                val text = if (count > 99) "99+" else "$count"
                val (tw, th) = measureString(text, 12f, Float.MAX_VALUE, density)
                Pair(tw + 12f, th + 4f)
            }
            "chip" -> {
                val label = node.props.getString("label")
                val hasIcon = node.props.getString("icon").isNotEmpty()
                val iconSpace = if (hasIcon) 20f else 0f
                val hPad = 24f
                val (tw, th) = measureString(label, 14f, maxWidthDp - hPad - iconSpace, density)
                Pair(tw + hPad + iconSpace, maxOf(th + 12f, 32f))
            }
            "select" -> Pair(0f, 48f)
            "radio" -> {
                val label = node.props.getString("label")
                if (label.isEmpty()) Pair(24f, 24f)
                else {
                    val (tw, th) = measureString(label, 16f, maxWidthDp - 32f, density)
                    Pair(tw + 32f, maxOf(th, 24f))
                }
            }
            else -> Pair(0f, 0f)
        }
    }

    private fun measureText(node: NativeUINode, maxWidthDp: Float, density: Float): Pair<Float, Float> {
        val text = node.props.getString("text")
        if (text.isEmpty()) return Pair(0f, 0f)

        val fontSize = node.props.getFloat("font_size", 16f)
        val maxLines = node.props.getInt("max_lines")
        val fontWeight = node.props.getInt("font_weight")

        return measureString(text, fontSize, maxWidthDp, density, maxLines, fontWeight)
    }

    private fun measureButton(node: NativeUINode, maxWidthDp: Float, density: Float): Pair<Float, Float> {
        val label = node.props.getString("label")
        if (label.isEmpty()) return Pair(48f, 40f)

        val fontSize = node.props.getFloat("font_size", 16f)
        val hPad = 48f // 24 each side
        val effectiveMaxW = if (maxWidthDp > hPad) maxWidthDp - hPad else Float.MAX_VALUE

        // Buttons always render bold
        val (tw, th) = measureString(label, fontSize, effectiveMaxW, density, fontWeight = 6)
        return Pair(tw + hPad, maxOf(th + 16f, 40f))
    }

    /** Measure text string in dp units using Android's Paint. Thread-safe. */
    private fun measureString(text: String, fontSizeDp: Float, maxWidthDp: Float, density: Float, maxLines: Int = 0, fontWeight: Int = 0): Pair<Float, Float> {
        val isBold = fontWeight >= 4  // semibold and above
        val paint = Paint().apply {
            textSize = fontSizeDp * density
            isAntiAlias = true
            if (isBold) typeface = android.graphics.Typeface.DEFAULT_BOLD
        }

        val maxWidthPx = if (maxWidthDp < Float.MAX_VALUE) maxWidthDp * density else Float.MAX_VALUE

        // Simple measurement — use StaticLayout for multiline
        if (maxLines == 1 || !text.contains('\n')) {
            val widthPx = paint.measureText(text)
            val bounds = Rect()
            paint.getTextBounds(text, 0, text.length, bounds)
            val heightPx = bounds.height().toFloat().coerceAtLeast(paint.textSize * 1.2f)

            if (widthPx <= maxWidthPx) {
                // +1dp buffer to prevent sub-pixel rounding clipping last character
                return Pair(widthPx / density + 1f, heightPx / density)
            }
        }

        // Multiline: use StaticLayout
        val sl = android.text.StaticLayout.Builder
            .obtain(text, 0, text.length, android.text.TextPaint().apply {
                textSize = fontSizeDp * density
                isAntiAlias = true
                if (isBold) typeface = android.graphics.Typeface.DEFAULT_BOLD
            }, (if (maxWidthPx < Float.MAX_VALUE) maxWidthPx else 10000f).toInt())
            .apply {
                if (maxLines > 0) setMaxLines(maxLines)
            }
            .build()

        val w = (0 until sl.lineCount).maxOf { sl.getLineWidth(it) }
        val h = sl.height.toFloat()

        // +1dp buffer to prevent sub-pixel rounding clipping last character
        return Pair(w / density + 1f, h / density)
    }
}
