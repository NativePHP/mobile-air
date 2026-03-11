import UIKit

struct ActivityIndicatorViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let style: UIActivityIndicatorView.Style
        switch node.props.getInt("size") {
        case 1: style = .large
        case 2: style = .medium
        default: style = .medium
        }

        let indicator = UIActivityIndicatorView(style: style)
        indicator.hidesWhenStopped = false
        indicator.startAnimating()

        let color = node.props.getColor("color", default: 0)
        if color != 0 {
            indicator.color = UIColor(argb: color)
        }

        return indicator
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        guard let indicator = view as? UIActivityIndicatorView else { return }
        let color = node.props.getColor("color", default: 0)
        if color != 0 {
            indicator.color = UIColor(argb: color)
        }
        if !indicator.isAnimating {
            indicator.startAnimating()
        }
    }
}
