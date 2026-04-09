import SwiftUI
import UIKit

// MARK: - Node Style Modifier

/// Applies visual style properties from a NativeUINode to a SwiftUI view.
/// Handles background color, corner radius, border, shadow, opacity,
/// and dark mode overrides from dark_* props.
struct NodeStyleModifier: ViewModifier {
    let style: NodeStyle?
    let props: GenericProps
    @Environment(\.colorScheme) private var colorScheme

    func body(content: Content) -> some View {
        let dark = colorScheme == .dark
        let radius = cornerRadius

        content
            .background(backgroundColor(dark: dark))
            .modifier(ClipRadiusModifier(radius: radius))
            .overlay(borderOverlay(dark: dark, radius: radius))
            .shadow(
                color: shadowColor,
                radius: shadowRadius,
                x: 0,
                y: shadowY
            )
            .opacity(resolvedOpacity(dark: dark))
    }

    // MARK: - Background Color

    private func backgroundColor(dark: Bool) -> Color {
        let darkBg = dark ? props.getColor("dark_bg_color", default: 0) : 0
        let argb = darkBg != 0 ? darkBg : (style?.bgColor ?? 0)
        return colorFromARGB(argb)
    }

    // MARK: - Corner Radius

    private var cornerRadius: CGFloat {
        guard let s = style, s.borderRadius > 0 else { return 0 }
        return CGFloat(s.borderRadius)
    }

    // MARK: - Border

    private func borderOverlay(dark: Bool, radius: CGFloat) -> some View {
        let width = CGFloat(style?.borderWidth ?? 0)
        let darkBorder = dark ? props.getColor("dark_border_color", default: 0) : 0
        let argb = darkBorder != 0 ? darkBorder : (style?.borderColor ?? 0)
        let color = colorFromARGB(argb)

        return RoundedRectangle(cornerRadius: radius)
            .strokeBorder(color, lineWidth: width)
            .opacity(width > 0 ? 1 : 0)
    }

    // MARK: - Shadow

    private var shadowRadius: CGFloat {
        guard let s = style, s.elevation > 0 else { return 0 }
        return CGFloat(s.elevation)
    }

    private var shadowY: CGFloat {
        guard let s = style, s.elevation > 0 else { return 0 }
        return CGFloat(s.elevation / 2)
    }

    private var shadowColor: Color {
        guard let s = style, s.elevation > 0 else { return .clear }
        return .black.opacity(0.25)
    }

    // MARK: - Opacity

    private func resolvedOpacity(dark: Bool) -> Double {
        let darkOpacity = dark ? props.getFloat("dark_opacity") : 0
        if darkOpacity > 0 { return Double(darkOpacity) }
        return Double(style?.opacity ?? 1)
    }
}

// MARK: - ARGB Color Conversion

/// Convert a 32-bit ARGB integer to a SwiftUI Color.
/// Transparent (0x00000000) maps to Color.clear.
func colorFromARGB(_ argb: Int) -> Color {
    let v = UInt32(bitPattern: Int32(truncatingIfNeeded: argb))
    let a = Double((v >> 24) & 0xFF) / 255.0
    guard a > 0 else { return .clear }
    let r = Double((v >> 16) & 0xFF) / 255.0
    let g = Double((v >> 8) & 0xFF) / 255.0
    let b = Double(v & 0xFF) / 255.0
    return Color(.sRGB, red: r, green: g, blue: b, opacity: a)
}

/// Only clips when corner radius > 0. A zero-radius clipShape clips to a sharp
/// rectangle which cuts off content that slightly overflows (e.g. Toggle switches).
private struct ClipRadiusModifier: ViewModifier {
    let radius: CGFloat
    func body(content: Content) -> some View {
        if radius > 0 {
            content.clipShape(RoundedRectangle(cornerRadius: radius))
        } else {
            content
        }
    }
}

// MARK: - Color Extensions (used by plugin renderers)

extension Color {
    init(argb: Int) {
        let v = UInt32(bitPattern: Int32(truncatingIfNeeded: argb))
        let a = Double((v >> 24) & 0xFF) / 255.0
        let r = Double((v >> 16) & 0xFF) / 255.0
        let g = Double((v >> 8) & 0xFF) / 255.0
        let b = Double(v & 0xFF) / 255.0
        self.init(.sRGB, red: r, green: g, blue: b, opacity: a)
    }
}

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
