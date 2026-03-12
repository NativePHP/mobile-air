import UIKit

struct TextViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let label = UILabel()
        label.numberOfLines = 0
        applyProps(label, node: node)
        return label
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        guard let label = view as? UILabel else { return }
        applyProps(label, node: node)
    }

    private func applyProps(_ label: UILabel, node: NativeUINode) {
        let p = node.props
        label.text = p.getString("text")
        label.font = .systemFont(
            ofSize: CGFloat(p.getFloat("font_size", default: 16)),
            weight: resolveWeight(p.getInt("font_weight"))
        )
        let dark: Bool = {
            if #available(iOS 13.0, *) {
                // Use label's own traitCollection for reliable dark mode detection
                return label.traitCollection.userInterfaceStyle == .dark
            }
            return false
        }()
        if p.has("dark_color") {
            print("DARK DEBUG TEXT: isDark=\(dark) has_dark_color=true dark_color_val=\(p.getColor("dark_color", default: 0)) text=\(p.getString("text").prefix(20))")
        }
        let darkColor = dark ? p.getColor("dark_color", default: 0) : 0
        let textArgb = darkColor != 0 ? darkColor : p.getColor("color", default: 0xFF000000)
        label.textColor = UIColor(argb: textArgb)
        label.textAlignment = resolveAlignment(p.getInt("text_align"))
        let maxLines = p.getInt("max_lines")
        label.numberOfLines = maxLines > 0 ? maxLines : 0
        label.lineBreakMode = .byTruncatingTail
        label.adjustsFontSizeToFitWidth = true
        label.minimumScaleFactor = 0.5
    }
}

private func resolveWeight(_ weight: Int) -> UIFont.Weight {
    switch weight {
    case 1: return .thin
    case 2: return .light
    case 3: return .regular
    case 4: return .medium
    case 5: return .semibold
    case 6: return .bold
    case 7: return .heavy
    default: return .regular
    }
}

private func resolveAlignment(_ align: Int) -> NSTextAlignment {
    switch align {
    case 0: return .left
    case 1: return .center
    case 2: return .right
    default: return .natural
    }
}
