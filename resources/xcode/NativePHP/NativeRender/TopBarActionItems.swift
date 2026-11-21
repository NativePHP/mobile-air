import SwiftUI
import UIKit

/// Puts a screen's top-bar actions (its `top_bar_action` children, from
/// `NavBar` / `NavAction`) in the navigation bar as real `UIBarButtonItem`s
/// on the hosting controller's `navigationItem`, instead of SwiftUI
/// `.topBarTrailing` toolbar items.
///
/// Measured frame by frame on the iOS 26.2 simulator, SwiftUI-owned bar
/// items empty the outgoing tab's glass capsule for 1-5 frames before
/// TabView's crossfade (a stock SwiftUI app does it too; UITabBarController
/// doesn't), and snap when a republish changes the action set on the same
/// screen. `setRightBarButtonItems(_:animated:)` morphs the capsule
/// natively, and push / pop still morph. SwiftUI keeps owning the leading
/// button and the `.principal` title without clobbering the right items, so
/// screens using this must not declare ANY `.topBarTrailing` toolbar items
/// of their own, or the two would fight over the slot.
///
/// Shared by both chrome renderers. Empty `actions` clears the slot (the
/// tabs renderer's placeholder level relies on that).
struct TopBarActionItemsModifier: ViewModifier {
    let actions: [NativeUINode]
    let textArgb: Int

    func body(content: Content) -> some View {
        content.background(
            TopBarActionItemsProbe(actions: actions, textArgb: textArgb)
                .frame(width: 0, height: 0)
                .allowsHitTesting(false)
                .accessibilityHidden(true)
        )
    }
}

/// Zero-size representable whose controller SwiftUI adds as a child of the
/// screen's hosting controller — the only handle SwiftUI gives us on the
/// `navigationItem` the bar reads from.
private struct TopBarActionItemsProbe: UIViewControllerRepresentable {
    let actions: [NativeUINode]
    let textArgb: Int

    func makeUIViewController(context: Context) -> TopBarActionItemsController {
        let controller = TopBarActionItemsController()
        controller.actions = actions
        controller.textArgb = textArgb
        return controller
    }

    func updateUIViewController(_ controller: TopBarActionItemsController, context: Context) {
        controller.actions = actions
        controller.textArgb = textArgb
        controller.apply(animated: true)
    }

    // No dismantle hook clearing the slot: SwiftUI dismantles the outgoing
    // tab's probe as a tab switch starts, while that screen is still on
    // screen, and clearing there emptied its capsule for the whole
    // crossfade. Every screen carries this modifier, so whichever probe
    // replaces this one applies its own items (or clears them).
}

/// Owns the hosting controller's `rightBarButtonItems`.
///
/// Items are keyed by the action's PHP `id` (position when it has none) and
/// the same `UIBarButtonItem` is reused and updated in place while its key
/// survives, so a republish that only flips `disabled` or swaps an icon
/// doesn't rebuild the capsule; the item list is only handed to UIKit when
/// it actually changes. Press handlers resolve the latest node for their key
/// at tap time: callback ids can change between publishes, and an open menu
/// keeps the closures it was built with.
final class TopBarActionItemsController: UIViewController {
    var actions: [NativeUINode] = []
    var textArgb = 0

    /// The hosting controller whose `navigationItem` we manage.
    private weak var host: UIViewController?
    private var entries: [String: Entry] = [:]
    /// Latest node for every action and menu item, by key path.
    private var nodesByKey: [String: NativeUINode] = [:]

    override func loadView() {
        view = UIView(frame: .zero)
        view.isUserInteractionEnabled = false
    }

    // Both land before a pushed screen's transition starts (the hosting
    // controller is already in the navigation controller by
    // `viewWillAppear`), so the items are there for the push to morph to.
    // Neither animates: the screen isn't on screen yet.
    override func didMove(toParent parent: UIViewController?) {
        super.didMove(toParent: parent)
        apply(animated: false)
    }

    override func viewWillAppear(_ animated: Bool) {
        super.viewWillAppear(animated)
        apply(animated: false)
    }

    /// The nearest ancestor sitting directly in a navigation controller —
    /// the per-destination hosting controller NavigationStack pushes.
    private func findHost() -> UIViewController? {
        var candidate = parent
        while let controller = candidate {
            if controller.parent is UINavigationController { return controller }
            candidate = controller.parent
        }
        return nil
    }

