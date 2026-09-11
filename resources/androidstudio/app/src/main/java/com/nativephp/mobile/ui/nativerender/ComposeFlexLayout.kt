package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.BiasAlignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp

// MARK: - Flex Enums (match PHP/binary protocol values)

object FlexDirection {
    const val COLUMN = 0
    const val ROW = 1
}

object JustifyContent {
    const val START = 0
    const val CENTER = 1
    const val END = 2
    const val SPACE_BETWEEN = 3
    const val SPACE_AROUND = 4
    const val SPACE_EVENLY = 5
}

object AlignItems {
    /**
     * No `items-*` / `self-*` class was authored — PHP omits `align_items`
     * entirely, so it arrives as 0 and each renderer applies its own default.
     * On Android that default is content-sizing (this file never adds
     * `fillMaxWidth()` for it), which is unchanged by #309.
     */
    const val UNSET = 0
    const val CENTER = 1
    const val END = 2
    const val STRETCH = 3

    /**
     * Explicitly authored `items-start` / `self-start`. Deliberately NOT 0:
     * while Android renders START and UNSET identically, iOS does not, and a
     * shared wire value meant iOS could not fix `items-start` without also
     * flipping every unclassed container (mobile-air #309). Android behaviour
     * is untouched — 4 falls through the same `else` branches 0 always did.
     */
    const val START = 4
}

object PositionType {
    const val RELATIVE = 0
    const val ABSOLUTE = 1
}

object Display {
    const val FLEX = 0
    const val NONE = 1
}

/**
 * Renders children in a flex container using Compose's built-in Column/Row.
 * Maps flexbox properties to Compose equivalents:
 *   - flex_direction → Column or Row
 *   - justify_content → Arrangement
 *   - align_items → Alignment
 *   - flex_grow → Modifier.weight()
 *   - gap → Arrangement.spacedBy()
 *   - FILL width/height → fillMaxWidth/fillMaxHeight
 *   - FIXED width/height → width/height
 *   - absolute positioning → Box overlay
 */
@Composable
fun FlexContainer(
    direction: Int = FlexDirection.COLUMN,
    justify: Int = JustifyContent.START,
    align: Int = AlignItems.STRETCH,
    gap: Float = 0f,
    wrap: Int = 0,
    childNodes: List<NativeUINode>,
    modifier: Modifier = Modifier,
    content: @Composable () -> Unit
) {
    // Separate absolute children from flow children
    val hasAbsolute = childNodes.any { (it.layout?.positionType ?: 0) == PositionType.ABSOLUTE }

    if (hasAbsolute) {
        // The flow block must size NORMALLY (never `matchParentSize()`) so
        // it can DETERMINE the Box's size when nothing else constrains it —
        // matching the Box to the flow content, not to the (typically tiny)
        // absolute child. The flip side: when the Box IS externally sized
        // (weight, fillMax), the content-sized flow block needs placing, and
        // the container's own justify/align decide where it sits — CSS lays
        // flow children out in the container's full box. Without this,
        // `items-center justify-center` silently top-start-pinned the flow
        // content on any container that also had absolute children.
        Box(
            modifier = modifier,
            contentAlignment = flowContentAlignment(direction, justify, align)
        ) {
            if (direction == FlexDirection.ROW) {
                FlexRow(justify, align, gap, wrap, childNodes, Modifier, content)
            } else {
                FlexColumn(justify, align, gap, childNodes, Modifier, content)
            }
            // Overlay absolute children on the container's real bounds.
            // CSS semantics, mirroring iOS `FlexContainer.placeAbsolute`:
            // one edge set anchors to it; BOTH opposing edges set stretch
            // the child between them (`inset-0` fills the container,
            // `inset-x-0` is full-bleed width); neither set falls back to
            // the top/leading origin. +0.0 means unset, any non-zero value
            // anchors (negatives overhang — the `-right-8` Tailwind bleed),
            // and IEEE -0.0 is an authored explicit zero (`bottom-0`).
            //
            // `matchParentSize()` hands each child the container's exact
            // bounds without letting it influence them — absolute children
            // never size the container (parity with iOS).
            childNodes.forEach { node ->
                if ((node.layout?.positionType ?: 0) == PositionType.ABSOLUTE) {
                    AbsolutePositionedChild(node = node, modifier = Modifier.matchParentSize())
                }
            }
        }
    } else {
        if (direction == FlexDirection.ROW) {
            FlexRow(justify, align, gap, wrap, childNodes, modifier, content)
        } else {
            FlexColumn(justify, align, gap, childNodes, modifier, content)
        }
    }
}

