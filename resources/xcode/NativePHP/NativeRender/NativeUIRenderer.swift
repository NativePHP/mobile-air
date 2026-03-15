import UIKit
import SwiftUI

// MARK: - SwiftUI Hosting (bridges UIView tree into SwiftUI shell)

struct NativeUIContent: UIViewRepresentable {
    @ObservedObject var bridge = NativeUIBridge.shared

    func makeUIView(context: Context) -> NativeUIViewRenderer {
        NativeUIViewRenderer()
    }

    func updateUIView(_ renderer: NativeUIViewRenderer, context: Context) {
        if let tree = bridge.currentTree {
            renderer.applyTree(tree)
        } else {
            renderer.clear()
        }
    }
}

// MARK: - UIView Tree Renderer

/// Manages a UIView tree driven by NativeUINode trees from PHP.
/// Each node maps to a UIView. On tree update, diffs and mutates views imperatively.
final class NativeUIViewRenderer: UIView {

    /// Flat map of nodeId → UIView for O(1) lookup during diff
    private var viewMap: [Int: UIView] = [:]

    /// Track which node is currently associated with each view
    private var nodeMap: [Int: NativeUINode] = [:]

    /// Track the element type for each node ID so we can detect type changes
    private var nodeTypeMap: [Int: String] = [:]

    /// Special container types that need custom rendering
    private static let scrollTypes: Set<String> = ["scroll_view"]
    private static let sheetTypes: Set<String> = ["bottom_sheet"]