    func apply(animated: Bool) {
        let keyed = Self.keyed(actions, under: "")

        var nodes: [String: NativeUINode] = [:]
        Self.register(keyed, into: &nodes)
        nodesByKey = nodes

        var next: [String: Entry] = [:]
        for (key, node) in keyed {
            let kind = Entry.Kind(node)
            let entry = entries[key].flatMap { $0.kind == kind ? $0 : nil } ?? makeEntry(kind, key: key)
            configure(entry, key: key, with: node)
            next[key] = entry
        }
        entries = next

        guard let host = findHost() else { return }
        let isNewHost = host !== self.host
        self.host = host

        // rightBarButtonItems[0] is the rightmost; PHP lists actions left
        // to right.
        let items = keyed.reversed().compactMap { next[$0.key]?.item }
        if Self.identical(host.navigationItem.rightBarButtonItems ?? [], items) { return }

        let animate = animated && !isNewHost
            && host.viewIfLoaded?.window != nil
            && host.transitionCoordinator == nil
        host.navigationItem.setRightBarButtonItems(items, animated: animate)
    }

    // MARK: Entries

    private final class Entry {
        enum Kind {
            /// Icon (or avatar) only — the default.
            case glyph
            /// `show_label` with no icon or image: a text button.
            case text
            /// `show_label` with an icon or image: label and glyph side by
            /// side, which UIBarButtonItem can't draw, hence a custom view.
            case labeled

            init(_ node: NativeUINode) {
                let props = node.props
                guard props.getBool("show_label"), !props.getString("label").isEmpty else {
                    self = .glyph
                    return
                }
                self = props.getString("icon").isEmpty && props.getString("image").isEmpty
                    ? .text
                    : .labeled
            }
        }

        let kind: Kind
        let item: UIBarButtonItem
        let button: UIButton?
        /// Node and color last configured from, to skip no-op republishes.
        var configured: NativeUINode?
        var configuredArgb: Int?
        /// The avatar URL currently wanted, so a late load for an old URL
        /// doesn't land.
        var imageURL = ""

        init(kind: Kind, item: UIBarButtonItem, button: UIButton? = nil) {
            self.kind = kind
            self.item = item
            self.button = button
        }
    }

    private func makeEntry(_ kind: Entry.Kind, key: String) -> Entry {
        switch kind {
        case .glyph, .text:
            return Entry(kind: kind, item: UIBarButtonItem(image: nil, style: .plain, target: nil, action: nil))
        case .labeled:
            let button = UIButton(type: .system)
            button.configuration = .plain()
            button.addAction(UIAction { [weak self] _ in
                // A button showing its menu as the primary action doesn't
                // fire this, but don't rely on it.
                guard let self, let node = self.nodesByKey[key], !Self.hasMenu(node) else { return }
                self.press(key)
            }, for: .primaryActionTriggered)
            return Entry(kind: kind, item: UIBarButtonItem(customView: button), button: button)
        }
    }

    private func configure(_ entry: Entry, key: String, with node: NativeUINode) {
        if let configured = entry.configured, entry.configuredArgb == textArgb,
           Self.sameContent(configured, node) {
            return
        }
        entry.configured = node
        entry.configuredArgb = textArgb

        let props = node.props
        let item = entry.item
        let label = props.getString("label")
        let a11yLabel = props.getString("a11y_label")
        let disabled = props.getBool("disabled")
        let menu = Self.hasMenu(node) ? buildMenu(node, key: key) : nil

        switch entry.kind {
        case .glyph, .text:
            if entry.kind == .text {
                item.title = label
                item.image = nil
                item.accessibilityLabel = a11yLabel.isEmpty ? nil : a11yLabel
            } else {
                item.title = nil
                setGlyph(of: node, on: entry)
                // `a11y_label`, else the label, else UIKit's default (the
                // symbol's own name, `building.2` reads as "Business").
                let name = a11yLabel.isEmpty ? label : a11yLabel
                item.accessibilityLabel = name.isEmpty ? Self.customIconName(props.getString("icon")) : name
            }
            if let menu {
                // With an action set, the menu would only open on long press.
                item.target = nil
                item.action = nil
                item.menu = menu
            } else {
                item.menu = nil
                item.target = self
                item.action = #selector(barItemTapped(_:))
            }
            item.tintColor = barTint
        case .labeled:
            guard let button = entry.button else { return }
            var config = button.configuration ?? .plain()
            config.title = label
            config.imagePadding = 6
            // Today's SwiftUI label weight. The plain configuration takes
            // its foreground from tint, which in a nav bar custom view is the
            // app accent, so pin it to the label color as the SwiftUI
            // `.primary` did.
            config.titleTextAttributesTransformer = UIConfigurationTextAttributesTransformer { attributes in
                var attributes = attributes
                attributes.font = UIFont.systemFont(ofSize: 17, weight: .semibold)
                return attributes
            }
            config.baseForegroundColor = textArgb != 0 ? UIColor(argb: textArgb) : .label
            button.configuration = config
            setGlyph(of: node, on: entry)
            button.accessibilityLabel = a11yLabel.isEmpty ? nil : a11yLabel
            button.menu = menu
            button.showsMenuAsPrimaryAction = menu != nil
            button.isEnabled = !disabled
        }
        item.isEnabled = !disabled
    }