/**
 * Placement of the content-sized flow block inside a Box that also hosts
 * absolute children. space-between/around/evenly need the full main axis to
 * mean anything for a content-sized block, so they fall back to start.
 */
private fun flowContentAlignment(direction: Int, justify: Int, align: Int): Alignment {
    fun mainBias(j: Int): Float = when (j) {
        JustifyContent.CENTER -> 0f
        JustifyContent.END -> 1f
        else -> -1f
    }
    fun crossBias(a: Int): Float = when (a) {
        AlignItems.CENTER -> 0f
        AlignItems.END -> 1f
        else -> -1f
    }
    return if (direction == FlexDirection.ROW) {
        BiasAlignment(mainBias(justify), crossBias(align))
    } else {
        BiasAlignment(crossBias(align), mainBias(justify))
    }
}

/**
 * Measures and places one absolute child against the container bounds the
 * `matchParentSize` modifier hands us. A stretched axis (both opposing
 * insets set) is measured TIGHT — `absolute inset-x-0 justify-center` must
 * make the child the container's full width so its own arrangement can
 * center content, exactly like iOS proposes the stretched size. Unset axes
 * measure within the bounds and anchor to whichever edge was authored.
 */
@Composable
private fun AbsolutePositionedChild(node: NativeUINode, modifier: Modifier) {
    val l = node.layout
    val left = l?.positionLeft ?: 0f
    val top = l?.positionTop ?: 0f
    val right = l?.positionRight ?: 0f
    val bottom = l?.positionBottom ?: 0f
    // -0.0f == 0f in Kotlin, so authored zeros need the raw sign bit.
    fun isSet(v: Float) = v != 0f || v.toRawBits() != 0
    val hasLeft = isSet(left)
    val hasTop = isSet(top)
    val hasRight = isSet(right)
    val hasBottom = isSet(bottom)

    Layout(content = { NodeView(node = node) }, modifier = modifier) { measurables, constraints ->
        val boundsW = constraints.maxWidth
        val boundsH = constraints.maxHeight
        val leftPx = left.dp.roundToPx()
        val topPx = top.dp.roundToPx()
        val rightPx = right.dp.roundToPx()
        val bottomPx = bottom.dp.roundToPx()
        val stretchW = if (hasLeft && hasRight) (boundsW - leftPx - rightPx).coerceAtLeast(0) else null
        val stretchH = if (hasTop && hasBottom) (boundsH - topPx - bottomPx).coerceAtLeast(0) else null
        val childConstraints = Constraints(
            minWidth = stretchW ?: 0,
            maxWidth = stretchW ?: boundsW,
            minHeight = stretchH ?: 0,
            maxHeight = stretchH ?: boundsH
        )
        // A NodeView normally emits exactly one measurable, but a renderer
        // can legitimately emit none (display:none) — don't assume.
        val placeables = measurables.map { it.measure(childConstraints) }
        layout(boundsW, boundsH) {
            placeables.forEach { placeable ->
                val x = when {
                    hasLeft -> leftPx
                    hasRight -> boundsW - placeable.width - rightPx
                    else -> 0
                }
                val y = when {
                    hasTop -> topPx
                    hasBottom -> boundsH - placeable.height - bottomPx
                    else -> 0
                }
                placeable.place(x, y)
            }
        }
    }
}

