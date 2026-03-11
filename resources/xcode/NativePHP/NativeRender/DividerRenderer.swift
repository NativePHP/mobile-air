import SwiftUI

struct RenderDivider: View {
    let node: NativeUINode

    var body: some View {
        let color = node.style.map { Color(argb: $0.borderColor) }
        let thickness = node.style?.borderWidth ?? 0

        if let color, color != Color(argb: 0) {
            Divider()
                .overlay(color)
                .frame(height: thickness > 0 ? CGFloat(thickness) : nil)
        } else {
            Divider()
        }
    }
}