    /// Unset `text_color` keeps today's look: the SwiftUI items were forced
    /// to `.primary`. Liquid Glass bar items are already monochrome by
    /// default, so iOS 26 leaves the tint alone; earlier releases would tint
    /// them with the app accent.
    private var barTint: UIColor? {
        if textArgb != 0 { return UIColor(argb: textArgb) }
        if #available(iOS 26.0, *) { return nil }
        return .label
    }

    /// The icon now, swapped for the `image` (e.g. the signed-in user's
    /// avatar) once it loads. The icon stays if the load fails.
    private func setGlyph(of node: NativeUINode, on entry: Entry) {
        let icon = node.props.getString("icon")
        let url = node.props.getString("image")
        entry.imageURL = url

        var glyph = Self.barIcon(icon.isEmpty ? "ellipsis" : icon)
        if !url.isEmpty {
            if let avatar = TopBarAvatarCache.cached(url) {
                glyph = avatar
            } else {
                TopBarAvatarCache.load(url) { [weak entry] avatar in
                    guard let entry, let avatar, entry.imageURL == url else { return }
                    Self.show(avatar, on: entry)
                }
            }
        }
        Self.show(glyph, on: entry)
    }

    private static func show(_ glyph: UIImage?, on entry: Entry) {
        switch entry.kind {
        case .glyph: entry.item.image = glyph
        case .labeled: entry.button?.configuration?.image = glyph
        case .text: break
        }
    }

    // MARK: Presses

    @objc private func barItemTapped(_ sender: UIBarButtonItem) {
        guard let key = entries.first(where: { $0.value.item === sender })?.key else { return }
        press(key)
    }

    private func press(_ key: String) {
        guard let node = nodesByKey[key], node.onPress != 0 else { return }
        NativeElementBridge.sendPressEvent(node.onPress, nodeId: node.id)
    }

    // MARK: Menus

    private static func hasMenu(_ node: NativeUINode) -> Bool {
        node.children.contains { $0.type == "top_bar_action" }
    }

    private func buildMenu(_ node: NativeUINode, key: String) -> UIMenu {
        UIMenu(children: menuElements(node.children, under: key, topKey: key))
    }

    /// One level of menu items. `divider` items split their siblings into
    /// `.displayInline` groups, which UIKit separates with a line exactly
    /// where the divider sat; leading, trailing and doubled dividers just
    /// leave empty groups, which are dropped.
    private func menuElements(_ nodes: [NativeUINode], under parent: String, topKey: String) -> [UIMenuElement] {
        var groups: [[UIMenuElement]] = [[]]
        for (key, node) in Self.keyed(nodes, under: parent) {
            if node.props.getBool("divider") {
                groups.append([])
            } else {
                groups[groups.count - 1].append(menuElement(node, key: key, topKey: topKey))
            }
        }
        let filled = groups.filter { !$0.isEmpty }
        guard filled.count > 1 else { return filled.first ?? [] }
        return filled.map { UIMenu(options: .displayInline, children: $0) }
    }

