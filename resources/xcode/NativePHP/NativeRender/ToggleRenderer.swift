import UIKit

struct ToggleViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let p = node.props
        let label = p.getString("label")

        if label.isEmpty {
            let toggle = NativeSwitch()
            applySwitch(toggle, node: node)
            return toggle
        }

        let container = UIView()
        let labelView = UILabel()
        labelView.text = label
        applyLabelColor(labelView, node: node)
        labelView.tag = 1
        container.addSubview(labelView)

        let toggle = NativeSwitch()
        applySwitch(toggle, node: node)
        toggle.tag = 2
        container.addSubview(toggle)

        return container
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        let p = node.props

        if let toggle = view as? NativeSwitch {
            applySwitch(toggle, node: node)
        } else {
            if let labelView = view.viewWithTag(1) as? UILabel {
                labelView.text = p.getString("label")
                applyLabelColor(labelView, node: node)
            }
            if let toggle = view.viewWithTag(2) as? NativeSwitch {
                applySwitch(toggle, node: node)
            }
        }
    }

    private func applyLabelColor(_ label: UILabel, node: NativeUINode) {
        let isDark = UITraitCollection.current.userInterfaceStyle == .dark

        // text-* classes → color prop, dark:text-* → dark_color prop
        let darkColor = isDark ? node.props.getColor("dark_color", default: 0) : 0
        let color = node.props.getColor("color", default: 0)
        let labelColor = node.props.getColor("label_color", default: 0)

        if darkColor != 0 {
            label.textColor = UIColor(argb: darkColor)
        } else if labelColor != 0 {
            label.textColor = UIColor(argb: labelColor)
        } else if color != 0 {
            label.textColor = UIColor(argb: color)
        } else {
            label.textColor = .label
        }
    }

    private func applySwitch(_ toggle: NativeSwitch, node: NativeUINode) {
        let p = node.props
        toggle.nodeId = node.id
        toggle.onChangeCb = p.getCallbackId("on_change")
        toggle.isOn = p.getBool("value")
        toggle.isEnabled = !p.getBool("disabled")

        // bg-* classes → style.bgColor for toggle tint
        if let style = node.style {
            let argb = style.bgColor
            let alpha = (argb >> 24) & 0xFF
            if argb != 0 && alpha != 0 {
                toggle.onTintColor = UIColor(argb: argb)
            }
        }

        toggle.addTarget(toggle, action: #selector(NativeSwitch.valueChanged), for: .valueChanged)
    }
}

class NativeSwitch: UISwitch {
    var nodeId: Int = 0
    var onChangeCb: Int = 0

    @objc func valueChanged() {
        if onChangeCb != 0 {
            NativeElementBridge.sendToggleChangeEvent(onChangeCb, nodeId: nodeId, value: isOn)
        }
    }
}

// Layout the toggle container when frame changes
extension ToggleViewRenderer {
    static func layoutToggleContainer(_ container: UIView) {
        guard let label = container.viewWithTag(1) as? UILabel,
              let toggle = container.viewWithTag(2) as? UISwitch else { return }

        let bounds = container.bounds
        let switchSize = toggle.intrinsicContentSize
        toggle.frame = CGRect(
            x: bounds.width - switchSize.width,
            y: (bounds.height - switchSize.height) / 2,
            width: switchSize.width,
            height: switchSize.height
        )
        label.frame = CGRect(
            x: 0,
            y: 0,
            width: bounds.width - switchSize.width - 8,
            height: bounds.height
        )
    }
}