@Composable
private fun FlexColumn(
    justify: Int,
    align: Int,
    gap: Float,
    childNodes: List<NativeUINode>,
    modifier: Modifier,
    content: @Composable () -> Unit
) {
    val arrangement = when (justify) {
        JustifyContent.CENTER -> if (gap > 0f) Arrangement.spacedBy(gap.dp, Alignment.CenterVertically) else Arrangement.Center
        JustifyContent.END -> if (gap > 0f) Arrangement.spacedBy(gap.dp, Alignment.Bottom) else Arrangement.Bottom
        JustifyContent.SPACE_BETWEEN -> Arrangement.SpaceBetween
        JustifyContent.SPACE_AROUND -> Arrangement.SpaceAround
        JustifyContent.SPACE_EVENLY -> Arrangement.SpaceEvenly
        else -> if (gap > 0f) Arrangement.spacedBy(gap.dp) else Arrangement.Top
    }

    val alignment = when (align) {
        AlignItems.CENTER -> Alignment.CenterHorizontally
        AlignItems.END -> Alignment.End
        else -> Alignment.Start // UNSET, START and STRETCH all start-align; STRETCH fills per-child
    }

    Column(
        modifier = modifier,
        verticalArrangement = arrangement,
        horizontalAlignment = alignment
    ) {
        childNodes.forEachIndexed { i, node ->
            if ((node.layout?.display ?: 0) == Display.NONE) return@forEachIndexed
            if ((node.layout?.positionType ?: 0) == PositionType.ABSOLUTE) return@forEachIndexed

            val childMod = buildChildModifier(node, isRow = false, align = align, scope = this)
            NodeView(node = node, overrideModifier = childMod)
        }
    }
}

@Composable
private fun FlexRow(
    justify: Int,
    align: Int,
    gap: Float,
    wrap: Int,
    childNodes: List<NativeUINode>,
    modifier: Modifier,
    content: @Composable () -> Unit
) {
    val arrangement = when (justify) {
        JustifyContent.CENTER -> if (gap > 0f) Arrangement.spacedBy(gap.dp, Alignment.CenterHorizontally) else Arrangement.Center
        JustifyContent.END -> if (gap > 0f) Arrangement.spacedBy(gap.dp, Alignment.End) else Arrangement.End
        JustifyContent.SPACE_BETWEEN -> Arrangement.SpaceBetween
        JustifyContent.SPACE_AROUND -> Arrangement.SpaceAround
        JustifyContent.SPACE_EVENLY -> Arrangement.SpaceEvenly
        else -> if (gap > 0f) Arrangement.spacedBy(gap.dp) else Arrangement.Start
    }

    val alignment = when (align) {
        AlignItems.CENTER -> Alignment.CenterVertically
        AlignItems.END -> Alignment.Bottom
        else -> Alignment.Top
    }

    // flex-wrap: children flow onto additional lines instead of overflowing /
    // clipping off the row's edge (no-wrap = 0). The line gap mirrors the
    // item gap.
    if (wrap != 0) {
        FlowRow(
            modifier = modifier,
            horizontalArrangement = arrangement,
            verticalArrangement = if (gap > 0f) Arrangement.spacedBy(gap.dp) else Arrangement.Top
        ) {
            childNodes.forEach { node ->
                if ((node.layout?.display ?: 0) == Display.NONE) return@forEach
                if ((node.layout?.positionType ?: 0) == PositionType.ABSOLUTE) return@forEach
                val childMod = buildChildModifier(node, isRow = true, align = align, scope = this)
                NodeView(node = node, overrideModifier = childMod)
            }
        }
        return
    }

    Row(
        modifier = modifier,
        horizontalArrangement = arrangement,
        verticalAlignment = alignment
    ) {
        childNodes.forEachIndexed { i, node ->
            if ((node.layout?.display ?: 0) == Display.NONE) return@forEachIndexed
            if ((node.layout?.positionType ?: 0) == PositionType.ABSOLUTE) return@forEachIndexed

            val childMod = buildChildModifier(node, isRow = true, align = align, scope = this)
            NodeView(node = node, overrideModifier = childMod)
        }
    }
}

/**
 * Apply `min_width` / `max_width` / `min_height` / `max_height` from the packed
 * node as Compose constraint bounds.
 *
 * A bound of 0 is "unset" on the wire, so it maps to [Dp.Unspecified] rather
 * than to a literal 0.dp — `widthIn(max = 0.dp)` would collapse the node.
 *
 * Shared by [buildChildModifier] and `NodeView`'s no-parent fallback so the
 * constraints apply whether or not the node sits inside a flex container.
 */
fun Modifier.applySizeConstraints(layout: NodeLayout?): Modifier {
    if (layout == null) return this

    var mod = this

    if (layout.minWidth > 0f || layout.maxWidth > 0f) {
        mod = mod.widthIn(
            min = if (layout.minWidth > 0f) layout.minWidth.dp else Dp.Unspecified,
            max = if (layout.maxWidth > 0f) layout.maxWidth.dp else Dp.Unspecified
        )
    }

    if (layout.minHeight > 0f || layout.maxHeight > 0f) {
        mod = mod.heightIn(
            min = if (layout.minHeight > 0f) layout.minHeight.dp else Dp.Unspecified,
            max = if (layout.maxHeight > 0f) layout.maxHeight.dp else Dp.Unspecified
        )
    }

    return mod
}

