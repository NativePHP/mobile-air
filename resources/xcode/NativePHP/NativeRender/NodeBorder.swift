import SwiftUI

// MARK: - Node Border

/// A border that `strokeBorder` can't draw: dashed or dotted
/// (`border-dashed`, `border-dotted`), or on some sides only (`border-l`,
/// `border-y-2`, …), or both, as in a thread's elbow:
/// `border-l border-b rounded-bl-xl border-dashed`.
///
/// PHP sends these on the props bag beside the per-corner radii, since the
/// packed node carries one `border_width` and no style. `border_style` is
/// 0 solid, 1 dashed, 2 dotted. `border_t` / `_r` / `_b` / `_l` are the four
/// widths, all present whenever any side was authored, each already resolved
/// against the uniform width. A solid border of one width on every side is
/// not one of these and keeps the `strokeBorder` path.
///
/// Like `strokeBorder`, everything is drawn inside the bounds, and all the
/// lengths come from layout: a 1pt-wide view with `border-l border-dashed`
/// is a dashed line however tall its row turns out to be.
struct NodeBorder: Equatable {
    /// Side widths in points, clockwise from the top.
    let widths: [CGFloat]
    /// Outer corner radii, clockwise from the top left, as authored.
    let radii: [CGFloat]
    /// 0 solid, 1 dashed, 2 dotted.
    let style: Int

    init?(props: GenericProps, width: CGFloat, radius: CGFloat, radii cornerRadii: RectangleCornerRadii?) {
        let style = props.getInt("border_style", default: 0)
        let sided = props.has("border_t")
        guard style != 0 || sided else { return nil }

        self.style = style
        widths = sided
            ? ["border_t", "border_r", "border_b", "border_l"].map { CGFloat(props.getFloat($0, default: 0)) }
            : Array(repeating: width, count: 4)
        radii = cornerRadii.map { [$0.topLeading, $0.topTrailing, $0.bottomTrailing, $0.bottomLeading] }
            ?? Array(repeating: radius, count: 4)
    }

    /// Dash and gap for a line `width` thick: 4pt and 3pt at 1pt, 5pt and 4pt
    /// at 2pt, growing with the width. Browsers differ (Chromium's depend on
    /// the screen's scale), so these are picked to read like them on a phone.
    /// Dots are round, as wide as the line, a width apart.
    static func pattern(width: CGFloat, style: Int) -> (dash: CGFloat, gap: CGFloat) {
        style == 2 ? (0, width * 2) : (width + 3, width + 2)
    }

    /// One stroke: a run of touching sides of the same width.
    struct Run {
        let path: Path
        let style: StrokeStyle
    }

    /// The strokes for a box of `rect`, in its own coordinates.
    ///
    /// Each side is stroked along its middle line, inset by half its width,
    /// with each corner's curve split between the two sides that meet there.
    /// Touching sides of one width are a single path, so dashes run on round
    /// a corner unbroken; four of them make a closed path whose dashes are
    /// spaced to come out even all the way round, as browsers do. An open
    /// run is drawn from its top (or leftmost) end, starting on a dash, or
    /// with a dash centred on its first corner, so an elbow's bend reads the
    /// same from either side.
    func runs(in rect: CGRect) -> [Run] {
        guard rect.width > 0, rect.height > 0 else { return [] }

        let geometry = BorderGeometry(rect: rect, widths: widths, radii: radii)
        let on = widths.map { $0 > 0 }

        if on.allSatisfy({ $0 }), Set(widths).count == 1 {
            return [stroke(geometry.closedRun(), width: widths[0], closed: true)]
        }

        var runs: [Run] = []
        for start in 0..<4 where on[start] {
            let previous = (start + 3) % 4
            if on[previous], widths[previous] == widths[start] { continue }

            var sides = [start]
            while sides.count < 4 {
                let next = (sides.last! + 1) % 4
                guard on[next], widths[next] == widths[start] else { break }
                sides.append(next)
            }
            runs.append(stroke(geometry.openRun(sides: sides), width: widths[start], closed: false))
        }

        return runs
    }

