import SwiftUI

struct RenderText: View {
    let node: NativeUINode

    var body: some View {
        let p = node.props
        let text = p.getString("text")

        if !text.isEmpty {
            Text(text)
                .font(.system(
                    size: CGFloat(p.getFloat("font_size", default: 16)),
                    weight: resolveTextFontWeight(p.getInt("font_weight"))
                ))
                .foregroundColor(Color(argb: p.getColor("color", default: 0xFF000000)))
                .multilineTextAlignment(resolveTextAlignment(p.getInt("text_align")))
                .lineLimit(p.getInt("max_lines") > 0 ? p.getInt("max_lines") : nil)
                .truncationMode(.tail)
        }
    }
}

private func resolveTextFontWeight(_ weight: Int) -> Font.Weight {
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

private func resolveTextAlignment(_ align: Int) -> TextAlignment {
    switch align {
    case 0: return .leading
    case 1: return .center
    case 2: return .trailing
    default: return .leading
    }
}
