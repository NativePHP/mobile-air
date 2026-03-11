import SwiftUI

/// Thread-safe registry mapping type strings to renderers.
/// Follows the same pattern as Android's NativeRendererRegistry.
final class NativeRendererRegistry {
    static let shared = NativeRendererRegistry()

    private var renderers: [String: (NativeUINode) -> AnyView] = [:]
    private let lock = NSLock()

    private init() {}

    func register(_ type: String, renderer: @escaping (NativeUINode) -> AnyView) {
        lock.lock()
        defer { lock.unlock() }
        renderers[type] = renderer
    }

    func get(_ type: String) -> ((NativeUINode) -> AnyView)? {
        lock.lock()
        defer { lock.unlock() }
        return renderers[type]
    }

    /// Register all built-in primitive renderers.
    func registerBuiltins() {
        // Containers
        register("column") { AnyView(RenderColumn(node: $0)) }
        register("row") { AnyView(RenderRow(node: $0)) }
        register("stack") { AnyView(RenderStack(node: $0)) }
        register("scroll_view") { AnyView(RenderScrollView(node: $0)) }

        // Navigation chrome
        register("top_bar") { AnyView(RenderTopBar(node: $0)) }
        register("top_bar_action") { _ in AnyView(EmptyView()) }
        register("bottom_nav") { AnyView(RenderBottomNav(node: $0)) }
        register("bottom_nav_item") { _ in AnyView(EmptyView()) }
        register("side_nav") { AnyView(RenderSideNav(node: $0)) }
        register("side_nav_item") { _ in AnyView(EmptyView()) }
        register("side_nav_group") { _ in AnyView(EmptyView()) }
        register("side_nav_header") { _ in AnyView(EmptyView()) }
        register("fab") { AnyView(RenderFab(node: $0)) }
        register("horizontal_divider") { _ in AnyView(Divider().padding(.vertical, 8)) }

        // Core visual primitives
        register("text") { AnyView(RenderText(node: $0)) }
        register("image") { AnyView(RenderImage(node: $0)) }
        register("spacer") { AnyView(RenderSpacer(node: $0)) }
        register("divider") { AnyView(RenderDivider(node: $0)) }
        register("pressable") { AnyView(RenderPressable(node: $0)) }
        register("canvas") { AnyView(RenderCanvas(node: $0)) }
        register("rect") { AnyView(RenderRect(node: $0)) }
        register("circle") { AnyView(RenderCircle(node: $0)) }
        register("line") { AnyView(RenderLine(node: $0)) }

        // Interactive inputs
        register("button") { AnyView(RenderButton(node: $0)) }
        register("text_input") { AnyView(RenderTextInput(node: $0)) }
        register("toggle") { AnyView(RenderToggle(node: $0)) }
        register("checkbox") { AnyView(RenderCheckbox(node: $0)) }
        register("slider") { AnyView(RenderSlider(node: $0)) }
        register("radio_group") { AnyView(RenderRadioGroup(node: $0)) }
        register("select") { AnyView(RenderSelect(node: $0)) }

        // Display components
        register("icon") { AnyView(RenderIcon(node: $0)) }
        register("badge") { AnyView(RenderBadge(node: $0)) }
        register("chip") { AnyView(RenderChip(node: $0)) }
        register("card") { AnyView(RenderCard(node: $0)) }
        register("list_item") { AnyView(RenderListItem(node: $0)) }

        // Indicators & controls
        register("progress_bar") { AnyView(RenderProgressBar(node: $0)) }
        register("activity_indicator") { AnyView(RenderActivityIndicator(node: $0)) }
        register("tab_row") { AnyView(RenderTabRow(node: $0)) }
        register("tab") { _ in AnyView(EmptyView()) }

        // Special containers
        register("bottom_sheet") { AnyView(RenderBottomSheet(node: $0)) }
    }
}
