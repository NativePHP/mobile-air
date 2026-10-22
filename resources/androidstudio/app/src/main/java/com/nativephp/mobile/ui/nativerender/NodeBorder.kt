package com.nativephp.mobile.ui.nativerender

import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawWithCache
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.drawscope.Stroke
import kotlin.math.abs
import kotlin.math.cos
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt
import kotlin.math.sin
import kotlin.math.tan

// MARK: - Node Border

/**
 * A border that `Modifier.border` can't draw: dashed or dotted
 * (`border-dashed`, `border-dotted`), or on some sides only (`border-l`,
 * `border-y-2`, …), or both, as in a thread's elbow:
 * `border-l border-b rounded-bl-xl border-dashed`.
 *
 * PHP sends these on the props bag beside the per-corner radii, since the
 * packed node carries one `border_width` and no style. `border_style` is
 * 0 solid, 1 dashed, 2 dotted. `border_t` / `_r` / `_b` / `_l` are the four
 * widths, all present whenever any side was authored, each already resolved
 * against the uniform width. A solid border of one width on every side is
 * not one of these and keeps `Modifier.border`.
 *
 * Like `Modifier.border`, everything is drawn inside the bounds, and all the
 * lengths come from layout: a 1dp-wide view with `border-l border-dashed` is
 * a dashed line however tall its row turns out to be. Mirrors iOS's
 * NodeBorder.swift.
 */
class NodeBorder private constructor(
    /** Side widths in dp, clockwise from the top. */
    private val widths: FloatArray,
    /** Outer corner radii in dp, clockwise from the top left, as authored. */
    private val radii: FloatArray,
    /** 0 solid, 1 dashed, 2 dotted. */
    private val style: Int,
) {
    class Run(val path: Path, val stroke: Stroke)

    companion object {
        fun from(props: GenericProps, width: Float, radius: Float): NodeBorder? {
            val style = props.getInt("border_style", 0)
            val sided = props.has("border_t")
            if (style == 0 && !sided) return null

            val widths = if (sided) {
                floatArrayOf(
                    props.getFloat("border_t", 0f),
                    props.getFloat("border_r", 0f),
                    props.getFloat("border_b", 0f),
                    props.getFloat("border_l", 0f),
                )
            } else {
                FloatArray(4) { width }
            }
            val radii = if (props.has("radius_tl")) {
                floatArrayOf(
                    props.getFloat("radius_tl", 0f),
                    props.getFloat("radius_tr", 0f),
                    props.getFloat("radius_br", 0f),
                    props.getFloat("radius_bl", 0f),
                )
            } else {
                FloatArray(4) { radius }
            }

            return NodeBorder(widths, radii, style)
        }

        /**
         * Dash and gap for a line [width] thick: 4 and 3 at 1, 5 and 4 at 2,
         * growing with the width. Browsers differ (Chromium's depend on the
         * screen's scale), so these are picked to read like them on a phone.
         * Dots are round, as wide as the line, a width apart.
         */
        fun pattern(width: Float, style: Int): Pair<Float, Float> =
            if (style == 2) 0f to width * 2 else (width + 3f) to (width + 2f)
    }

    /**
     * The strokes for a box of [size] px at [density] px per dp.
     *
     * Each side is stroked along its middle line, inset by half its width,
     * with each corner's curve split between the two sides that meet there.
     * Touching sides of one width are a single path, so dashes run on round a
     * corner unbroken; four of them make a closed path whose dashes are spaced
     * to come out even all the way round, as browsers do. An open run is drawn
     * from its top (or leftmost) end, starting on a dash, or with a dash
     * centred on its first corner, so an elbow's bend reads the same from
     * either side.
     */
    fun runs(size: Size, density: Float): List<Run> {
        if (size.width <= 0f || size.height <= 0f) return emptyList()

        val w = FloatArray(4) { widths[it] * density }
        val geometry = BorderGeometry(size, w, FloatArray(4) { radii[it] * density })
        val on = BooleanArray(4) { w[it] > 0f }

        if (on.all { it } && w.toSet().size == 1) {
            return listOf(stroke(geometry.closedRun(), w[0], density, closed = true))
        }

        val runs = mutableListOf<Run>()
        for (start in 0 until 4) {
            if (!on[start]) continue
            val previous = (start + 3) % 4
            if (on[previous] && w[previous] == w[start]) continue

            val sides = mutableListOf(start)
            while (sides.size < 4) {
                val next = (sides.last() + 1) % 4
                if (!on[next] || w[next] != w[start]) break
                sides.add(next)
            }
            runs.add(stroke(geometry.openRun(sides), w[start], density, closed = false))
        }

        return runs
    }

    private fun stroke(run: BorderGeometry.Segments, width: Float, density: Float, closed: Boolean): Run {
        val cap = if (style == 2) StrokeCap.Round else StrokeCap.Butt
        val join = if (style == 2) StrokeJoin.Round else StrokeJoin.Miter
        val path = run.path(closed)

        if (style == 0) return Run(path, Stroke(width = width, cap = cap, join = join))

        val (dashDp, gapDp) = pattern(width / density, style)
        val dash = dashDp * density
        var spacing = gapDp * density
        val length = run.length

        if (closed) {
            // Whole dashes all the way round, so there's no seam where the path starts.
            val count = max(1, (length / (dash + spacing)).roundToInt())
            spacing = length / count - dash
            if (spacing <= 0f) return Run(path, Stroke(width = width, cap = cap, join = join))
        }

        val period = dash + spacing
        val centre = if (closed) 0f else (run.firstCorner ?: (dash / 2))
        val phase = ((dash / 2 - centre) % period).let { if (it < 0f) it + period else it }

        // Skia draws a zero-length dash as a round dot only on some versions;
        // a hair of length is a dot everywhere.
        val on = max(dash, 0.01f)

        return Run(
            path,
            Stroke(
                width = width,
                cap = cap,
                join = join,
                pathEffect = PathEffect.dashPathEffect(floatArrayOf(on, spacing), phase),
            ),
        )
    }
}

