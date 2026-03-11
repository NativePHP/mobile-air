import UIKit

struct ToggleViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let p = node.props
        let label = p.getString("label")

        if label.isEmpty {
            let toggle = NativeSwitch()
            toggle.nodeId = node.id
            toggle.onChangeCb = p.getCallbackId("on_change")
            toggle.isOn = p.getBool("value")
            toggle.isEnabled = !p.getBool("disabled")
            toggle.addTarget(toggle, action: #selector(NativeSwitch.valueChanged), for: .valueChanged)
            return toggle
        }

        // Toggle with label — horizontal container
        let container = UIView()
        let labelView = UILabel()
        labelView.text = label
        labelView.tag = 1
        container.addSubview(labelView)

        let toggle = NativeSwitch()
        toggle.nodeId = node.id
        toggle.onChangeCb = p.getCallbackId("on_change")
        toggle.isOn = p.getBool("value")
        toggle.isEnabled = !p.getBool("disabled")
        toggle.addTarget(toggle, action: #selector(NativeSwitch.valueChanged), for: .valueChanged)
        toggle.tag = 2
        container.addSubview(toggle)

        return container
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        let p = node.props

        if let toggle = view as? NativeSwitch {
            toggle.nodeId = node.id
            toggle.onChangeCb = p.getCallbackId("on_change")
            toggle.isOn = p.getBool("value")
            toggle.isEnabled = !p.getBool("disabled")
        } else {
            // Container with label + switch
            if let labelView = view.viewWithTag(1) as? UILabel {
                labelView.text = p.getString("label")
            }
            if let toggle = view.viewWithTag(2) as? NativeSwitch {
                toggle.nodeId = node.id
                toggle.onChangeCb = p.getCallbackId("on_change")
                toggle.isOn = p.getBool("value")
                toggle.isEnabled = !p.getBool("disabled")
            }
        }
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
    // Called externally by the renderer after frame is set, since we use manual layout
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