/**
 * Build per-child modifier for flex properties (weight, fill, fixed size, margin).
 */
@Composable
private fun buildChildModifier(
    node: NativeUINode,
    isRow: Boolean,
    align: Int,
    scope: Any // ColumnScope or RowScope
): Modifier {
    val layout = node.layout
    var mod: Modifier = Modifier

    // Margins. Split into a positive part (padding, which reserves space and
    // shifts siblings) and a negative part (offset, which only moves the drawn
    // child). `Modifier.padding` THROWS on a negative dp, so the split isn't
    // cosmetic — passing `-mt-4` straight through would crash the render.
    //
    // Caveat: a negative margin therefore does NOT pull siblings on Android
    // the way it does on iOS, where FlexContainer folds margins into its own
    // arithmetic. Use negative INSETS on an absolute child for overlap that
    // must behave identically on both platforms.
    if (layout != null && (layout.marginTop != 0f || layout.marginRight != 0f ||
        layout.marginBottom != 0f || layout.marginLeft != 0f)) {
        mod = mod.padding(
            start = layout.marginLeft.coerceAtLeast(0f).dp,
            top = layout.marginTop.coerceAtLeast(0f).dp,
            end = layout.marginRight.coerceAtLeast(0f).dp,
            bottom = layout.marginBottom.coerceAtLeast(0f).dp
        )

        val negX = layout.marginLeft.coerceAtMost(0f) - layout.marginRight.coerceAtMost(0f)
        val negY = layout.marginTop.coerceAtMost(0f) - layout.marginBottom.coerceAtMost(0f)
        if (negX != 0f || negY != 0f) {
            mod = mod.offset(x = negX.dp, y = negY.dp)
        }
    }

    // Min/max size constraints (`max-w-*`, `min-h-*`, …).
    //
    // Applied BEFORE the fill/fixed/weight modifiers below so that a combo
    // like `w-full max-w-[280px]` fills *within* the 280dp bound instead of
    // filling the parent and being clamped after the fact. Compose narrows
    // constraints outer→inner, so ordering here is the whole behaviour.
    //
    // 0 means "unset" on the wire (the packed node has no companion size
    // mode for min/max), which is why each bound is gated on `> 0f`.
    mod = mod.applySizeConstraints(layout)

    // Cross axis: per-child `self-*` PLACEMENT.
    //
    // The container's Column/Row alignment parameter applies to every child
    // uniformly, so an individual child can only move via `Modifier.align()`
    // inside the layout scope. Without this, `self-center` / `self-end` only
    // affected whether the child FILLED (via the width branch below) and never
    // where it sat — a `self-end` child hugged its content but stayed on the
    // leading edge. iOS honours align_self in FlexContainer's placement switch,
    // so this was a platform divergence.
    //
    // 0 = unset (inherit the container's align-items) and STRETCH needs no
    // placement — it's expressed as fillMaxWidth/Height below — so both fall
    // through untouched.
    val alignSelf = layout?.alignSelf ?: 0
    if (alignSelf > 0 && alignSelf != AlignItems.STRETCH) {
        mod = if (isRow && scope is RowScope) {
            with(scope) {
                when (alignSelf) {
                    AlignItems.CENTER -> mod.align(Alignment.CenterVertically)
                    AlignItems.END -> mod.align(Alignment.Bottom)
                    else -> mod.align(Alignment.Top)
                }
            }
        } else if (!isRow && scope is ColumnScope) {
            with(scope) {
                when (alignSelf) {
                    AlignItems.CENTER -> mod.align(Alignment.CenterHorizontally)
                    AlignItems.END -> mod.align(Alignment.End)
                    else -> mod.align(Alignment.Start)
                }
            }
        } else mod
    }

    // Main axis: flex_grow or fill → weight
    val flexGrow = layout?.flexGrow ?: 0f
    val mainFill = if (isRow) {
        layout?.widthMode == SizeMode.FILL
    } else {
        layout?.heightMode == SizeMode.FILL
    }

    if (flexGrow > 0f || mainFill == true) {
        val weight = if (flexGrow > 0f) flexGrow else 1f
        mod = if (isRow && scope is RowScope) {
            with(scope) { mod.weight(weight) }
        } else if (!isRow && scope is ColumnScope) {
            with(scope) { mod.weight(weight) }
        } else mod
    }

    // Main axis: fixed size
    if (isRow && layout?.widthMode == SizeMode.FIXED && (layout.width) > 0f) {
        mod = mod.width(layout.width.dp)
    }
    if (!isRow && layout?.heightMode == SizeMode.FIXED && (layout.height) > 0f) {
        mod = mod.height(layout.height.dp)
    }

    // Main axis: percent size (e.g. w-3/4 → 75). fillMaxWidth takes a fraction
    // in 0..1, so we divide the percent value by 100.
    if (isRow && layout?.widthMode == SizeMode.PERCENT && (layout.width) > 0f) {
        mod = mod.fillMaxWidth((layout.width / 100f).coerceIn(0f, 1f))
    }
    if (!isRow && layout?.heightMode == SizeMode.PERCENT && (layout.height) > 0f) {
        mod = mod.fillMaxHeight((layout.height / 100f).coerceIn(0f, 1f))
    }

    // Cross axis: fixed or fill
    // Note: STRETCH alignment does NOT apply fillMaxWidth/fillMaxHeight.
    // In Compose, fillMaxHeight() in a Row creates a circular dependency —
    // the Row's height is determined by children, but a stretched child
    // wants the Row's height, causing the tallest fixed-size child (e.g. avatar)
    // to set the height and clip taller content. Instead, children render at
    // natural size and the Row/Column expands to fit the tallest/widest child.
    // STRETCH only matters for explicit FILL mode.
    val effectiveAlign = if ((layout?.alignSelf ?: 0) > 0) layout!!.alignSelf else align
    if (isRow) {
        when {
            layout?.heightMode == SizeMode.FIXED && (layout.height) > 0f -> mod = mod.height(layout.height.dp)
            layout?.heightMode == SizeMode.PERCENT && (layout.height) > 0f ->
                mod = mod.fillMaxHeight((layout.height / 100f).coerceIn(0f, 1f))
            layout?.heightMode == SizeMode.FILL -> mod = mod.fillMaxHeight()
        }
    } else {
        // Cross-axis width fill, mirroring iOS (which proposes the full cross
        // width to column children but lets each decide). Applied only to column
        // children and never when explicitly center/end-aligned:
        //   - text  → fills so text-align (center/right) works, matching the iOS
        //             text renderer's `.frame(maxWidth: .infinity)`.
        //   - row   → fills ONLY when it must distribute its children
        //             (justify space-between/around/evenly, e.g. a label/value
        //             row). A default/justify-start row content-sizes — e.g. a
        //             chip pill — so it doesn't swallow the whole width.
        //   - other → content-sizes; columns/cards opt into full width with w-full.
        val justify = layout?.justifyContent ?: JustifyContent.START
        val stretchesWidth = (effectiveAlign != AlignItems.CENTER && effectiveAlign != AlignItems.END) &&
            when (node.type) {
                // Text fills the column width ONLY when it's center/right-aligned —
                // that's when the alignment needs a wider box to take effect (e.g.
                // a centered value pill). Left/default text is identical filled or
                // content-sized, so we leave it content-sized; that way a content-
                // sized column wrapping just a label (a chip/Subscribe button) keeps
                // hugging its text instead of stretching to the full row width.
                "text" -> node.props.getInt("text_align").let { it == 1 || it == 2 }
                // A row fills only when it must distribute its children.
                "row" -> justify == JustifyContent.SPACE_BETWEEN ||
                    justify == JustifyContent.SPACE_AROUND ||
                    justify == JustifyContent.SPACE_EVENLY
                else -> false
            }
        when {
            layout?.widthMode == SizeMode.FIXED && (layout.width) > 0f -> mod = mod.width(layout.width.dp)
            layout?.widthMode == SizeMode.PERCENT && (layout.width) > 0f ->
                mod = mod.fillMaxWidth((layout.width / 100f).coerceIn(0f, 1f))
            layout?.widthMode == SizeMode.FILL || effectiveAlign == AlignItems.STRETCH || stretchesWidth ->
                mod = mod.fillMaxWidth()
        }
    }

    return mod
}
