package com.nativephp.mobile.ui.nativerender

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.isImeVisible
import androidx.compose.foundation.layout.absoluteOffset
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.LayoutCoordinates
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.dp
import com.nativephp.mobile.ui.MaterialIcon
import kotlin.math.abs
import kotlin.math.cos
import kotlin.math.roundToInt
import kotlin.math.sin

/**
 * Raised tabs (`Tab::raised()`): a `NavigationBarItem` drawn as a disc
 * rising out of the bar. `NativeRootTabsRenderer` keeps the item (label,
 * selection, a11y, taps) with a blank icon slot and no pill; the disc is
 * placed from the item's measured position, so it follows insets,
 * rotation and the keyboard. Style props: `raised_*` (PHP `RaisedTab::toProps()`).
 */

/** Space kept above and below the disc when it docks into the bar. */
private val DockInset = 3.dp

/** Where a raised item sits, in px, relative to the box that holds the bar. */
internal data class RaisedTabSlot(
    val centerX: Float,
    /** Top edge of the item — the bar's top edge. */
    val top: Float,
    /** Top edge of the item's label (the item's bottom when it has none). */
    val labelTop: Float,
)

/**
 * Measured positions of the raised items. Coordinates are kept as plain
 * fields (layout callbacks update them every frame the bar moves); only
 * the box-relative [slots] are snapshot state, and they only change when
 * the bar's own layout does, so the bar sliding with the keyboard doesn't
 * recompose the discs.
 */
internal class RaisedTabGeometry {
    val slots = mutableStateMapOf<String, RaisedTabSlot>()

    private var box: LayoutCoordinates? = null
    private val items = HashMap<String, LayoutCoordinates>()
    private val labels = HashMap<String, LayoutCoordinates>()

    fun onBoxPositioned(coordinates: LayoutCoordinates) {
        box = coordinates
        items.keys.toList().forEach(::update)
    }

    fun onItemPositioned(id: String, coordinates: LayoutCoordinates) {
        items[id] = coordinates
        update(id)
    }

    fun onLabelPositioned(id: String, coordinates: LayoutCoordinates) {
        labels[id] = coordinates
        update(id)
    }

    /** Forget items that are no longer raised (or no longer in the bar). */
    fun retain(ids: Set<String>) {
        items.keys.retainAll(ids)
        labels.keys.retainAll(ids)
        slots.keys.filterNot { it in ids }.forEach { slots.remove(it) }
    }

    private fun update(id: String) {
        val box = box?.takeIf { it.isAttached } ?: return
        val item = items[id]?.takeIf { it.isAttached } ?: return
        val bounds = box.localBoundingBoxOf(item, clipBounds = false)
        val label = labels[id]?.takeIf { it.isAttached }
        val labelTop = label?.let { box.localBoundingBoxOf(it, clipBounds = false).top } ?: bounds.bottom

        val slot = RaisedTabSlot(centerX = bounds.center.x, top = bounds.top, labelTop = labelTop)
        if (slots[id] != slot) slots[id] = slot
    }
}

