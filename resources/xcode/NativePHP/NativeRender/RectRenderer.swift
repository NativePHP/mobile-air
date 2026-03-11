import SwiftUI

struct RenderRect: View {
    let node: NativeUINode

    var body: some View {
        // Render as an empty container (like Android's Box(modifier)).
        // NodeModifier applies bgColor, borderRadius, border, size.
        // Canvas parent handles offset positioning.
        Color.clear
    }
}
