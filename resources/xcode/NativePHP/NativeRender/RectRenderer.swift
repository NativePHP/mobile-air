import UIKit

struct RectViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        // Rect is a styled container — bgColor, borderRadius, border all handled by applyStyle
        return UIView()
    }
    func updateView(_ view: UIView, node: NativeUINode) {}
}
