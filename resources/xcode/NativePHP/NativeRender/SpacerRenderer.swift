import SwiftUI

struct RenderSpacer: View {
    let node: NativeUINode

    var body: some View {
        let layout = node.layout
        if let layout, layout.widthMode == SizeMode.fixed, layout.width > 0 {
            Spacer().frame(width: CGFloat(layout.width))
        } else if let layout, layout.heightMode == SizeMode.fixed, layout.height > 0 {
            Spacer().frame(height: CGFloat(layout.height))
        } else {
            // Match Android: fill width with zero height (non-greedy vertical)
            Spacer().frame(maxWidth: .infinity, maxHeight: 0)
        }
    }
}
