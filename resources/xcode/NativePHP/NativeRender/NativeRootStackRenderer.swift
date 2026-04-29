import SwiftUI

/// Phase 2 of the native chrome swap (project_native_chrome_swap.md).
/// Renders a `native_root_stack` sentinel element via SwiftUI's
/// `NavigationStack` — system push animation, edge-swipe-back support
/// (when the path has multiple entries), large title eligibility, and
/// (on iOS 26+) Liquid Glass material on the navigation bar by default.
///
/// **Phase 2 alpha — single-level only.** The `NavigationStack` is
/// driven by an empty path, so push/pop within the bar is currently
/// handled by the existing `AnimatedContent` transition system at the
/// tree level. Phase 2.5 will introduce a per-route tree cache and a
/// `NavigationCoordinator` that lets the path actually grow, enabling
/// edge-swipe-back via the system gesture.
///
/// **Three-tier appearance** (matches the locked-in contract in the
/// plan):
///   - No `background_color` / `text_color` set → defaults; on iOS 26+
///     the system applies Liquid Glass.
///   - Either color set → `.toolbarBackground(...)` made visible →
///     opaque native bar with the developer's solid colors (the
///     X / Instagram path).
///   - Anything routed through the inline `<native:top-bar>` blade tag
///     bypasses this renderer entirely (the `usesNativeChrome()` flag
///     gates this branch in `wrapWithChrome`).
struct NativeRootStackRenderer: View {
    let node: NativeUINode

    var body: some View {
        let title = node.props.getString("title", default: "")
        let subtitle = node.props.getString("subtitle", default: "")
        let showBack = node.props.getBool("back")
        let bgArgb = node.props.getColor("background_color", default: 0)
        let textArgb = node.props.getColor("text_color", default: 0)

        // Children: top_bar_action items + screen content. The screen
        // content is the first child whose type isn't `top_bar_action`.
        let actions = node.children.filter { $0.type == "top_bar_action" }
        let screenContent = node.children.first { $0.type != "top_bar_action" }

        let textColor: Color = textArgb != 0 ? Color(argb: textArgb) : .primary
        let hasExplicitBg = bgArgb != 0

        NavigationStack {
            content(screenContent)
                .navigationTitle(title)
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    // Leading back chevron — only when the layout asked
                    // for one. NavigationStack would normally hide this
                    // on a single-level path; we render it manually so
                    // the existing PHP back semantics keep working until
                    // Phase 2.5 wires the real path.
                    if showBack {
                        ToolbarItem(placement: .topBarLeading) {
                            Button {
                                NativeElementBridge.sendSystemBackEvent()
                            } label: {
                                Image(systemName: "chevron.backward")
                                    .font(.system(size: 17, weight: .semibold))
                                    .foregroundColor(textColor)
                            }
                        }
                    }

                    // Subtitle: substitute a custom principal item that
                    // stacks title + caption. SwiftUI's
                    // `.navigationSubtitle()` only landed in iOS 26 —
                    // this works back to iOS 16.
                    if !subtitle.isEmpty {
                        ToolbarItem(placement: .principal) {
                            VStack(spacing: 0) {
                                Text(title)
                                    .font(.headline)
                                    .foregroundColor(textColor)
                                Text(subtitle)
                                    .font(.caption)
                                    .foregroundColor(textColor.opacity(0.7))
                            }
                        }
                    }

                    // Trailing actions, in declaration order. SwiftUI's
                    // `@ToolbarContentBuilder` doesn't have a `ForEach`
                    // overload — dynamic items have to live inside a
                    // `ToolbarItemGroup`, whose contents are a normal
                    // `@ViewBuilder`.
                    ToolbarItemGroup(placement: .topBarTrailing) {
                        ForEach(actions) { action in
                            actionView(action, textColor: textColor)
                        }
                    }
                }
                // Three-tier appearance: explicit color → opaque native
                // bar; otherwise leave alone so iOS 26+ applies Liquid
                // Glass automatically.
                .toolbarBackground(
                    hasExplicitBg ? Color(argb: bgArgb) : .clear,
                    for: .navigationBar
                )
                .toolbarBackground(
                    hasExplicitBg ? .visible : .automatic,
                    for: .navigationBar
                )
        }
    }

    @ViewBuilder
    private func content(_ node: NativeUINode?) -> some View {
        if let node = node {
            NodeView(node: node)
        } else {
            Color.clear
        }
    }

    /// Renders one trailing action — either a plain Button (when the
    /// action has no sub-items) or a SwiftUI `Menu` of sub-items
    /// (when `NavAction::items()` was set on the PHP side, which puts
    /// the sub-actions in `action.children`).
    @ViewBuilder
    private func actionView(_ action: NativeUINode, textColor: Color) -> some View {
        let icon = action.props.getString("icon", default: "ellipsis")
        let subItems = action.children.filter { $0.type == "top_bar_action" }

        if subItems.isEmpty {
            // Plain button — fires `onPress` directly.
            Button {
                if action.onPress != 0 {
                    NativeElementBridge.sendPressEvent(action.onPress, nodeId: action.id)
                }
            } label: {
                Image(systemName: getIconForName(icon))
                    .font(.system(size: 17, weight: .semibold))
                    .foregroundColor(textColor)
            }
        } else {
            // Pull-down menu — tap reveals sub-items, each fires its own
            // `onPress`. Optional per-item `label`, `icon`, and `destructive`
            // flag (renders the item with `.role(.destructive)` styling).
            Menu {
                ForEach(subItems) { item in
                    let itemLabel = item.props.getString("label", default: "")
                    let itemIcon = item.props.getString("icon", default: "")
                    let isDestructive = item.props.getBool("destructive")
                    Button(role: isDestructive ? .destructive : nil) {
                        if item.onPress != 0 {
                            NativeElementBridge.sendPressEvent(item.onPress, nodeId: item.id)
                        }
                    } label: {
                        if !itemIcon.isEmpty {
                            Label(itemLabel, systemImage: getIconForName(itemIcon))
                        } else {
                            Text(itemLabel)
                        }
                    }
                }
            } label: {
                Image(systemName: getIconForName(icon))
                    .font(.system(size: 17, weight: .semibold))
                    .foregroundColor(textColor)
            }
        }
    }
}
