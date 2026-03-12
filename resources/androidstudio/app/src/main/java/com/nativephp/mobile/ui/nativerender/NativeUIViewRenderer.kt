package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.content.res.Configuration
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.view.MotionEvent
import android.view.View
import android.view.ViewGroup
import android.view.ViewOutlineProvider
import android.view.inputmethod.InputMethodManager
import android.widget.FrameLayout
import android.widget.HorizontalScrollView
import android.widget.ScrollView
import com.google.android.material.bottomsheet.BottomSheetDialog
import com.nativephp.mobile.R

/**
 * Imperative view tree renderer driven by NativeUINode trees from PHP.
 * Mirrors the iOS NativeUIViewRenderer architecture exactly:
 * each node maps to an android.view.View, diffs and mutates views on tree update.
 */
class NativeUIViewRenderer(context: Context) : FrameLayout(context) {

    /** Flat map of nodeId → View for O(1) lookup during diff */
    private val viewMap = mutableMapOf<Int, View>()

    /** Track which node is currently associated with each view */
    private val nodeMap = mutableMapOf<Int, NativeUINode>()

    /** Density for dp→px conversion */
    private val density = context.resources.displayMetrics.density

    private val scrollTypes = setOf("scroll_view")
    private val sheetTypes = setOf("bottom_sheet")

    /** Active bottom sheet dialog (one at a time) */
    private var activeSheet: BottomSheetDialog? = null

