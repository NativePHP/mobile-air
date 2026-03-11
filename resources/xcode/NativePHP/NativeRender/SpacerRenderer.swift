import UIKit

struct SpacerViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let v = UIView()
        v.isUserInteractionEnabled = false
        return v
    }
    func updateView(_ view: UIView, node: NativeUINode) {}
}