/** Draws a [NodeBorder] over the content, as `Modifier.border` does. */
fun Modifier.nodeBorder(border: NodeBorder, color: Color): Modifier = drawWithCache {
    val runs = border.runs(size, density)
    onDrawWithContent {
        drawContent()
        runs.forEach { drawPath(it.path, color, style = it.stroke) }
    }
}

// MARK: - Geometry

/**
 * The middle lines of a box's four sides and the corner curves between them,
 * after CSS's corner-radius clamp. All in px.
 */
private class BorderGeometry(size: Size, w: FloatArray, radii: FloatArray) {
    sealed class Segment {
        abstract val start: Offset
        abstract val end: Offset
        abstract fun reversed(): Segment
        abstract val length: Float

        class Line(override val start: Offset, override val end: Offset) : Segment() {
            override fun reversed() = Line(end, start)
            override val length get() = (end - start).getDistance()
        }

        class Curve(override val start: Offset, val c1: Offset, val c2: Offset, override val end: Offset) : Segment() {
            override fun reversed() = Curve(end, c2, c1, start)
            override val length: Float
                get() {
                    var total = 0f
                    var previous = start
                    for (step in 1..16) {
                        val t = step / 16f
                        val u = 1 - t
                        val point = Offset(
                            u * u * u * start.x + 3 * u * u * t * c1.x + 3 * u * t * t * c2.x + t * t * t * end.x,
                            u * u * u * start.y + 3 * u * u * t * c1.y + 3 * u * t * t * c2.y + t * t * t * end.y,
                        )
                        total += (point - previous).getDistance()
                        previous = point
                    }
                    return total
                }
        }
    }

    /** A run's segments end to end, and how far along it its first corner's middle is. */
    class Segments(var segments: List<Segment> = emptyList(), var firstCorner: Float? = null) {
        val length: Float get() = segments.fold(0f) { total, segment -> total + segment.length }

        fun path(closed: Boolean): Path = Path().apply {
            val first = segments.firstOrNull() ?: return@apply
            moveTo(first.start.x, first.start.y)
            for (segment in segments) {
                when (segment) {
                    is Segment.Line -> lineTo(segment.end.x, segment.end.y)
                    is Segment.Curve -> cubicTo(segment.c1.x, segment.c1.y, segment.c2.x, segment.c2.y, segment.end.x, segment.end.y)
                }
            }
            if (closed) close()
        }
    }