    override init(frame: CGRect) {
        super.init(frame: frame)
        backgroundColor = .systemBackground
        // Dismiss keyboard on tap
        let tap = UITapGestureRecognizer(target: self, action: #selector(dismissKeyboard))
        tap.cancelsTouchesInView = false
        addGestureRecognizer(tap)
    }

    required init?(coder: NSCoder) { fatalError() }

    @objc private func dismissKeyboard() {
        endEditing(true)
    }

    // MARK: - Apply Tree

    func applyTree(_ tree: NativeUITree) {
        print("DEBUG TREE: renderer.bounds=\(self.bounds) renderer.frame=\(self.frame)")
        let root = tree.root
        print("DEBUG TREE: root type=\(root.type) computed=\(root.computed.map { "(\($0.x),\($0.y),\($0.width),\($0.height))" } ?? "nil") children=\(root.children.count)")
        for (i, child) in root.children.enumerated() {
            print("DEBUG TREE: root.child[\(i)] type=\(child.type) computed=\(child.computed.map { "(\($0.x),\($0.y),\($0.width),\($0.height))" } ?? "nil") children=\(child.children.count) safeArea=\(child.layout?.safeArea ?? -1)")
        }
        applyNode(node: tree.root, parentView: self, index: 0)
        // Remove stale subviews from self that aren't the root
        removeStaleChildren(parentView: self, expectedChildren: [tree.root])
    }

    func clear() {
        subviews.forEach { $0.removeFromSuperview() }
        viewMap.removeAll()
        nodeMap.removeAll()
        nodeTypeMap.removeAll()
    }

    // MARK: - Recursive Node Application

    private func applyNode(node: NativeUINode, parentView: UIView, index: Int) {
        if node.type == "button" || node.type == "icon" || node.type == "text" {
            let c = node.computed
            let hasRenderer = NativeRendererRegistry.shared.get(node.type) != nil
            print("DEBUG applyNode: type=\(node.type) id=\(node.id) children=\(node.children.count) computed=\(c.map { "(\($0.x),\($0.y),\($0.width),\($0.height))" } ?? "nil") hasRenderer=\(hasRenderer) props=\(node.props.debugDescription) parentFrame=\(parentView.frame) existing=\(viewMap[node.id] != nil)")
        }
        if let existingView = viewMap[node.id] {
            // Type changed — destroy old view and fall through to create new one
            if nodeTypeMap[node.id] != node.type {
                removeViewRecursively(existingView)
                // Fall through to the create branch below
            } else {
                // Check reference equality — if same object, diff says unchanged
                if nodeMap[node.id] === node {
                    // Unchanged — but ensure correct parent/order
                    ensureParent(existingView, parentView: parentView, index: index)
                    return
                }

                // Same ID, same type, changed props/style/layout — update in place
                ensureParent(existingView, parentView: parentView, index: index)
                applyLayout(existingView, node: node)
                applyStyle(existingView, node: node)
                applyClickHandlers(existingView, node: node)
                updateLeafContent(existingView, node: node)
                nodeMap[node.id] = node
                nodeTypeMap[node.id] = node.type

                // Recurse children
                reconcileChildren(existingView, node: node)
                return
            }
        }

        // New node or type-changed node — create view
        let view = createNodeView(node: node)
        applyLayout(view, node: node)
        applyClickHandlers(view, node: node)
        nodeMap[node.id] = node
        nodeTypeMap[node.id] = node.type
        viewMap[node.id] = view

        if node.type == "button" || node.type == "icon" || node.type == "text" {
            let s = node.style
            print("DEBUG post-create: type=\(node.type) id=\(node.id) viewType=\(type(of: view)) frame=\(view.frame) alpha=\(view.alpha) hidden=\(view.isHidden) bg=\(String(describing: view.backgroundColor)) clipsToBounds=\(view.clipsToBounds) cornerRadius=\(view.layer.cornerRadius) style.opacity=\(s?.opacity ?? -1) style.bgColor=\(s.map { String(format: "0x%08X", UInt32(bitPattern: Int32(truncatingIfNeeded: $0.bgColor))) } ?? "nil") parentClips=\(parentView.clipsToBounds)")
            if let label = view as? UILabel {
                print("DEBUG label: text='\(label.text ?? "nil")' textColor=\(label.textColor!) font=\(label.font!)")
            }
            if let iv = view as? UIImageView {
                print("DEBUG imageView: image=\(iv.image != nil) tint=\(iv.tintColor!)")
            }
        }

        insertView(view, intoParent: parentView, atIndex: index)

        // Apply style and leaf content AFTER insertion so view.traitCollection
        // reflects the window's dark mode setting
        applyStyle(view, node: node)
        if node.children.isEmpty, let renderer = NativeRendererRegistry.shared.get(node.type) {
            renderer.updateView(view, node: node)
        }

        // Create children recursively
        if Self.scrollTypes.contains(node.type) {
            reconcileScrollChildren(view, node: node)
        } else if Self.sheetTypes.contains(node.type) {
            // Bottom sheet handled separately
            handleBottomSheet(view, node: node)
        } else {
            for (i, child) in node.children.enumerated() {
                applyNode(node: child, parentView: view, index: i)
            }
        }
    }

    // MARK: - View Creation

    private func createNodeView(node: NativeUINode) -> UIView {
        if Self.scrollTypes.contains(node.type) {
            return createScrollView(node: node)
        }
        if Self.sheetTypes.contains(node.type) {
            let v = UIView()
            v.isOpaque = false
            return v
        }

        // Check registry for leaf renderers
        if node.children.isEmpty, let renderer = NativeRendererRegistry.shared.get(node.type) {
            let v = renderer.createView(node: node)
            v.isOpaque = false
            return v
        }

        // Container — plain UIView, transparent by default
        let container = UIView()
        container.isOpaque = false
        container.clipsToBounds = false
        return container
    }

    private func updateLeafContent(_ view: UIView, node: NativeUINode) {
        if node.children.isEmpty, let renderer = NativeRendererRegistry.shared.get(node.type) {
            renderer.updateView(view, node: node)
        }
    }

    // MARK: - Layout (frame from Yoga)

    private func applyLayout(_ view: UIView, node: NativeUINode) {
        guard let c = node.computed else { return }

        let frame = CGRect(
            x: CGFloat(c.x), y: CGFloat(c.y),
            width: CGFloat(c.width), height: CGFloat(c.height)
        )

        view.frame = frame

        // Canvas clips children to bounds
        if node.type == "canvas" {
            view.clipsToBounds = true
        }

        // Toggle with label needs internal layout
        if node.type == "toggle" && !node.props.getString("label").isEmpty {
            ToggleViewRenderer.layoutToggleContainer(view)
        }
    }

    // MARK: - Style

    private var isDarkMode: Bool {
        if #available(iOS 13.0, *) {
            // Use self.traitCollection (UIView's own) rather than UITraitCollection.current
            // which may not be set correctly outside UIKit rendering callbacks
            return self.traitCollection.userInterfaceStyle == .dark
        }
        return false
    }

