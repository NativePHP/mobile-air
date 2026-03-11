import SwiftUI

// MARK: - Top Bar

struct RenderTopBar: View {
    let node: NativeUINode

    var body: some View {
        let p = node.props
        let title = p.getString("title")
        let subtitle = p.getString("subtitle")

        VStack(spacing: 0) {
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text(title)
                        .font(.headline)
                    if !subtitle.isEmpty {
                        Text(subtitle)
                            .font(.subheadline)
                            .foregroundColor(.secondary)
                    }
                }

                Spacer()

                // Action buttons (max 3 visible, rest overflow)
                let actions = node.children.filter { $0.type == "top_bar_action" }
                ForEach(actions.prefix(3)) { action in
                    let icon = action.props.getString("icon", default: "ellipsis")

                    Button(action: {
                        if action.onPress != 0 {
                            NativeUIBridge.sendPressEvent(action.onPress, nodeId: action.id)
                        }
                    }) {
                        Image(systemName: getIconForName(icon))
                    }
                }
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
        }
    }
}

// MARK: - Side Nav

/// Stores side_nav node for the drawer to render. The side_nav node
/// itself doesn't render inline — it provides data for the drawer.
class NativeEdgeDrawerState: ObservableObject {
    static let shared = NativeEdgeDrawerState()
    @Published var sideNavNode: NativeUINode?
}

struct RenderSideNav: View {
    let node: NativeUINode

    var body: some View {
        Color.clear.frame(width: 0, height: 0)
            .onAppear {
                NativeEdgeDrawerState.shared.sideNavNode = node
            }
    }
}

// MARK: - Bottom Nav

struct RenderBottomNav: View {
    let node: NativeUINode

    var body: some View {
        let items = node.children.filter { $0.type == "bottom_nav_item" }
        guard !items.isEmpty else { return AnyView(EmptyView()) }

        return AnyView(
            HStack(spacing: 0) {
                ForEach(items) { item in
                    let p = item.props
                    let label = p.getString("label")
                    let icon = p.getString("icon", default: "circle")
                    let active = p.getBool("active")
                    let badge = p.getString("badge")

                    Button(action: {
                        if item.onPress != 0 {
                            NativeUIBridge.sendPressEvent(item.onPress, nodeId: item.id)
                        }
                    }) {
                        VStack(spacing: 4) {
                            ZStack(alignment: .topTrailing) {
                                Image(systemName: getIconForName(icon))
                                    .font(.system(size: 22))

                                if !badge.isEmpty {
                                    Text(badge)
                                        .font(.system(size: 10, weight: .bold))
                                        .foregroundColor(.white)
                                        .padding(.horizontal, 4)
                                        .padding(.vertical, 1)
                                        .background(Color.red)
                                        .clipShape(Capsule())
                                        .offset(x: 8, y: -4)
                                }
                            }

                            Text(label)
                                .font(.system(size: 10))
                        }
                        .frame(maxWidth: .infinity)
                        .foregroundColor(active ? .accentColor : .secondary)
                    }
                }
            }
            .padding(.vertical, 8)
            .background(Color(.systemBackground))
        )
    }
}

// MARK: - FAB

struct RenderFab: View {
    let node: NativeUINode

    var body: some View {
        let p = node.props
        let icon = p.getString("icon", default: "plus")
        let label = p.getString("label")
        let size = p.getString("size", default: "regular")

        let buttonSize: CGFloat = switch size {
        case "small": 40
        case "large": 64
        default: 56
        }

        Button(action: {
            if node.onPress != 0 {
                NativeUIBridge.sendPressEvent(node.onPress, nodeId: node.id)
            }
        }) {
            if size == "extended" && !label.isEmpty {
                HStack(spacing: 8) {
                    Image(systemName: getIconForName(icon))
                    Text(label)
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 12)
            } else {
                Image(systemName: getIconForName(icon))
                    .font(.system(size: buttonSize * 0.4))
                    .frame(width: buttonSize, height: buttonSize)
            }
        }
        .buttonStyle(.borderedProminent)
        .clipShape(RoundedRectangle(cornerRadius: buttonSize / 4))
    }
}
