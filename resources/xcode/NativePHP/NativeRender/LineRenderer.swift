import SwiftUI

struct RenderLine: View {
    let node: NativeUINode

    var body: some View {
        let p = node.props
        let fromX = CGFloat(p.getFloat("from_x"))
        let fromY = CGFloat(p.getFloat("from_y"))
        let toX = CGFloat(p.getFloat("to_x"))
        let toY = CGFloat(p.getFloat("to_y"))

        let style = node.style
        let strokeColor = (style != nil && style!.borderColor != 0)
            ? Color(argb: style!.borderColor)
            : Color.black
        let strokeWidth = (style != nil && style!.borderWidth > 0)
            ? CGFloat(style!.borderWidth)
            : 1.0

        // Use a Path shape instead of SwiftUI Canvas to avoid being greedy.
        // The line is positioned absolutely by the parent canvas offset.
        LinePath(from: CGPoint(x: fromX, y: fromY), to: CGPoint(x: toX, y: toY))
            .stroke(strokeColor, lineWidth: strokeWidth)
            .allowsHitTesting(false)
    }
}

private struct LinePath: Shape {
    let from: CGPoint
    let to: CGPoint

    func path(in rect: CGRect) -> Path {
        var p = Path()
        p.move(to: from)
        p.addLine(to: to)
        return p
    }
}
