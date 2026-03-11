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
            "activity_indicator" -> Pair(20f, 20f)
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

        return measureString(text, fontSize, maxWidthDp, density, maxLines)
    }

    private fun measureButton(node: NativeUINode, maxWidthDp: Float, density: Float): Pair<Float, Float> {
        val label = node.props.getString("label")
        if (label.isEmpty()) return Pair(48f, 40f)

        val fontSize = node.props.getFloat("font_size", 16f)
        val hPad = 48f // 24 each side
        val effectiveMaxW = if (maxWidthDp > hPad) maxWidthDp - hPad else Float.MAX_VALUE

        val (tw, th) = measureString(label, fontSize, effectiveMaxW, density)
        return Pair(tw + hPad, maxOf(th + 16f, 40f))
    }

    /** Measure text string in dp units using Android's Paint. Thread-safe. */
    private fun measureString(text: String, fontSizeDp: Float, maxWidthDp: Float, density: Float, maxLines: Int = 0): Pair<Float, Float> {
        val paint = Paint().apply {
            textSize = fontSizeDp * density
            isAntiAlias = true
        }

        val maxWidthPx = if (maxWidthDp < Float.MAX_VALUE) maxWidthDp * density else Float.MAX_VALUE

        // Simple measurement — use StaticLayout for multiline
        if (maxLines == 1 || !text.contains('\n')) {
            val widthPx = paint.measureText(text)
            val bounds = Rect()
            paint.getTextBounds(text, 0, text.length, bounds)
            val heightPx = bounds.height().toFloat().coerceAtLeast(paint.textSize * 1.2f)

            if (widthPx <= maxWidthPx) {
                return Pair(widthPx / density, heightPx / density)
            }
        }

        // Multiline: use StaticLayout
        val sl = android.text.StaticLayout.Builder
            .obtain(text, 0, text.length, android.text.TextPaint().apply {
                textSize = fontSizeDp * density
                isAntiAlias = true
            }, (if (maxWidthPx < Float.MAX_VALUE) maxWidthPx else 10000f).toInt())
            .apply {
                if (maxLines > 0) setMaxLines(maxLines)
            }
            .build()

        val w = (0 until sl.lineCount).maxOf { sl.getLineWidth(it) }
        val h = sl.height.toFloat()

        return Pair(w / density, h / density)
    }
}
