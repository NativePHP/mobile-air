import UIKit

struct CircleViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        // Circle uses borderRadius=9999 from PHP — applyStyle handles the clipping
        return UIView()
    }
    func updateView(_ view: UIView, node: NativeUINode) {}
}