    private func menuElement(_ node: NativeUINode, key: String, topKey: String) -> UIMenuElement {
        let props = node.props
        let label = props.getString("label")
        let subtitle = props.getString("subtitle")

        // An inline group with an optional heading.
        if props.getBool("section") {
            return UIMenu(title: label, options: .displayInline,
                          children: menuElements(node.children, under: key, topKey: topKey))
        }

        // The compact row of icon-over-label buttons at the top of Notes'
        // menu. Dividers mean nothing inside it.
        if props.getBool("row") {
            let children = Self.keyed(node.children, under: key)
                .filter { !$0.node.props.getBool("divider") }
                .map { menuElement($0.node, key: $0.key, topKey: topKey) }
            let row = UIMenu(title: label, options: .displayInline, children: children)
            row.preferredElementSize = .medium
            return row
        }

        let image = menuImage(node, topKey: topKey)
        let destructive = props.getBool("destructive")
        let disabled = props.getBool("disabled")

        // A submenu opens in place behind a chevron. UIMenu can't be
        // disabled, so a disabled one falls through to a disabled row.
        if Self.hasMenu(node) && !disabled {
            return UIMenu(title: label, subtitle: subtitle.isEmpty ? nil : subtitle, image: image,
                          options: destructive ? .destructive : [],
                          children: menuElements(node.children, under: key, topKey: topKey))
        }

        var attributes: UIMenuElement.Attributes = []
        if destructive { attributes.insert(.destructive) }
        if disabled { attributes.insert(.disabled) }
        let action = UIAction(title: label, image: image, attributes: attributes,
                              state: props.getBool("checked") ? .on : .off) { [weak self] _ in
            self?.press(key)
        }
        if !subtitle.isEmpty { action.subtitle = subtitle }
        return action
    }

    /// A menu row's icon, or its `image` once loaded. Menus are immutable,
    /// so a fresh load rebuilds the owning action's menu; a failed one
    /// leaves the icon.
    private func menuImage(_ node: NativeUINode, topKey: String) -> UIImage? {
        let url = node.props.getString("image")
        if !url.isEmpty {
            if let avatar = TopBarAvatarCache.cached(url) { return avatar }
            TopBarAvatarCache.load(url) { [weak self] avatar in
                guard avatar != nil else { return }
                self?.refreshMenu(topKey)
            }
        }
        let icon = node.props.getString("icon")
        return icon.isEmpty ? nil : Self.menuIcon(icon)
    }

    private func refreshMenu(_ key: String) {
        guard let entry = entries[key], let node = entry.configured, Self.hasMenu(node) else { return }
        let menu = buildMenu(node, key: key)
        if let button = entry.button {
            button.menu = menu
        } else {
            entry.item.menu = menu
        }
    }

    // MARK: Keys

    /// `top_bar_action` children paired with their key: the parent's key
    /// plus the PHP `id`, or the position when there's none. A repeated id
    /// gets its position appended so siblings never share a key.
    private static func keyed(_ nodes: [NativeUINode], under parent: String) -> [(key: String, node: NativeUINode)] {
        var seen = Set<String>()
        var result: [(key: String, node: NativeUINode)] = []
        for (index, node) in nodes.enumerated() where node.type == "top_bar_action" {
            let id = node.props.getString("id")
            var key = parent + "/" + (id.isEmpty ? "#\(index)" : id)
            if !seen.insert(key).inserted {
                key += "#\(index)"
                seen.insert(key)
            }
            result.append((key, node))
        }
        return result
    }

    private static func register(_ keyed: [(key: String, node: NativeUINode)], into nodes: inout [String: NativeUINode]) {
        for (key, node) in keyed {
            nodes[key] = node
            register(Self.keyed(node.children, under: key), into: &nodes)
        }
    }

    // MARK: Comparison

    /// Same type, props and child structure, ignoring the per-publish node
    /// ids and callback ids (presses look those up at tap time).
    private static func sameContent(_ a: NativeUINode, _ b: NativeUINode) -> Bool {
        a === b || (a.type == b.type && a.props == b.props
            && a.children.count == b.children.count
            && zip(a.children, b.children).allSatisfy { sameContent($0, $1) })
    }

    private static func identical(_ a: [UIBarButtonItem], _ b: [UIBarButtonItem]) -> Bool {
        a.count == b.count && zip(a, b).allSatisfy { $0 === $1 }
    }

    // MARK: Icons

