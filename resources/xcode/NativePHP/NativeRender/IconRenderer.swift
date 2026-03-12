import UIKit

struct IconViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let iv = UIImageView()
        iv.contentMode = .scaleAspectFit
        applyProps(iv, node: node)
        return iv
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        guard let iv = view as? UIImageView else { return }
        applyProps(iv, node: node)
    }

    private func applyProps(_ iv: UIImageView, node: NativeUINode) {
        let p = node.props
        let name = p.getString("name")
        let size = CGFloat(p.getFloat("size", default: 24))
        let color = p.getColor("color", default: 0)

        let sfName = getIconForName(name)
        let config = UIImage.SymbolConfiguration(pointSize: size, weight: .regular)
        let img = UIImage(systemName: sfName, withConfiguration: config)

        if color != 0 {
            iv.tintColor = UIColor(argb: color)
            iv.image = img?.withRenderingMode(.alwaysTemplate)
        } else {
            iv.image = img
        }
    }
}
