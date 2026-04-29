package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
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
    const val START = 0
    const val CENTER = 1
    const val END = 2
    const val STRETCH = 3
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
    childNodes: List<NativeUINode>,
    modifier: Modifier = Modifier,
    content: @Composable () -> Unit
) {
    // Separate absolute children from flow children
    val hasAbsolute = childNodes.any { (it.layout?.positionType ?: 0) == PositionType.ABSOLUTE }

    if (hasAbsolute) {
        Box(modifier = modifier) {
            // Render flow children in Column/Row
            if (direction == FlexDirection.ROW) {
                FlexRow(justify, align, gap, childNodes, Modifier.matchParentSize(), content)
            } else {
                FlexColumn(justify, align, gap, childNodes, Modifier.matchParentSize(), content)
            }
            // Render absolute children on top — anchor to the appropriate
            // corner based on which edge insets are set, then offset inward.
            // Same convention as iOS FlexContainer.placeAbsolute: when
            // `right` is set and `left` is 0, anchor to the right edge;
            // same for bottom vs top.
            childNodes.forEachIndexed { i, node ->
                if ((node.layout?.positionType ?: 0) == PositionType.ABSOLUTE) {
                    val left = node.layout?.positionLeft ?: 0f
                    val top = node.layout?.positionTop ?: 0f
                    val right = node.layout?.positionRight ?: 0f
                    val bottom = node.layout?.positionBottom ?: 0f

                    val anchor = when {
                        right > 0f && bottom > 0f -> Alignment.BottomEnd
                        right > 0f && top > 0f    -> Alignment.TopEnd
                        right > 0f                 -> Alignment.TopEnd
                        bottom > 0f                -> Alignment.BottomStart
                        else                       -> Alignment.TopStart
                    }
                    val offsetX = if (right > 0f) (-right).dp else left.dp
                    val offsetY = if (bottom > 0f) (-bottom).dp else top.dp

                    Box(
                        modifier = Modifier
                            .align(anchor)
                            .offset(x = offsetX, y = offsetY)
                    ) {
                        NodeView(node = node)
                    }
                }
            }
        }
    } else {
        if (direction == FlexDirection.ROW) {
            FlexRow(justify, align, gap, childNodes, modifier, content)
        } else {
            FlexColumn(justify, align, gap, childNodes, modifier, content)
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
        else -> Alignment.Start // START and STRETCH both start-align; STRETCH handled per-child
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

    // Margins
    if (layout != null && (layout.marginTop > 0f || layout.marginRight > 0f ||
        layout.marginBottom > 0f || layout.marginLeft > 0f)) {
        mod = mod.padding(
            start = layout.marginLeft.dp,
            top = layout.marginTop.dp,
            end = layout.marginRight.dp,
            bottom = layout.marginBottom.dp
        )
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
        when {
            layout?.widthMode == SizeMode.FIXED && (layout.width) > 0f -> mod = mod.width(layout.width.dp)
            layout?.widthMode == SizeMode.PERCENT && (layout.width) > 0f ->
                mod = mod.fillMaxWidth((layout.width / 100f).coerceIn(0f, 1f))
            layout?.widthMode == SizeMode.FILL || effectiveAlign == AlignItems.STRETCH -> mod = mod.fillMaxWidth()
        }
    }

    return mod
}
