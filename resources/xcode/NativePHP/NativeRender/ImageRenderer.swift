import UIKit

struct ImageViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let iv = UIImageView()
        iv.clipsToBounds = true
        applyProps(iv, node: node)
        return iv
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        guard let iv = view as? UIImageView else { return }
        applyProps(iv, node: node)
    }

    private func applyProps(_ iv: UIImageView, node: NativeUINode) {
        let p = node.props
        let src = p.getString("src")
        let fit = p.getInt("fit")
        let tintColor = p.getColor("tint_color", default: 0)

        // Content mode from fit: 0=None, 1=Fit, 2=Crop, 3=FillBounds, 4=Inside
        switch fit {
        case 0: iv.contentMode = .center
        case 1, 4: iv.contentMode = .scaleAspectFit
        case 2: iv.contentMode = .scaleAspectFill
        case 3: iv.contentMode = .scaleToFill
        default: iv.contentMode = .scaleAspectFit
        }

        // Tint
        if tintColor != 0 {
            iv.tintColor = UIColor(argb: tintColor)
            iv.image = iv.image?.withRenderingMode(.alwaysTemplate)
        }

        // Load image
        if !src.isEmpty, let url = URL(string: src) {
            loadImage(iv, url: url, tint: tintColor != 0)
        }
    }

    private func loadImage(_ iv: UIImageView, url: URL, tint: Bool) {
        // Check if it's a local/bundle image first
        if url.scheme == nil || url.scheme == "file" {
            if let img = UIImage(contentsOfFile: url.path) {
                iv.image = tint ? img.withRenderingMode(.alwaysTemplate) : img
                return
            }
        }

        // Async download
        let task = URLSession.shared.dataTask(with: url) { data, _, error in
            guard let data, error == nil, let img = UIImage(data: data) else { return }
            DispatchQueue.main.async {
                iv.image = tint ? img.withRenderingMode(.alwaysTemplate) : img
            }
        }
        task.resume()
    }
}