/**
 * The disc for one raised item, drawn in the (unclipped) box that holds
 * the `NavigationBar`, over the item's slot. [interactionSource] is shared
 * with the item, so pressing either the disc or the label under it scales
 * the disc; [onClick] is the item's own tap handler.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun RaisedTabDisc(
    tab: NativeUINode,
    slot: RaisedTabSlot,
    selected: Boolean,
    fallbackFill: Color,
    chromeFontFamily: FontFamily?,
    interactionSource: MutableInteractionSource,
    onClick: () -> Unit,
) {
    val props = tab.props
    val density = LocalDensity.current

    val size = props.getInt("raised_size", 52).dp
    val lift = props.getInt("raised_lift", 20).dp
    val ringWidth = if (props.has("raised_ring_color")) props.getInt("raised_ring_width", 0).dp else 0.dp
    val haloWidth = if (props.has("raised_halo_color")) props.getInt("raised_halo_width", 0).dp else 0.dp
    // The halo's space is always reserved so the disc never shifts when
    // its tab becomes selected.
    val outer = size + (ringWidth + haloWidth) * 2

    val fill = raisedTabFill(props, fallbackFill)
    val ringColor = argbToComposeColor(props.getColor("raised_ring_color", 0))
    val haloColor = argbToComposeColor(props.getColor("raised_halo_color", 0))
    val shadowColor = if (props.has("raised_shadow_color")) {
        argbToComposeColor(props.getColor("raised_shadow_color", 0))
    } else {
        fill.last()
    }
    val iconColor = argbToComposeColor(props.getColor("raised_icon_color", 0xFFFFFFFF.toInt()))
    val icon = props.getString("raised_icon", "").ifEmpty {
        props.getString("icon", "").ifBlank { "add" }
    }
    val badge = props.getString("badge", "")
    val news = props.getBool("news")

    val pressed by interactionSource.collectIsPressedAsState()
    val pressScale by animateFloatAsState(
        targetValue = if (pressed) props.getFloat("raised_press_scale", 0.94f) else 1f,
        animationSpec = tween(durationMillis = 160),
        label = "raised-tab-press",
    )

    // Core lifts the whole bar above the keyboard (root imePadding), which
    // would leave a raised disc sitting over the field being typed into.
    // Unless the tab opted out, the disc settles into the bar while the
    // keyboard is open — scaled to fit between the bar's top edge and the
    // tab's label — and rises again when it closes.
    val docks = props.getBool("raised_dock_with_keyboard", true) && WindowInsets.isImeVisible
    val dock by animateFloatAsState(
        targetValue = if (docks) 1f else 0f,
        animationSpec = tween(durationMillis = 220),
        label = "raised-tab-dock",
    )

    val outerPx = with(density) { outer.toPx() }
    val sizePx = with(density) { size.toPx() }
    val liftPx = with(density) { lift.toPx() }
    val dockInsetPx = with(density) { DockInset.toPx() }

    Box(
        modifier = Modifier
            .absoluteOffset {
                // Top of the fill sits `lift` above the bar's top edge.
                val raisedCenterY = slot.top - liftPx + sizePx / 2
                IntOffset(
                    x = (slot.centerX - outerPx / 2).roundToInt(),
                    y = (raisedCenterY - outerPx / 2).roundToInt(),
                )
            }
            .size(outer)
            .graphicsLayer {
                val raisedCenterY = slot.top - liftPx + sizePx / 2
                val dockedOuter = minOf(outerPx, (slot.labelTop - slot.top - dockInsetPx * 2).coerceAtLeast(1f))
                val dockedCenterY = slot.top + dockInsetPx + dockedOuter / 2
                val docked = 1f + (dockedOuter / outerPx - 1f) * dock
                scaleX = pressScale * docked
                scaleY = pressScale * docked
                translationY = (dockedCenterY - raisedCenterY) * dock
            }
            // The item underneath is the accessible element (label,
            // selected state, activation), so the disc stays out of the
            // screen reader's way instead of announcing the tab twice.
            .clearAndSetSemantics { }
            .clickable(interactionSource = interactionSource, indication = null, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (selected && haloWidth > 0.dp) {
            Box(Modifier.size(outer).drawBehind { drawCircle(haloColor) })
        }

        Box(
            Modifier
                .size(size + ringWidth * 2)
                .then(
                    if (props.getBool("raised_shadow", true)) {
                        Modifier.shadow(
                            elevation = props.getInt("raised_shadow_elevation", 8).dp,
                            shape = CircleShape,
                            clip = false,
                            ambientColor = shadowColor,
                            spotColor = shadowColor,
                        )
                    } else {
                        Modifier
                    }
                )
                .drawBehind { if (ringWidth > 0.dp) drawCircle(ringColor) }
        )

        // A badge on a raised tab would sit under the disc in the item's
        // (blank) icon slot, so the disc carries it instead.
        val disc: @Composable () -> Unit = {
            Box(
                Modifier
                    .size(size)
                    .drawBehind { drawCircle(raisedTabBrush(fill, props, this.size.width)) },
                contentAlignment = Alignment.Center,
            ) {
                MaterialIcon(
                    name = icon,
                    contentDescription = null,
                    size = props.getInt("raised_icon_size", 26).dp,
                    tint = iconColor,
                )
            }
        }
        if (badge.isNotEmpty() || news) {
            BadgedBox(badge = {
                Badge {
                    if (badge.isNotEmpty()) Text(badge, fontFamily = chromeFontFamily)
                }
            }) { disc() }
        } else {
            disc()
        }
    }
}

/** The disc's fill colours: a gradient's two stops, a solid colour, or the fallback. */
private fun raisedTabFill(props: GenericProps, fallback: Color): List<Color> = when {
    props.has("raised_gradient_from") && props.has("raised_gradient_to") -> listOf(
        argbToComposeColor(props.getColor("raised_gradient_from")),
        argbToComposeColor(props.getColor("raised_gradient_to")),
    )
    props.has("raised_color") -> listOf(argbToComposeColor(props.getColor("raised_color")))
    else -> listOf(fallback)
}

/**
 * CSS `linear-gradient()` geometry for a square of side [side]: the
 * gradient line runs through the centre at `raised_gradient_angle` (0 =
 * up, clockwise) and is long enough that the corners get the end colours.
 */
private fun raisedTabBrush(fill: List<Color>, props: GenericProps, side: Float): Brush {
    if (fill.size < 2) return SolidColor(fill.first())

    val radians = Math.toRadians(props.getFloat("raised_gradient_angle", 135f).toDouble())
    val dx = sin(radians).toFloat()
    val dy = -cos(radians).toFloat()
    val half = (abs(side * dx) + abs(side * dy)) / 2
    val center = side / 2

    return Brush.linearGradient(
        colors = fill,
        start = Offset(center - dx * half, center - dy * half),
        end = Offset(center + dx * half, center + dy * half),
    )
}

/** The fill a disc without its own colour uses: the bar's active colour, else the theme primary. */
@Composable
internal fun raisedTabFallbackFill(activeColorArgb: Int): Color =
    if (activeColorArgb != 0) argbToComposeColor(activeColorArgb) else MaterialTheme.colorScheme.primary