    private func stroke(_ run: BorderGeometry.Segments, width: CGFloat, closed: Bool) -> Run {
        let (dash, gap) = Self.pattern(width: width, style: style)
        let cap: CGLineCap = style == 2 ? .round : .butt
        let join: CGLineJoin = style == 2 ? .round : .miter

        guard style != 0 else {
            return Run(path: run.path(closed: closed), style: StrokeStyle(lineWidth: width, lineCap: cap, lineJoin: join))
        }

        let length = run.length
        var spacing = gap

        if closed {
            // Whole dashes all the way round, so there's no seam where the path starts.
            let count = max(1, (length / (dash + gap)).rounded())
            spacing = length / count - dash
            guard spacing > 0 else {
                return Run(path: run.path(closed: true), style: StrokeStyle(lineWidth: width, lineCap: cap, lineJoin: join))
            }
        }

        let period = dash + spacing
        let centre = closed ? 0 : (run.firstCorner ?? dash / 2)
        let phase = (dash / 2 - centre).truncatingRemainder(dividingBy: period)

        return Run(
            path: run.path(closed: closed),
            style: StrokeStyle(lineWidth: width, lineCap: cap, lineJoin: join, dash: [dash, spacing], dashPhase: phase < 0 ? phase + period : phase)
        )
    }
}

/// Draws a [NodeBorder] by filling the outline of its strokes, so the whole
/// border is one shape whatever its runs.
struct NodeBorderShape: Shape {
    let border: NodeBorder

    func path(in rect: CGRect) -> Path {
        var path = Path()
        for run in border.runs(in: rect) {
            path.addPath(run.path.strokedPath(run.style))
        }
        return path
    }
}

// MARK: - Geometry

/// The middle lines of a box's four sides and the corner curves between
/// them, after CSS's corner-radius clamp.
private struct BorderGeometry {
    enum Segment {
        case line(CGPoint, CGPoint)
        case curve(CGPoint, CGPoint, CGPoint, CGPoint)

        var start: CGPoint {
            switch self {
            case let .line(a, _), let .curve(a, _, _, _): a
            }
        }

        var end: CGPoint {
            switch self {
            case let .line(_, b), let .curve(_, _, _, b): b
            }
        }

        var reversed: Segment {
            switch self {
            case let .line(a, b): .line(b, a)
            case let .curve(a, c1, c2, b): .curve(b, c2, c1, a)
            }
        }

        var length: CGFloat {
            switch self {
            case let .line(a, b):
                return hypot(b.x - a.x, b.y - a.y)
            case let .curve(a, c1, c2, b):
                var total: CGFloat = 0
                var previous = a
                for step in 1...16 {
                    let t = CGFloat(step) / 16, u = 1 - t
                    let point = CGPoint(
                        x: u * u * u * a.x + 3 * u * u * t * c1.x + 3 * u * t * t * c2.x + t * t * t * b.x,
                        y: u * u * u * a.y + 3 * u * u * t * c1.y + 3 * u * t * t * c2.y + t * t * t * b.y
                    )
                    total += hypot(point.x - previous.x, point.y - previous.y)
                    previous = point
                }
                return total
            }
        }
    }

    /// A run's segments end to end, and how far along it its first corner's
    /// middle is.
    struct Segments {
        var segments: [Segment] = []
        var firstCorner: CGFloat?

        var length: CGFloat { segments.reduce(0) { $0 + $1.length } }

        func path(closed: Bool) -> Path {
            var path = Path()
            guard let first = segments.first else { return path }
            path.move(to: first.start)
            for segment in segments {
                switch segment {
                case let .line(_, b): path.addLine(to: b)
                case let .curve(_, c1, c2, b): path.addCurve(to: b, control1: c1, control2: c2)
                }
            }
            if closed { path.closeSubpath() }
            return path
        }
    }

    /// Each side's segments, clockwise from the top: the second half of the
    /// corner before it, its straight edge, the first half of the corner after.
    private var sides: [[Segment]] = []
    /// Whether each corner, clockwise from the top left, has a curve.
    private var curved: [Bool] = []

