import UIKit

struct LineViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let v = LineView()
        applyProps(v, node: node)
        return v
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        guard let v = view as? LineView else { return }
        applyProps(v, node: node)
        v.setNeedsDisplay()
    }

    private func applyProps(_ v: LineView, node: NativeUINode) {
        let p = node.props
        v.fromPoint = CGPoint(x: CGFloat(p.getFloat("from_x")), y: CGFloat(p.getFloat("from_y")))
        v.toPoint = CGPoint(x: CGFloat(p.getFloat("to_x")), y: CGFloat(p.getFloat("to_y")))
        v.strokeColor = (node.style?.borderColor != 0)
            ? UIColor(argb: node.style!.borderColor)
            : .black
        v.strokeWidth = (node.style?.borderWidth ?? 0) > 0
            ? CGFloat(node.style!.borderWidth)
            : 1.0
        v.backgroundColor = .clear
        v.isOpaque = false
        v.isUserInteractionEnabled = false
    }
}

private class LineView: UIView {
    var fromPoint: CGPoint = .zero
    var toPoint: CGPoint = .zero
    var strokeColor: UIColor = .black
    var strokeWidth: CGFloat = 1.0

    override func draw(_ rect: CGRect) {
        guard let ctx = UIGraphicsGetCurrentContext() else { return }
        ctx.setStrokeColor(strokeColor.cgColor)
        ctx.setLineWidth(strokeWidth)
        ctx.move(to: fromPoint)
        ctx.addLine(to: toPoint)
        ctx.strokePath()
    }
}
