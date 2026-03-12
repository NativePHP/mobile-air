import UIKit

struct DividerViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let v = UIView()
        applyProps(v, node: node)
        return v
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        applyProps(view, node: node)
    }

    private func applyProps(_ view: UIView, node: NativeUINode) {
        if let style = node.style, style.borderColor != 0 {
            view.backgroundColor = UIColor(argb: style.borderColor)
        } else {
            view.backgroundColor = UIColor.separator
        }
    }
}
