import SwiftUI

struct RenderPressable: View {
    let node: NativeUINode

    var body: some View {
        ZStack {
            ForEach(node.children) { child in
                RenderNode(node: child)
            }
        }
        // Click handling is done by NodeModifier (after all styling/sizing)
    }
}