    private class Corner(val centre: Offset, val rx: Float, val ry: Float, val from: Float)

    /**
     * Each side's segments, clockwise from the top: the second half of the
     * corner before it, its straight edge, the first half of the corner after.
     */
    private val sides: List<List<Segment>>

    /** Whether each corner, clockwise from the top left, has a curve. */
    private val curved: BooleanArray

    init {
        // CSS shrinks every radius by the same factor when two on one side
        // add up to more than it, which is what makes `rounded-full` a circle.
        val r = FloatArray(4) { max(0f, radii[it]) }
        var fit = 1f
        for ((length, sum) in listOf(
            size.width to r[0] + r[1],
            size.height to r[1] + r[2],
            size.width to r[2] + r[3],
            size.height to r[3] + r[0],
        )) {
            if (sum > 0f) fit = min(fit, length / sum)
        }
        for (i in 0 until 4) r[i] *= fit

        val left = w[3] / 2
        val top = w[0] / 2
        val right = max(left, size.width - w[1] / 2)
        val bottom = max(top, size.height - w[2] / 2)

        // Each corner's curve on the middle lines: its centre, radii, and the
        // angle it starts from, going clockwise (y grows downward).
        val corners = List(4) { corner ->
            val vertical = if (corner == 0 || corner == 3) w[3] else w[1]
            val horizontal = if (corner < 2) w[0] else w[2]
            val rx = max(0f, r[corner] - vertical / 2)
            val ry = max(0f, r[corner] - horizontal / 2)
            val centre = when (corner) {
                0 -> Offset(left + rx, top + ry)
                1 -> Offset(right - rx, top + ry)
                2 -> Offset(right - rx, bottom - ry)
                else -> Offset(left + rx, bottom - ry)
            }
            Corner(centre, rx, ry, (Math.PI * (1 + corner / 2.0)).toFloat())
        }

        fun point(corner: Int, angle: Float): Offset {
            val c = corners[corner]
            return Offset(c.centre.x + c.rx * cos(angle), c.centre.y + c.ry * sin(angle))
        }

        // An eighth of an ellipse as a cubic.
        fun half(corner: Int, second: Boolean): Segment? {
            val c = corners[corner]
            if (c.rx <= 0f && c.ry <= 0f) return null
            val eighth = (Math.PI / 4).toFloat()
            val a = c.from + if (second) eighth else 0f
            val b = a + eighth
            val k = 4f / 3f * tan((b - a) / 4)
            val p0 = point(corner, a)
            val p3 = point(corner, b)
            return Segment.Curve(
                p0,
                Offset(p0.x - k * c.rx * sin(a), p0.y + k * c.ry * cos(a)),
                Offset(p3.x + k * c.rx * sin(b), p3.y - k * c.ry * cos(b)),
                p3,
            )
        }

        curved = BooleanArray(4) { corners[it].rx > 0f || corners[it].ry > 0f }
        sides = List(4) { side ->
            val next = (side + 1) % 4
            val edge = Segment.Line(point(side, corners[side].from + (Math.PI / 2).toFloat()), point(next, corners[next].from))
            listOfNotNull(half(side, second = true), edge, half(next, second = false))
        }
    }

    /** All four sides, starting from the middle of the top-left corner. */
    fun closedRun(): Segments = Segments(sides.flatten())

    /**
     * Touching sides, clockwise, turned round if need be to start from the end
     * nearest the top, then the left.
     */
    fun openRun(indices: List<Int>): Segments {
        val run = Segments()
        val segments = mutableListOf<Segment>()
        var corners = mutableListOf<Float>()

        indices.forEachIndexed { position, side ->
            segments += sides[side]
            if (position < indices.size - 1 && curved[(side + 1) % 4]) {
                corners.add(Segments(segments.toList()).length)
            }
        }
        run.segments = segments

        val start = segments.firstOrNull()?.start ?: return run
        val end = segments.last().end

        if (end.y < start.y - 0.001f || (abs(end.y - start.y) <= 0.001f && end.x < start.x)) {
            val length = run.length
            run.segments = segments.reversed().map { it.reversed() }
            corners = corners.map { length - it }.reversed().toMutableList()
        }

        run.firstCorner = corners.firstOrNull()
        return run
    }
}
