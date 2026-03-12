import UIKit

struct ButtonViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let label = UILabel()
        label.textAlignment = .center
        applyProps(label, node: node)
        return label
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        guard let label = view as? UILabel else { return }
        applyProps(label, node: node)
    }

    private func applyProps(_ label: UILabel, node: NativeUINode) {
        let p = node.props
        label.text = p.getString("label")

        let fontSize = p.getFloat("font_size")
        label.font = .systemFont(
            ofSize: fontSize > 0 ? CGFloat(fontSize) : 16,
            weight: .medium
        )

        let labelColor = p.getColor("label_color", default: 0)
        let propColor = p.getColor("color", default: 0)
        let effectiveColor = labelColor != 0 ? labelColor : (propColor != 0 ? propColor : 0)
        label.textColor = effectiveColor != 0 ? UIColor(argb: effectiveColor) : .label

        let disabled = p.getBool("disabled")
        label.alpha = disabled ? 0.5 : 1.0
    }
}