    private func applyStyle(_ view: UIView, node: NativeUINode) {
        guard let style = node.style else {
            view.backgroundColor = nil
            view.layer.cornerRadius = 0
            view.layer.borderWidth = 0
            view.layer.shadowOpacity = 0
            view.alpha = 1
            return
        }

        let dark = isDarkMode

        // Debug: check if dark props exist and dark mode state
        if node.props.has("dark_bg_color") || node.props.has("dark_color") {
            print("DARK DEBUG: node=\(node.type) isDark=\(dark) has_dark_bg=\(node.props.has("dark_bg_color")) has_dark_color=\(node.props.has("dark_color")) dark_bg_val=\(node.props.getColor("dark_bg_color", default: 0))")
        }

        // Background color (0x00000000 = transparent/unset)
        let darkBg = dark ? node.props.getColor("dark_bg_color", default: 0) : 0
        let argb = darkBg != 0 ? darkBg : style.bgColor
        let v = UInt32(bitPattern: Int32(truncatingIfNeeded: argb))
        let alpha = (v >> 24) & 0xFF
        if v == 0 || alpha == 0 {
            view.backgroundColor = nil
            view.isOpaque = false
        } else {
            view.backgroundColor = UIColor(argb: argb)
            view.isOpaque = true
        }

        // Corner radius — clamp to half the shortest dimension (pill shape)
        let maxRadius = min(view.frame.width, view.frame.height) / 2
        let radius = min(CGFloat(style.borderRadius), maxRadius)
        view.layer.cornerRadius = radius
        if radius > 0 {
            view.clipsToBounds = style.elevation <= 0 // Don't clip if shadow needed
        }

        // Border
        if style.borderWidth > 0 && node.type != "line" {
            view.layer.borderWidth = CGFloat(style.borderWidth)
            let darkBorderColor = dark ? node.props.getColor("dark_border_color", default: 0) : 0
            let borderArgb = darkBorderColor != 0 ? darkBorderColor : style.borderColor
            view.layer.borderColor = UIColor(argb: borderArgb).cgColor
        } else {
            view.layer.borderWidth = 0
        }

        // Shadow (elevation)
        if style.elevation > 0 {
            view.layer.shadowColor = UIColor.black.cgColor
            view.layer.shadowOpacity = 0.25
            view.layer.shadowRadius = CGFloat(style.elevation)
            view.layer.shadowOffset = CGSize(width: 0, height: CGFloat(style.elevation / 2))
            view.clipsToBounds = false
        } else {
            view.layer.shadowOpacity = 0
        }

        // Opacity
        let darkOpacity = dark ? node.props.getFloat("dark_opacity") : 0
        view.alpha = darkOpacity > 0 ? CGFloat(darkOpacity) : CGFloat(style.opacity)
    }

    // MARK: - Click Handlers

