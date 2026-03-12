import UIKit

struct TextInputViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let tf = NativeTextField()
        tf.borderStyle = .roundedRect
        tf.nodeId = node.id
        applyProps(tf, node: node)
        tf.addTarget(tf, action: #selector(NativeTextField.textDidChange), for: .editingChanged)
        return tf
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        guard let tf = view as? NativeTextField else { return }
        // Don't overwrite text if user is actively editing
        if !tf.isFirstResponder {
            tf.text = node.props.getString("value")
        }
        tf.nodeId = node.id
        applyProps(tf, node: node)
    }

    private func applyProps(_ tf: NativeTextField, node: NativeUINode) {
        let p = node.props
        tf.placeholder = p.getString("placeholder")
        tf.isSecureTextEntry = p.getBool("secure")
        tf.onChangeCb = p.getCallbackId("on_change")
        tf.onSubmitCb = p.getCallbackId("on_submit")

        // Set initial value only on first setup
        if tf.text?.isEmpty ?? true {
            tf.text = p.getString("value")
        }
    }
}

class NativeTextField: UITextField, UITextFieldDelegate {
    var nodeId: Int = 0
    var onChangeCb: Int = 0
    var onSubmitCb: Int = 0

    override init(frame: CGRect) {
        super.init(frame: frame)
        delegate = self
    }

    required init?(coder: NSCoder) { fatalError() }

    @objc func textDidChange() {
        if onChangeCb != 0, let text = text {
            NativeElementBridge.sendTextChangeEvent(onChangeCb, nodeId: nodeId, text: text)
        }
    }

    func textFieldShouldReturn(_ textField: UITextField) -> Bool {
        if onSubmitCb != 0, let text = text {
            NativeElementBridge.sendSubmitEvent(onSubmitCb, nodeId: nodeId, text: text)
        }
        textField.resignFirstResponder()
        return true
    }
}
