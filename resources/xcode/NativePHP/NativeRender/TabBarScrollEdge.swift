import SwiftUI

/// iOS 26's scroll edge effect under the native tab bar, for a tab's root
/// level. `style` is the tabs root's `edge_blur` prop: "soft" (the default,
/// sent as nothing), "hard" or "none".
///
/// "soft" is what the tab bar draws by itself, so it leaves the level alone.
/// UIKit puts that effect on the outermost scroll view under the bar, which
/// for a horizontal pager of lists is the pager, so it covers whichever page
/// is on screen. Don't add a second effect for the list inside a page (an
/// edge element interaction on the tab bar, say): the two stack and wash out
/// everything under the bar instead of blurring it.
///
/// "hard" sets the style for the scroll views in the level, and "none" hides
/// the effect there. An empty style (a pushed level, or a root that hides the
/// tab bar) leaves the level alone. A bottom bar on the same level that asks
/// for its own edge blur sits inside this modifier, so its style wins for the
/// bar's region. Before iOS 26 this does nothing.
struct TabBarEdgeBlurModifier: ViewModifier {
    let style: String

    func body(content: Content) -> some View {
        #if compiler(>=6.2)
        if #available(iOS 26.0, *) {
            switch style {
            case "none":
                content.scrollEdgeEffectHidden(true, for: .bottom)
            case "hard":
                content.scrollEdgeEffectStyle(.hard, for: .bottom)
            default:
                content
            }
        } else {
            content
        }
        #else
        content
        #endif
    }
}