    private func applyClickHandlers(_ view: UIView, node: NativeUINode) {
        // Remove existing gesture recognizers we added
        view.gestureRecognizers?.forEach { gr in
            if gr is NodeTapGesture || gr is NodeLongPressGesture {
                view.removeGestureRecognizer(gr)
            }
        }

        if node.onPress != 0 {
            let tap = NodeTapGesture(target: self, action: #selector(handleTap(_:)))
            tap.callbackId = node.onPress
            tap.nodeId = node.id
            view.addGestureRecognizer(tap)
            view.isUserInteractionEnabled = true
        }

        if node.onLongPress != 0 {
            let lp = NodeLongPressGesture(target: self, action: #selector(handleLongPress(_:)))
            lp.callbackId = node.onLongPress
            lp.nodeId = node.id
            lp.minimumPressDuration = 0.5
            view.addGestureRecognizer(lp)
            view.isUserInteractionEnabled = true
        }
    }

    @objc private func handleTap(_ gesture: NodeTapGesture) {
        NativeElementBridge.sendPressEvent(gesture.callbackId, nodeId: gesture.nodeId)
    }

    @objc private func handleLongPress(_ gesture: NodeLongPressGesture) {
        guard gesture.state == .began else { return }
        NativeElementBridge.sendLongPressEvent(gesture.callbackId, nodeId: gesture.nodeId)
    }

    // MARK: - Child Reconciliation

    private func reconcileChildren(_ parentView: UIView, node: NativeUINode) {
        if Self.scrollTypes.contains(node.type) {
            reconcileScrollChildren(parentView, node: node)
            return
        }
        if Self.sheetTypes.contains(node.type) {
            handleBottomSheet(parentView, node: node)
            return
        }

        for (i, child) in node.children.enumerated() {
            applyNode(node: child, parentView: parentView, index: i)
        }
        removeStaleChildren(parentView: parentView, expectedChildren: node.children)
    }

    private func removeStaleChildren(parentView: UIView, expectedChildren: [NativeUINode]) {
        let expectedIds = Set(expectedChildren.map { $0.id })
        for subview in parentView.subviews {
            let viewNodeId = nodeIdForView(subview)
            if viewNodeId != nil && !expectedIds.contains(viewNodeId!) {
                removeViewRecursively(subview)
            }
        }
    }

    private func removeViewRecursively(_ view: UIView) {
        // Remove children first
        for sub in view.subviews {
            removeViewRecursively(sub)
        }
        // Remove from maps
        if let nodeId = nodeIdForView(view) {
            viewMap.removeValue(forKey: nodeId)
            nodeMap.removeValue(forKey: nodeId)
            nodeTypeMap.removeValue(forKey: nodeId)
        }
        view.removeFromSuperview()
    }

    private func nodeIdForView(_ view: UIView) -> Int? {
        for (id, v) in viewMap where v === view {
            return id
        }
        return nil
    }

    private func ensureParent(_ view: UIView, parentView: UIView, index: Int) {
        if view.superview !== parentView {
            view.removeFromSuperview()
            insertView(view, intoParent: parentView, atIndex: index)
        }
    }

    private func insertView(_ view: UIView, intoParent parent: UIView, atIndex index: Int) {
        if index < parent.subviews.count {
            parent.insertSubview(view, at: index)
        } else {
            parent.addSubview(view)
        }
    }

    // MARK: - Scroll View

    private func createScrollView(node: NativeUINode) -> UIScrollView {
        let sv = UIScrollView()
        let horizontal = node.props.getBool("horizontal")
        sv.showsVerticalScrollIndicator = !horizontal && node.props.getBool("shows_indicators", default: true)
        sv.showsHorizontalScrollIndicator = horizontal && node.props.getBool("shows_indicators", default: true)
        sv.alwaysBounceVertical = !horizontal
        sv.alwaysBounceHorizontal = horizontal
        sv.clipsToBounds = true
        return sv
    }

    private func reconcileScrollChildren(_ scrollViewOrWrapper: UIView, node: NativeUINode) {
        guard let sv = scrollViewOrWrapper as? UIScrollView else { return }

        // Apply children directly to scroll view
        for (i, child) in node.children.enumerated() {
            applyNode(node: child, parentView: sv, index: i)
        }
        removeStaleChildren(parentView: sv, expectedChildren: node.children)

        // Compute content size from children extents
        var maxX: CGFloat = 0
        var maxY: CGFloat = 0
        for child in node.children {
            if let c = child.computed {
                maxX = max(maxX, CGFloat(c.x + c.width))
                maxY = max(maxY, CGFloat(c.y + c.height))
            }
        }
        sv.contentSize = CGSize(width: maxX, height: maxY)
        print("DEBUG SCROLL: frame=\(sv.frame) contentSize=\(sv.contentSize) bounce=\(sv.alwaysBounceVertical) children=\(node.children.count) childComputed=\(node.children.first?.computed.map { "(\($0.x),\($0.y),\($0.width),\($0.height))" } ?? "nil")")
    }

    // MARK: - Bottom Sheet

    private func handleBottomSheet(_ view: UIView, node: NativeUINode) {
        let visible = node.props.getBool("visible")
        view.isHidden = true // Placeholder view is always hidden

        guard visible else { return }

        // Find the presenting view controller
        guard let vc = findViewController() else { return }

        let sheetVC = UIViewController()
        sheetVC.view.backgroundColor = .systemBackground

        // Build sheet content
        let contentView = UIView()
        contentView.translatesAutoresizingMaskIntoConstraints = false
        sheetVC.view.addSubview(contentView)
        NSLayoutConstraint.activate([
            contentView.topAnchor.constraint(equalTo: sheetVC.view.topAnchor),
            contentView.leadingAnchor.constraint(equalTo: sheetVC.view.leadingAnchor),
            contentView.trailingAnchor.constraint(equalTo: sheetVC.view.trailingAnchor),
            contentView.bottomAnchor.constraint(equalTo: sheetVC.view.bottomAnchor),
        ])

        for (i, child) in node.children.enumerated() {
            applyNode(node: child, parentView: contentView, index: i)
        }

        if let sheet = sheetVC.sheetPresentationController {
            sheet.detents = [.medium(), .large()]
            sheet.prefersGrabberVisible = true
        }

        let onDismissCb = node.props.getCallbackId("on_dismiss")
        sheetVC.presentationController?.delegate = SheetDismissDelegate(callbackId: onDismissCb, nodeId: node.id)

        vc.present(sheetVC, animated: true)
    }

    private func findViewController() -> UIViewController? {
        var responder: UIResponder? = self
        while let next = responder?.next {
            if let vc = next as? UIViewController { return vc }
            responder = next
        }
        return nil
    }
}

// MARK: - Gesture Recognizer Subclasses

final class NodeTapGesture: UITapGestureRecognizer {
    var callbackId: Int = 0
    var nodeId: Int = 0
}

final class NodeLongPressGesture: UILongPressGestureRecognizer {
    var callbackId: Int = 0
    var nodeId: Int = 0
}

// MARK: - Sheet Dismiss Delegate

final class SheetDismissDelegate: NSObject, UIAdaptivePresentationControllerDelegate {
    let callbackId: Int
    let nodeId: Int

    init(callbackId: Int, nodeId: Int) {
        self.callbackId = callbackId
        self.nodeId = nodeId
    }

    func presentationControllerDidDismiss(_ presentationController: UIPresentationController) {
        if callbackId != 0 {
            NativeElementBridge.sendSheetDismissEvent(callbackId, nodeId: nodeId)
        }
    }
}

// MARK: - UIColor Extension

extension UIColor {
    convenience init(argb: Int) {
        let v = UInt32(bitPattern: Int32(truncatingIfNeeded: argb))
        let a = CGFloat((v >> 24) & 0xFF) / 255.0
        let r = CGFloat((v >> 16) & 0xFF) / 255.0
        let g = CGFloat((v >> 8) & 0xFF) / 255.0
        let b = CGFloat(v & 0xFF) / 255.0
        self.init(red: r, green: g, blue: b, alpha: a)
    }
}

// MARK: - Color Helpers (keep for backward compat with other files)

extension Color {
    init(argb: Int) {
        let a = Double((argb >> 24) & 0xFF) / 255.0
        let r = Double((argb >> 16) & 0xFF) / 255.0
        let g = Double((argb >> 8) & 0xFF) / 255.0
        let b = Double(argb & 0xFF) / 255.0
        self.init(.sRGB, red: r, green: g, blue: b, opacity: a)
    }
}

func hasEdges(_ top: Float, _ right: Float, _ bottom: Float, _ left: Float) -> Bool {
    top > 0 || right > 0 || bottom > 0 || left > 0
}