    init(rect: CGRect, widths w: [CGFloat], radii: [CGFloat]) {
        // CSS shrinks every radius by the same factor when two on one side
        // add up to more than it, which is what makes `rounded-full` a circle.
        var r = radii.map { max(0, $0) }
        let fit = [(rect.width, r[0] + r[1]), (rect.height, r[1] + r[2]), (rect.width, r[2] + r[3]), (rect.height, r[3] + r[0])]
            .reduce(CGFloat(1)) { scale, side in side.1 > 0 ? min(scale, side.0 / side.1) : scale }
        r = r.map { $0 * fit }

        let inner = CGRect(
            x: rect.minX + w[3] / 2,
            y: rect.minY + w[0] / 2,
            width: max(0, rect.width - (w[1] + w[3]) / 2),
            height: max(0, rect.height - (w[0] + w[2]) / 2)
        )

        // Each corner's curve on the middle lines: its centre, radii, and the
        // angle it starts from, going clockwise (y grows downward).
        let corners: [(centre: CGPoint, rx: CGFloat, ry: CGFloat, from: CGFloat)] = (0..<4).map { corner in
            let vertical = corner == 0 || corner == 3 ? w[3] : w[1]
            let horizontal = corner < 2 ? w[0] : w[2]
            let rx = max(0, r[corner] - vertical / 2)
            let ry = max(0, r[corner] - horizontal / 2)
            let centre = switch corner {
            case 0: CGPoint(x: inner.minX + rx, y: inner.minY + ry)
            case 1: CGPoint(x: inner.maxX - rx, y: inner.minY + ry)
            case 2: CGPoint(x: inner.maxX - rx, y: inner.maxY - ry)
            default: CGPoint(x: inner.minX + rx, y: inner.maxY - ry)
            }
            return (centre, rx, ry, CGFloat.pi * (1 + CGFloat(corner) / 2))
        }

        func point(_ corner: Int, _ angle: CGFloat) -> CGPoint {
            let c = corners[corner]
            return CGPoint(x: c.centre.x + c.rx * cos(angle), y: c.centre.y + c.ry * sin(angle))
        }

        // An eighth of an ellipse as a cubic.
        func half(_ corner: Int, second: Bool) -> Segment? {
            let c = corners[corner]
            guard c.rx > 0 || c.ry > 0 else { return nil }
            let a = c.from + (second ? .pi / 4 : 0), b = a + .pi / 4
            let k = 4 / 3 * tan((b - a) / 4)
            let p0 = point(corner, a), p3 = point(corner, b)
            return .curve(
                p0,
                CGPoint(x: p0.x - k * c.rx * sin(a), y: p0.y + k * c.ry * cos(a)),
                CGPoint(x: p3.x + k * c.rx * sin(b), y: p3.y - k * c.ry * cos(b)),
                p3
            )
        }

        curved = corners.map { $0.rx > 0 || $0.ry > 0 }
        sides = (0..<4).map { side in
            let next = (side + 1) % 4
            let edge = Segment.line(point(side, corners[side].from + .pi / 2), point(next, corners[next].from))
            return [half(side, second: true), edge, half(next, second: false)].compactMap { $0 }
        }
    }

    /// All four sides, starting from the middle of the top-left corner.
    func closedRun() -> Segments {
        Segments(segments: sides.flatMap { $0 })
    }

    /// Touching sides, clockwise, turned round if need be to start from the
    /// end nearest the top, then the left.
    func openRun(sides indices: [Int]) -> Segments {
        var run = Segments()
        var corners: [CGFloat] = []

        for (position, side) in indices.enumerated() {
            run.segments += sides[side]
            if position < indices.count - 1, curved[(side + 1) % 4] {
                corners.append(run.length)
            }
        }

        guard let start = run.segments.first?.start, let end = run.segments.last?.end else { return run }

        if end.y < start.y - 0.001 || (abs(end.y - start.y) <= 0.001 && end.x < start.x) {
            let length = run.length
            run.segments = run.segments.reversed().map(\.reversed)
            corners = corners.map { length - $0 }.reversed()
        }

        run.firstCorner = corners.first
        return run
    }
}