    /// A bar icon, resolved like `NativeIconView`: a custom icon's template
    /// asset framed to match the symbol it replaces, else the mapped SF
    /// Symbol. 17pt semibold is what the SwiftUI items drew, and still
    /// matches the SwiftUI back chevron beside them.
    private static func barIcon(_ name: String) -> UIImage? {
        if let custom = customIcon(name, side: NativeIcon.side(forPointSize: 17)) { return custom }
        let config = UIImage.SymbolConfiguration(pointSize: 17, weight: .semibold)
        return UIImage(systemName: getIconForName(name), withConfiguration: config)
    }

    /// A menu row icon. Menus size symbols themselves; custom icons get the
    /// same frame as in the bar.
    private static func menuIcon(_ name: String) -> UIImage? {
        if let custom = customIcon(name, side: NativeIcon.side(forPointSize: 17)) { return custom }
        return UIImage(systemName: getIconForName(name))
    }

    private static var customIcons: [String: UIImage] = [:]

    /// `custom:<name>` drawn into a `side`-point square as a template image,
    /// so tint applies. Nil for anything else, or a missing asset (which
    /// then falls back to the system symbol for the bare name).
    private static func customIcon(_ name: String, side: CGFloat) -> UIImage? {
        guard let asset = NativeIcon.customAssetName(name) else { return nil }
        let cacheKey = "\(asset)@\(side)"
        if let cached = customIcons[cacheKey] { return cached }
        guard let source = UIImage(named: asset) else { return nil }

        let fit = min(side / max(source.size.width, 1), side / max(source.size.height, 1))
        let size = CGSize(width: source.size.width * fit, height: source.size.height * fit)
        let image = UIGraphicsImageRenderer(size: CGSize(width: side, height: side)).image { _ in
            source.draw(in: CGRect(x: (side - size.width) / 2, y: (side - size.height) / 2,
                                   width: size.width, height: size.height))
        }.withRenderingMode(.alwaysTemplate)
        // Without a label VoiceOver would read the asset path.
        image.accessibilityLabel = customIconName(name)
        customIcons[cacheKey] = image
        return image
    }

    /// The spoken name of a `custom:<name>` icon, as `NativeIcon` gives it.
    private static func customIconName(_ name: String) -> String? {
        NativeIcon.customName(name)?.replacingOccurrences(of: "-", with: " ")
    }
}

/// Remote `image` props (avatars) as 28pt circles, kept in memory by URL so
/// a republish, or the next screen showing the same avatar, doesn't refetch.
/// Main thread only.
private enum TopBarAvatarCache {
    private static var images: [String: UIImage] = [:]
    private static var waiting: [String: [(UIImage?) -> Void]] = [:]

    static func cached(_ url: String) -> UIImage? {
        images[url]
    }

    /// Calls back on the main thread, with nil when the load or decode
    /// fails. Concurrent requests for one URL share a single fetch.
    static func load(_ urlString: String, completion: @escaping (UIImage?) -> Void) {
        if let image = images[urlString] {
            completion(image)
            return
        }
        if waiting[urlString] != nil {
            waiting[urlString]?.append(completion)
            return
        }
        guard let url = URL(string: urlString) else {
            completion(nil)
            return
        }
        waiting[urlString] = [completion]
        URLSession.shared.dataTask(with: url) { data, _, _ in
            let source = data.flatMap { UIImage(data: $0) }
            DispatchQueue.main.async {
                let image = source.map(circle)
                if let image {
                    // Avatars, not a gallery: a small bound is plenty.
                    if images.count >= 64 { images.removeAll() }
                    images[urlString] = image
                }
                for callback in waiting.removeValue(forKey: urlString) ?? [] {
                    callback(image)
                }
            }
        }.resume()
    }

    /// Aspect-fills `source` into a 28pt circle, rendered original so the
    /// bar's tint doesn't flatten it.
    private static func circle(_ source: UIImage) -> UIImage {
        let side: CGFloat = 28
        let fill = max(side / max(source.size.width, 1), side / max(source.size.height, 1))
        let size = CGSize(width: source.size.width * fill, height: source.size.height * fill)
        return UIGraphicsImageRenderer(size: CGSize(width: side, height: side)).image { _ in
            UIBezierPath(ovalIn: CGRect(x: 0, y: 0, width: side, height: side)).addClip()
            source.draw(in: CGRect(x: (side - size.width) / 2, y: (side - size.height) / 2,
                                   width: size.width, height: size.height))
        }.withRenderingMode(.alwaysOriginal)
    }
}