    init {
        val dark = (context.resources.configuration.uiMode and
            Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
        setBackgroundColor(if (dark) Color.parseColor("#1C1C1E") else Color.WHITE)
        // Dismiss keyboard on tap (like iOS)
        setOnTouchListener { _, event ->
            if (event.action == MotionEvent.ACTION_DOWN) {
                dismissKeyboard()
            }
            false
        }
    }

    override fun dispatchTouchEvent(ev: MotionEvent): Boolean {
        if (ev.action == MotionEvent.ACTION_DOWN) {
            val touchX = ev.x
            val touchY = ev.y
            // Find which clickable views contain this touch point
            val hitViews = mutableListOf<String>()
            for ((nodeId, view) in viewMap) {
                if (!view.isClickable) continue
                val loc = IntArray(2)
                view.getLocationInWindow(loc)
                val myLoc = IntArray(2)
                getLocationInWindow(myLoc)
                val relX = loc[0] - myLoc[0]
                val relY = loc[1] - myLoc[1]
                val hit = touchX >= relX && touchX <= relX + view.width &&
                          touchY >= relY && touchY <= relY + view.height
                val node = nodeMap[nodeId]
                if (hit || (kotlin.math.abs(touchX - (relX + view.width / 2)) < 100 &&
                            kotlin.math.abs(touchY - (relY + view.height / 2)) < 100)) {
                    hitViews.add("id=$nodeId type=${node?.type} viewPos=($relX,$relY,${view.width}x${view.height}) " +
                        "computed=(${node?.computed?.let { "${it.x},${it.y},${it.width}x${it.height}" } ?: "nil"}) " +
                        "hit=$hit clickable=${view.isClickable}")
                }
            }
            if (hitViews.isNotEmpty()) {
                android.util.Log.d("TOUCH_DEBUG", "Touch at (${touchX.toInt()},${touchY.toInt()}) renderer=(${width}x$height) density=$density nearby:")
                hitViews.forEach { android.util.Log.d("TOUCH_DEBUG", "  $it") }
            }
        }
        return super.dispatchTouchEvent(ev)
    }

    private fun dismissKeyboard() {
        val imm = context.getSystemService(Context.INPUT_METHOD_SERVICE) as? InputMethodManager
        imm?.hideSoftInputFromWindow(windowToken, 0)
        clearFocus()
    }

    // ── Apply Tree ──

    fun applyTree(tree: NativeUITree) {
        applyNode(tree.root, this, 0)
        removeStaleChildren(this, listOf(tree.root))
    }

    fun clear() {
        removeAllViews()
        viewMap.clear()
        nodeMap.clear()
        activeSheet?.dismiss()
        activeSheet = null
    }

    // ── Recursive Node Application ──

    private fun applyNode(node: NativeUINode, parentView: ViewGroup, index: Int) {
        val existingView = viewMap[node.id]

        if (existingView != null) {
            // Check reference equality — if same object, diff says unchanged
            if (nodeMap[node.id] === node) {
                ensureParent(existingView, parentView, index)
                return
            }

            // Same ID, changed props/style/layout — update in place
            ensureParent(existingView, parentView, index)
            applyLayout(existingView, node)
            applyStyle(existingView, node)
            applyClickHandlers(existingView, node)
            updateLeafContent(existingView, node)
            nodeMap[node.id] = node

            // Recurse children
            reconcileChildren(existingView, node)
        } else {
            // New node — create view
            val view = createNodeView(node)
            applyLayout(view, node)
            applyStyle(view, node)
            applyClickHandlers(view, node)
            // Apply leaf content AFTER style so leaf renderers can override bg
            updateLeafContent(view, node)
            nodeMap[node.id] = node
            viewMap[node.id] = view

            insertView(view, parentView, index)

            // Create children recursively
            when {
                scrollTypes.contains(node.type) -> reconcileScrollChildren(view, node)
                sheetTypes.contains(node.type) -> handleBottomSheet(view, node)
                else -> {
                    val container = view as? ViewGroup ?: return
                    for (i in node.children.indices) {
                        applyNode(node.children[i], container, i)
                    }
                }
            }
        }
    }

    // ── View Creation ──

    private fun createNodeView(node: NativeUINode): View {
        if (scrollTypes.contains(node.type)) {
            return createScrollView(node)
        }
        if (sheetTypes.contains(node.type)) {
            val v = View(context)
            v.visibility = View.GONE
            return v
        }

        // Check registry for leaf renderers
        if (node.children.isEmpty()) {
            val renderer = NativeRendererRegistry.getView(node.type)
            if (renderer != null) {
                return renderer.createView(context, node)
            }
        }

        // Container — plain FrameLayout, transparent
        val container = FrameLayout(context)
        container.setBackgroundColor(Color.TRANSPARENT)
        container.clipChildren = false
        container.clipToPadding = false
        return container
    }

    private fun updateLeafContent(view: View, node: NativeUINode) {
        if (node.children.isEmpty()) {
            val renderer = NativeRendererRegistry.getView(node.type)
            renderer?.updateView(view, node)
        }
    }

    // ── Layout (frame from Yoga → LayoutParams) ──

    private fun applyLayout(view: View, node: NativeUINode) {
        val c = node.computed ?: return

        val x = (c.x * density).toInt()
        val y = (c.y * density).toInt()
        val w = (c.width * density).toInt().coerceAtLeast(0)
        val h = (c.height * density).toInt().coerceAtLeast(0)

        // Use FrameLayout.LayoutParams with margins for absolute positioning.
        // This is respected by FrameLayout's own onLayout pass.
        val lp = FrameLayout.LayoutParams(w, h).apply {
            leftMargin = x
            topMargin = y
        }
        view.layoutParams = lp

        // Canvas clips children to bounds
        if (node.type == "canvas" && view is ViewGroup) {
            view.clipChildren = true
            view.clipToPadding = true
        }

    }

    // ── Style ──

    private fun isDarkMode(): Boolean {
        return (context.resources.configuration.uiMode and
            Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
    }

    private fun applyStyle(view: View, node: NativeUINode) {
        val style = node.style
        if (style == null) {
            view.background = null
            view.elevation = 0f
            view.alpha = 1f
            return
        }

        val dark = isDarkMode()

        // Background + corners + border via GradientDrawable
        val darkBg = if (dark) node.props.getColor("dark_bg_color", 0) else 0
        val argb = if (darkBg != 0) darkBg else style.bgColor
        val alpha = (argb.toLong() and 0xFF000000L) ushr 24
        val hasBg = argb != 0 && alpha != 0L
        val hasCorners = style.borderRadius > 0
        val hasBorder = style.borderWidth > 0 && node.type != "line"

        if (hasBg || hasCorners || hasBorder) {
            val drawable = GradientDrawable()

            if (hasBg) {
                drawable.setColor(argb)
            } else {
                drawable.setColor(Color.TRANSPARENT)
            }

            if (hasCorners) {
                // Use Yoga computed dimensions for clamp (view isn't measured yet)
                val c = node.computed
                val viewW = if (c != null) c.width * density else 0f
                val viewH = if (c != null) c.height * density else 0f
                val maxRadius = minOf(viewW / 2f, viewH / 2f).coerceAtLeast(0f)
                drawable.cornerRadius = if (maxRadius > 0f) {
                    minOf(style.borderRadius * density, maxRadius)
                } else {
                    style.borderRadius * density
                }
            }

            if (hasBorder) {
                val darkBorderColor = if (dark) node.props.getColor("dark_border_color", 0) else 0
                val borderArgb = if (darkBorderColor != 0) darkBorderColor else style.borderColor
                drawable.setStroke(
                    (style.borderWidth * density).toInt(),
                    borderArgb
                )
            }

            view.background = drawable
        } else {
            view.background = null
        }

        // Elevation / shadow
        if (style.elevation > 0) {
            view.elevation = style.elevation * density
            view.outlineProvider = ViewOutlineProvider.BACKGROUND
            if (view is ViewGroup) {
                view.clipChildren = false
            }
        } else {
            view.elevation = 0f
        }

        // Clip to outline for rounded corners (but not if shadow needed with corners)
        if (hasCorners) {
            view.clipToOutline = style.elevation <= 0
        }

        // Opacity
        val darkOpacity = if (dark) node.props.getFloat("dark_opacity", 0f) else 0f
        view.alpha = if (darkOpacity > 0f) darkOpacity else style.opacity
    }

    // ── Click Handlers ──

    /** Leaf types that manage their own touch (EditText, Switch, etc.) */
    private val interactiveLeafTypes = setOf(
        "text_input", "toggle", "checkbox", "slider", "select", "radio_group"
    )

    private fun applyClickHandlers(view: View, node: NativeUINode) {
        val needsNativeTouch = interactiveLeafTypes.contains(node.type)

        if (node.onPress != 0) {
            view.setTag(R.id.tag_callback_id, node.onPress)
            view.setTag(R.id.tag_node_id, node.id)
            view.setOnClickListener { v ->
                val cbId = v.getTag(R.id.tag_callback_id) as? Int ?: return@setOnClickListener
                val nId = v.getTag(R.id.tag_node_id) as? Int ?: return@setOnClickListener
                NativeElementBridge.sendPressEvent(cbId, nId)
            }
            view.isClickable = true
        } else if (!needsNativeTouch) {
            // Don't call setOnClickListener(null) — it has a side effect of
            // setting isClickable=true (Android View.java always calls
            // setClickable(true) before assigning the listener, even for null).
            // Instead, just clear clickable state directly.
            view.isClickable = false
        }

        if (node.onLongPress != 0) {
            view.setTag(R.id.tag_long_press_cb, node.onLongPress)
            view.setTag(R.id.tag_node_id, node.id)
            view.setOnLongClickListener { v ->
                val cbId = v.getTag(R.id.tag_long_press_cb) as? Int ?: return@setOnLongClickListener false
                val nId = v.getTag(R.id.tag_node_id) as? Int ?: return@setOnLongClickListener false
                NativeElementBridge.sendLongPressEvent(cbId, nId)
                true
            }
            view.isLongClickable = true
        } else if (!needsNativeTouch) {
            view.isLongClickable = false
        }
    }

    // ── Child Reconciliation ──

    private fun reconcileChildren(parentView: View, node: NativeUINode) {
        when {
            scrollTypes.contains(node.type) -> reconcileScrollChildren(parentView, node)
            sheetTypes.contains(node.type) -> handleBottomSheet(parentView, node)
            else -> {
                val container = parentView as? ViewGroup ?: return
                for (i in node.children.indices) {
                    applyNode(node.children[i], container, i)
                }
                removeStaleChildren(container, node.children)
            }
        }
    }

    private fun removeStaleChildren(parentView: ViewGroup, expectedChildren: List<NativeUINode>) {
        val expectedIds = expectedChildren.map { it.id }.toSet()
        val toRemove = mutableListOf<View>()

        for (i in 0 until parentView.childCount) {
            val child = parentView.getChildAt(i)
            val viewNodeId = nodeIdForView(child)
            if (viewNodeId != null && viewNodeId !in expectedIds) {
                toRemove.add(child)
            }
        }

        for (view in toRemove) {
            removeViewRecursively(view)
        }
    }

    private fun removeViewRecursively(view: View) {
        if (view is ViewGroup) {
            for (i in view.childCount - 1 downTo 0) {
                removeViewRecursively(view.getChildAt(i))
            }
        }
        val nodeId = nodeIdForView(view)
        if (nodeId != null) {
            viewMap.remove(nodeId)
            nodeMap.remove(nodeId)
        }
        (view.parent as? ViewGroup)?.removeView(view)
    }

    private fun nodeIdForView(view: View): Int? {
        for ((id, v) in viewMap) {
            if (v === view) return id
        }
        return null
    }

    private fun ensureParent(view: View, parentView: ViewGroup, index: Int) {
        if (view.parent !== parentView) {
            (view.parent as? ViewGroup)?.removeView(view)
            insertView(view, parentView, index)
        }
    }

    private fun insertView(view: View, parent: ViewGroup, index: Int) {
        if (index < parent.childCount) {
            parent.addView(view, index)
        } else {
            parent.addView(view)
        }
    }

    // ── Scroll View ──

    private fun createScrollView(node: NativeUINode): ViewGroup {
        val horizontal = node.props.getBool("horizontal")
        val showIndicators = node.props.getBool("shows_indicators", true)

        return if (horizontal) {
            HorizontalScrollView(context).apply {
                isHorizontalScrollBarEnabled = showIndicators
                clipToPadding = false
                isFillViewport = false
                // Inner container for absolute positioning
                addView(FrameLayout(context).apply {
                    layoutParams = LayoutParams(
                        LayoutParams.WRAP_CONTENT,
                        LayoutParams.MATCH_PARENT
                    )
                })
            }
        } else {
            ScrollView(context).apply {
                isVerticalScrollBarEnabled = showIndicators
                clipToPadding = false
                isFillViewport = false
                // Inner container for absolute positioning
                addView(FrameLayout(context).apply {
                    layoutParams = LayoutParams(
                        LayoutParams.MATCH_PARENT,
                        LayoutParams.WRAP_CONTENT
                    )
                })
            }
        }
    }

    private fun reconcileScrollChildren(scrollView: View, node: NativeUINode) {
        // Get the inner container (first child of the ScrollView)
        val container: FrameLayout = when (scrollView) {
            is ScrollView -> scrollView.getChildAt(0) as? FrameLayout ?: return
            is HorizontalScrollView -> scrollView.getChildAt(0) as? FrameLayout ?: return
            else -> return
        }

        for (i in node.children.indices) {
            applyNode(node.children[i], container, i)
        }
        removeStaleChildren(container, node.children)

        // Compute content size from children extents
        var maxX = 0f
        var maxY = 0f
        for (child in node.children) {
            val c = child.computed ?: continue
            maxX = maxOf(maxX, c.x + c.width)
            maxY = maxOf(maxY, c.y + c.height)
        }

        val contentW = (maxX * density).toInt()
        val contentH = (maxY * density).toInt()

        container.layoutParams = FrameLayout.LayoutParams(contentW, contentH)
    }

    // ── Bottom Sheet ──

    private fun handleBottomSheet(view: View, node: NativeUINode) {
        val visible = node.props.getBool("visible")
        view.visibility = View.GONE // Placeholder always hidden

        if (!visible) {
            activeSheet?.dismiss()
            activeSheet = null
            return
        }

        if (activeSheet != null) return // Already showing

        val dialog = BottomSheetDialog(context)
        val contentView = FrameLayout(context)
        contentView.setBackgroundColor(if (isDarkMode()) Color.parseColor("#1C1C1E") else Color.WHITE)

        for (i in node.children.indices) {
            applyNode(node.children[i], contentView, i)
        }

        dialog.setContentView(contentView)

        val onDismissCb = node.props.getCallbackId("on_dismiss")
        dialog.setOnDismissListener {
            if (onDismissCb != 0) {
                NativeElementBridge.sendSheetDismissEvent(onDismissCb, node.id)
            }
            activeSheet = null
        }

        activeSheet = dialog
        dialog.show()
    }

    companion object {
        private const val TAG = "NativeUIViewRenderer"
    }
}
