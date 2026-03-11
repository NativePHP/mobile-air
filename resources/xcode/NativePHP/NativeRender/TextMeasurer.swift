import Foundation
import UIKit

/// Pre-measures leaf node intrinsic sizes for Yoga layout.
/// Called on the shadow thread before Yoga compute.
/// All UIKit text measurement APIs used here are thread-safe.
enum TextMeasurer {

    /// Measure intrinsic size for a node. Returns (width, height).
    /// For container nodes, returns (0, 0) — Yoga computes their size from children.
    static func measure(node: NativeUINode, maxWidth: Float) -> (Float, Float) {
        switch node.type {
        case "text":
            return measureText(node: node, maxWidth: maxWidth)
        case "button":
            return measureButton(node: node, maxWidth: maxWidth)
        case "icon":
            let size = node.props.getFloat("size", default: 24)
            return (size, size)
        case "toggle":
            let toggleLabel = node.props.getString("label")
            if toggleLabel.isEmpty { return (51, 31) }
            let (tlw, tlh) = measureString(toggleLabel, fontSize: 17, maxWidth: maxWidth - 59)
            return (tlw + 59, max(tlh, 31))  // 51 switch + 8 spacing
        case "checkbox":
            let label = node.props.getString("label")
            if label.isEmpty { return (24, 24) }
            let (tw, th) = measureString(label, fontSize: 17, maxWidth: maxWidth - 32)
            return (tw + 32, max(th, 24))
        case "text_input":
            return (0, 44)
        case "slider":
            return (0, 32)
        case "progress_bar":
            return (0, 4)
        case "activity_indicator":
            return (20, 20)
        case "divider", "line", "horizontal_divider":
            return (0, 1)
        case "spacer":
            return (0, 0)
        case "image":
            let w = node.layout?.widthMode == SizeMode.fixed ? node.layout!.width : 0
            let h = node.layout?.heightMode == SizeMode.fixed ? node.layout!.height : 0
            return (w, h)
        case "badge":
            return measureBadge(node: node)
        case "chip":
            return measureChip(node: node, maxWidth: maxWidth)
        case "select":
            return (0, 44)
        case "radio":
            let label = node.props.getString("label")
            if label.isEmpty { return (24, 24) }
            let (tw, th) = measureString(label, fontSize: 17, maxWidth: maxWidth - 32)
            return (tw + 32, max(th, 24))
        default:
            return (0, 0)
        }
    }

    // MARK: - Text

    private static func measureText(node: NativeUINode, maxWidth: Float) -> (Float, Float) {
        let text = node.props.getString("text")
        guard !text.isEmpty else { return (0, 0) }

        let fontSize = node.props.getFloat("font_size", default: 16)
        let weight = mapFontWeight(node.props.getInt("font_weight"))
        let maxLines = node.props.getInt("max_lines")

        let font = UIFont.systemFont(ofSize: CGFloat(fontSize), weight: weight)
        let constraintWidth = maxWidth > 0 ? CGFloat(maxWidth) : CGFloat.greatestFiniteMagnitude
        let boundingRect = (text as NSString).boundingRect(
            with: CGSize(width: constraintWidth, height: .greatestFiniteMagnitude),
            options: [.usesLineFragmentOrigin, .usesFontLeading],
            attributes: [.font: font],
            context: nil
        )

        var h = Float(ceil(boundingRect.height))
        if maxLines > 0 {
            let lineHeight = Float(ceil(font.lineHeight))
            h = min(h, lineHeight * Float(maxLines))
        }

        return (Float(ceil(boundingRect.width)), h)
    }

    // MARK: - Button

    private static func measureButton(node: NativeUINode, maxWidth: Float) -> (Float, Float) {
        let label = node.props.getString("label")
        guard !label.isEmpty else { return (48, 40) }

        let fontSize = node.props.getFloat("font_size", default: 16)
        let hPad: Float = 48 // 24 each side
        let effectiveMaxW = maxWidth > hPad ? maxWidth - hPad : Float.greatestFiniteMagnitude

        let (tw, th) = measureString(label, fontSize: fontSize, maxWidth: effectiveMaxW)
        return (tw + hPad, max(th + 16, 40))
    }

    // MARK: - Badge

    private static func measureBadge(node: NativeUINode) -> (Float, Float) {
        let count = node.props.getInt("count")
        let text = count > 99 ? "99+" : "\(count)"
        let (tw, th) = measureString(text, fontSize: 12, maxWidth: .greatestFiniteMagnitude)
        return (tw + 12, th + 4)
    }

    // MARK: - Chip

    private static func measureChip(node: NativeUINode, maxWidth: Float) -> (Float, Float) {
        let label = node.props.getString("label")
        let hasIcon = !node.props.getString("icon").isEmpty
        let iconSpace: Float = hasIcon ? 20 : 0
        let hPad: Float = 24
        let (tw, th) = measureString(label, fontSize: 15, maxWidth: maxWidth - hPad - iconSpace)
        return (tw + hPad + iconSpace, max(th + 12, 32))
    }

    // MARK: - Helpers

    private static func measureString(_ text: String, fontSize: Float, maxWidth: Float) -> (Float, Float) {
        let font = UIFont.systemFont(ofSize: CGFloat(fontSize))
        let rect = (text as NSString).boundingRect(
            with: CGSize(width: CGFloat(maxWidth), height: .greatestFiniteMagnitude),
            options: [.usesLineFragmentOrigin, .usesFontLeading],
            attributes: [.font: font],
            context: nil
        )
        return (Float(ceil(rect.width)), Float(ceil(rect.height)))
    }

    private static func mapFontWeight(_ weight: Int) -> UIFont.Weight {
        switch weight {
        case 100: return .ultraLight
        case 200: return .thin
        case 300: return .light
        case 500: return .medium
        case 600: return .semibold
        case 700: return .bold
        case 800: return .heavy
        case 900: return .black
        default:  return .regular
        }
    }
}
