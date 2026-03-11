import SwiftUI

struct RenderCanvas: View {
    let node: NativeUINode

    var body: some View {
        let borderRadius = CGFloat(node.style?.borderRadius ?? 0)

        ZStack(alignment: .topLeading) {
            ForEach(node.children) { child in
                let left = CGFloat(child.props.getFloat("left"))
                let top = CGFloat(child.props.getFloat("top"))

                RenderNode(node: child)
                    .offset(x: left, y: top)
            }
        }
        .clipShape(RoundedRectangle(cornerRadius: borderRadius))
    }
}
