import SwiftUI

struct RenderCircle: View {
    let node: NativeUINode

    var body: some View {
        // borderRadius=9999 is forced from PHP, so NodeModifier already
        // clips with a large corner radius — effectively a circle.
        // Canvas parent handles offset positioning.
        Color.clear
    }
}
