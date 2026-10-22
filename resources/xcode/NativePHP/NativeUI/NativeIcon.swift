import SwiftUI
import UIKit

/// Resolves the icon strings PHP sends for every icon prop (tab `icon`,
/// top-bar action `icon`, menu item `icon`, ...).
///
/// `custom:<name>` (`Icon::custom()`) is an app icon: `native:build`
/// compiles `resources/icons/<name>.svg` into a template imageset under
/// `Assets.xcassets/CustomIcons/`, so the asset is `CustomIcons/<name>`.
/// Anything else is an SF Symbol name or alias and goes through
/// `getIconForName` as before.
///
/// A custom icon whose asset is missing (Jump, or a build that predates
/// the icon) logs one warning and draws the system symbol for the bare
/// name, so `custom:bell` still shows a bell rather than nothing.
enum NativeIcon {
    static let customPrefix = "custom:"
    private static let assetFolder = "CustomIcons/"

    /// Asset lookups keyed by bare name. A stored nil means "missing,
    /// already warned", so each name is looked up and logged once.
    private static var assetNames: [String: String?] = [:]
    private static let lock = NSLock()

    /// The `<name>` of a `custom:<name>` string, else nil.
    static func customName(_ name: String) -> String? {
        guard name.hasPrefix(customPrefix) else { return nil }

        let bare = String(name.dropFirst(customPrefix.count))

        return bare.isEmpty ? nil : bare
    }

    /// "CustomIcons/<name>" when `name` is `custom:<name>` and the asset exists, else nil.
    static func customAssetName(_ name: String) -> String? {
        guard let bare = customName(name) else { return nil }

        lock.lock()
        defer { lock.unlock() }

        if let cached = assetNames[bare] {
            return cached
        }

        let asset = assetFolder + bare
        let found: String? = UIImage(named: asset) != nil ? asset : nil
        if found == nil {
            print("⚠️ Custom icon '\(bare)' has no \(asset) asset in this build, using the system icon")
        }
        assetNames[bare] = found

        return found
    }

    /// Template asset image for custom names, else Image(systemName: getIconForName(name)).
    /// Drawn at its intrinsic size (24pt for a square icon), so use it where
    /// the system sizes the image itself (menu rows, labels); call sites that
    /// size a symbol with `.font(...)` want `NativeIconView`.
    static func image(_ name: String) -> Image {
        if let asset = customAssetName(name) {
            return customImage(asset)
        }

        return Image(systemName: getIconForName(name))
    }

    /// Side of the square frame a custom icon gets in place of an SF Symbol
    /// at `pointSize`. A symbol's outline spans ~1.2× its point size (a
    /// 17pt `circle` is ~20.5pt across) while a Lucide / Material glyph
    /// fills ~21 of its 24 units, so 4/3 lines the two up.
    static func side(forPointSize pointSize: CGFloat) -> CGFloat {
        pointSize * 4 / 3
    }

    fileprivate static func customImage(_ asset: String) -> Image {
        // Without a label VoiceOver would read the asset path.
        let spoken = asset
            .dropFirst(assetFolder.count)
            .replacingOccurrences(of: "-", with: " ")

        return Image(asset, label: Text(spoken)).renderingMode(.template)
    }
}

/// Drop-in for call sites that size an SF Symbol with `.font(...)`: custom
/// icons become a resizable template image framed to match the symbol it
/// replaces at `pointSize`. A system symbol is a bare
/// `Image(systemName: getIconForName(name))` with no font of its own, so
/// callers keep applying their `.font(...)` / `.nuiScaledFont(...)` after
/// this view exactly as before, and the symbol picks it up. That font does
/// nothing to a custom icon, whose stroke is whatever its SVG drew.
///
/// Pass `relativeTo` where the symbol used to follow a Dynamic Type style
/// (e.g. list rows) so the custom icon's frame scales with the text too.
struct NativeIconView: View {
    let name: String
    let pointSize: CGFloat
    private let scalesWithText: Bool

    @ScaledMetric private var scaledPointSize: CGFloat

    init(_ name: String, pointSize: CGFloat, relativeTo textStyle: Font.TextStyle? = nil) {
        self.name = name
        self.pointSize = pointSize
        self.scalesWithText = textStyle != nil
        _scaledPointSize = ScaledMetric(wrappedValue: pointSize, relativeTo: textStyle ?? .body)
    }

    var body: some View {
        if let asset = NativeIcon.customAssetName(name) {
            let side = NativeIcon.side(forPointSize: scalesWithText ? scaledPointSize : pointSize)

            NativeIcon.customImage(asset)
                .resizable()
                .scaledToFit()
                .frame(width: side, height: side)
        } else {
            Image(systemName: getIconForName(name))
        }
    }
}

extension Tab where Value: Hashable, Content: View, Label == DefaultTabLabel {
    /// `Tab(_:systemImage:value:)` for a NativePHP icon string: a custom icon
    /// goes through `Tab(_:image:value:)` with its asset instead. Both are the
    /// non-closure initialisers, so the bar keeps its system (Liquid Glass)
    /// styling either way. The asset renders at its intrinsic size.
    init(_ title: String, nativeIcon icon: String, value: Value, role: TabRole? = nil, @ViewBuilder content: () -> Content) {
        if let asset = NativeIcon.customAssetName(icon) {
            self.init(title, image: asset, value: value, role: role, content: content)
        } else {
            self.init(title, systemImage: getIconForName(icon), value: value, role: role, content: content)
        }
    }
}
