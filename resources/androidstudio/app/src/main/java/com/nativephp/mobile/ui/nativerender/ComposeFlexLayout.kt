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
            // Render absolute children on top
            childNodes.forEachIndexed { i, node ->
                if ((node.layout?.positionType ?: 0) == PositionType.ABSOLUTE) {
                    val left = node.layout?.positionLeft ?: 0f
                    val top = node.layout?.positionTop ?: 0f
                    Box(modifier = Modifier.offset(x = left.dp, y = top.dp)) {
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

            val childMod = buildChildModifier(node, isRow = false, align = align, scope = this, childNodes = childNodes)
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

            val childMod = buildChildModifier(node, isRow = true, align = align, scope = this, childNodes = childNodes)
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
    scope: Any, // ColumnScope or RowScope
    childNodes: List<NativeUINode> = emptyList()
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
    val flexShrink = layout?.flexShrink ?: 0f
    val mainFill = if (isRow) {
        layout?.widthMode == SizeMode.FILL
    } else {
        layout?.heightMode == SizeMode.FILL
    }
    val hasFixedMainSize = if (isRow) {
        layout?.widthMode == SizeMode.FIXED && (layout.width) > 0f
    } else {
        layout?.heightMode == SizeMode.FIXED && (layout.height) > 0f
    }

    if (flexGrow > 0f || mainFill == true) {
        val weight = if (flexGrow > 0f) flexGrow else 1f
        mod = if (isRow && scope is RowScope) {
            with(scope) { mod.weight(weight) }
        } else if (!isRow && scope is ColumnScope) {
            with(scope) { mod.weight(weight) }
        } else mod
    } else if (hasFixedMainSize == true) {
        // Fixed size on main axis
        if (isRow) mod = mod.width(layout!!.width.dp)
        else mod = mod.height(layout!!.height.dp)
    } else if (isRow && scope is RowScope) {
        // In a Row, children without explicit size or flex_grow should share space equally
        // to prevent overflow. CSS flex-shrink defaults to 1.
        // Use weight(1f, fill=false) so they share space but don't grow beyond natural size.
        // Actually, just constrain with weight to prevent overflow.
        val siblingCount = childNodes.count { n ->
            (n.layout?.display ?: 0) != Display.NONE &&
            (n.layout?.positionType ?: 0) != PositionType.ABSOLUTE
        }
        if (siblingCount > 1 && flexShrink != 0f) {
            // Only apply shrink behavior if there are multiple siblings
            with(scope) { mod = mod.weight(1f, fill = false) }
        }
    }

    // Cross axis: stretch or fixed
    val effectiveAlign = if ((layout?.alignSelf ?: 0) > 0) layout!!.alignSelf else align
    if (isRow) {
        when {
            layout?.heightMode == SizeMode.FIXED && (layout.height) > 0f -> mod = mod.height(layout.height.dp)
            layout?.heightMode == SizeMode.FILL || effectiveAlign == AlignItems.STRETCH -> mod = mod.fillMaxHeight()
        }
    } else {
        when {
            layout?.widthMode == SizeMode.FIXED && (layout.width) > 0f -> mod = mod.width(layout.width.dp)
            layout?.widthMode == SizeMode.FILL || effectiveAlign == AlignItems.STRETCH -> mod = mod.fillMaxWidth()
        }
    }

    return mod
}
