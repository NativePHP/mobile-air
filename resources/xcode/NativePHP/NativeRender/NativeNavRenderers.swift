import UIKit
import SwiftUI  // For NativeEdgeDrawerState (ObservableObject used by SwiftUI shell)

// MARK: - Top Bar

struct TopBarViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let container = UIView()
        buildTopBar(container, node: node)
        return container
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        view.subviews.forEach { $0.removeFromSuperview() }
        buildTopBar(view, node: node)
    }

    private func buildTopBar(_ container: UIView, node: NativeUINode) {
        let p = node.props
        let title = p.getString("title")
        let subtitle = p.getString("subtitle")

        // Title label
        let titleLabel = UILabel()
        titleLabel.text = title
        titleLabel.font = .preferredFont(forTextStyle: .headline)
        container.addSubview(titleLabel)

        var labelBottom: CGFloat = 0

        // Subtitle
        var subtitleLabel: UILabel?
        if !subtitle.isEmpty {
            let sl = UILabel()
            sl.text = subtitle
            sl.font = .preferredFont(forTextStyle: .subheadline)
            sl.textColor = .secondaryLabel
            container.addSubview(sl)
            subtitleLabel = sl
        }

        // Action buttons (max 3)
        let actions = node.children.filter { $0.type == "top_bar_action" }
        var actionButtons: [UIButton] = []
        for action in actions.prefix(3) {
            let icon = action.props.getString("icon", default: "ellipsis")
            let sfName = getIconForName(icon)
            let btn = UIButton(type: .system)
            btn.setImage(UIImage(systemName: sfName), for: .normal)
            btn.tag = action.onPress
            btn.accessibilityIdentifier = "\(action.id)"
            btn.addTarget(TopBarActionTarget.shared, action: #selector(TopBarActionTarget.actionTapped(_:)), for: .touchUpInside)
            container.addSubview(btn)
            actionButtons.append(btn)
        }

        // Layout in layoutSubviews override or manual frame
        container.setNeedsLayout()
        container.layoutIfNeeded()

        // Manual layout
        let bounds = container.bounds
        let hPad: CGFloat = 16
        let vPad: CGFloat = 12

        // Action buttons from right
        var rightX = bounds.width - hPad
        for btn in actionButtons.reversed() {
            let size: CGFloat = 28
            rightX -= size
            btn.frame = CGRect(x: rightX, y: vPad, width: size, height: size)
            rightX -= 8
        }

        let textMaxW = rightX - hPad
        titleLabel.frame = CGRect(x: hPad, y: vPad, width: textMaxW, height: 20)
        titleLabel.sizeToFit()
        titleLabel.frame.size.width = min(titleLabel.frame.width, textMaxW)

        labelBottom = titleLabel.frame.maxY
        if let sl = subtitleLabel {
            sl.frame = CGRect(x: hPad, y: labelBottom + 2, width: textMaxW, height: 16)
            sl.sizeToFit()
            sl.frame.size.width = min(sl.frame.width, textMaxW)
        }
    }
}

class TopBarActionTarget {
    static let shared = TopBarActionTarget()
    @objc func actionTapped(_ sender: UIButton) {
        let callbackId = sender.tag
        let nodeId = Int(sender.accessibilityIdentifier ?? "0") ?? 0
        if callbackId != 0 {
            NativeElementBridge.sendPressEvent(callbackId, nodeId: nodeId)
        }
    }
}

// MARK: - Side Nav

struct SideNavViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        // Side nav stores its node for the drawer to render — doesn't render inline
        NativeEdgeDrawerState.shared.sideNavNode = node
        let v = UIView()
        v.isHidden = true
        return v
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        NativeEdgeDrawerState.shared.sideNavNode = node
    }
}

// MARK: - Bottom Nav

struct BottomNavViewRenderer: NativeViewRenderer {
    func createView(node: NativeUINode) -> UIView {
        let container = UIView()
        container.backgroundColor = .systemBackground
        buildBottomNav(container, node: node)
        return container
    }

    func updateView(_ view: UIView, node: NativeUINode) {
        view.subviews.forEach { $0.removeFromSuperview() }
        buildBottomNav(view, node: node)
    }

    private func buildBottomNav(_ container: UIView, node: NativeUINode) {
        let items = node.children.filter { $0.type == "bottom_nav_item" }
        guard !items.isEmpty else { return }

        let itemWidth = container.bounds.width / CGFloat(items.count)
        let vPad: CGFloat = 8

        for (i, item) in items.enumerated() {
            let p = item.props
            let label = p.getString("label")
            let icon = p.getString("icon", default: "circle")
            let active = p.getBool("active")

            let itemView = UIView()
            itemView.frame = CGRect(x: itemWidth * CGFloat(i), y: 0, width: itemWidth, height: container.bounds.height)

            // Icon
            let sfName = getIconForName(icon)
            let iconIV = UIImageView(image: UIImage(systemName: sfName))
            iconIV.contentMode = .scaleAspectFit
            iconIV.tintColor = active ? .systemBlue : .secondaryLabel
            iconIV.frame = CGRect(x: (itemWidth - 22) / 2, y: vPad, width: 22, height: 22)
            itemView.addSubview(iconIV)

            // Label
            let lbl = UILabel()
            lbl.text = label
            lbl.font = .systemFont(ofSize: 10)
            lbl.textColor = active ? .systemBlue : .secondaryLabel
            lbl.textAlignment = .center
            lbl.frame = CGRect(x: 0, y: vPad + 24, width: itemWidth, height: 14)
            itemView.addSubview(lbl)

            // Badge
            let badge = p.getString("badge")
            if !badge.isEmpty {
                let badgeLabel = UILabel()
                badgeLabel.text = badge
                badgeLabel.font = .systemFont(ofSize: 10, weight: .bold)
                badgeLabel.textColor = .white
                badgeLabel.backgroundColor = .red
                badgeLabel.textAlignment = .center
                badgeLabel.layer.cornerRadius = 8
                badgeLabel.clipsToBounds = true
                let bw = max(16, CGFloat(badge.count) * 8 + 8)
                badgeLabel.frame = CGRect(x: (itemWidth + 22) / 2 - 4, y: vPad - 4, width: bw, height: 16)
                itemView.addSubview(badgeLabel)
            }

            // Tap target
            let btn = UIButton(type: .system)
            btn.frame = itemView.bounds
            btn.tag = item.onPress
            btn.accessibilityIdentifier = "\(item.id)"
            btn.addTarget(BottomNavTarget.shared, action: #selector(BottomNavTarget.itemTapped(_:)), for: .touchUpInside)
            itemView.addSubview(btn)

            container.addSubview(itemView)
        }
    }
}

class BottomNavTarget {
    static let shared = BottomNavTarget()
    @objc func itemTapped(_ sender: UIButton) {
        let callbackId = sender.tag
        let nodeId = Int(sender.accessibilityIdentifier ?? "0") ?? 0
        if callbackId != 0 {
            NativeElementBridge.sendPressEvent(callbackId, nodeId: nodeId)
        }
    }
}

// MARK: - NativeEdgeDrawerState (kept for compatibility)
// This was originally a SwiftUI ObservableObject. We keep it as a simple holder.

class NativeEdgeDrawerState: ObservableObject {
    static let shared = NativeEdgeDrawerState()
    @Published var sideNavNode: NativeUINode?
}
