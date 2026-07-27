import SwiftUI
import UIKit

// MARK: - Node Style Modifier

/// Applies visual style properties from a NativeUINode to a SwiftUI view.
/// Handles background color, corner radius, border, shadow, opacity,
/// and dark mode overrides from dark_* props.
struct NodeStyleModifier: ViewModifier {
    let style: NodeStyle?
    let props: GenericProps
    /// Node type string (e.g. "button", "row"). Used to skip outer-frame
    /// effects when a plugin renderer handles them internally — currently
    /// only `glass`, but easy to extend.
    let nodeType: String

    /// Plugin element types whose own renderer applies the Liquid Glass
    /// material via `.buttonStyle(.glass)` / `.glassEffect(...)` etc. For
    /// these, the outer NodeStyleModifier must NOT also draw a glass plate
    /// behind the rectangular node frame — that produces a ghost rectangle
    /// behind the visible button capsule.
    private static let glassHandledByRenderer: Set<String> = ["button", "chip", "badge"]
    @Environment(\.colorScheme) private var colorScheme

    func body(content: Content) -> some View {
        let dark = colorScheme == .dark
        let radius = cornerRadius
        let glassFlags = props.getInt("glass", default: 0)

        // Defer opacity to `NodeAnimationModifier` when:
        //   - `animate-duration > 0` (state-change transitions),
        //   - `animate-loop` is on (yoyo),
        //   - opacity is bound to a `SharedValue` (gesture-driven).
        // Otherwise multiplying would double-apply.
        let animateDuration = props.getFloat("animate-duration", default: 0)
        let animateLoop = props.getBool("animate-loop")
        let opacitySharedBound = !props.getString("opacity_sv", default: "").isEmpty
        let opacity = (animateDuration > 0 || animateLoop || opacitySharedBound)
            ? 1.0
            : resolvedOpacity(dark: dark)

        content
            // `bg-*` color sits BEHIND the content. When `glass` is also
            // set, the bg color is visible behind the glass and acts as
            // a tint — that's what you want for `bg-red-500 glass` to
            // produce tinted glass.
            .background(backgroundFill(dark: dark))
            // Liquid Glass material — iOS 26+ real glass, iOS 18-25 falls
            // back to `.regularMaterial`. Applied AFTER background so the
            // optional bg color tints through. Shape inferred from the
            // element's borderRadius (rounded-full → Capsule, rounded-N →
            // RoundedRectangle, no rounded → Rectangle).
            .modifier(GlassModifier(
                flags: Self.glassHandledByRenderer.contains(nodeType) ? 0 : glassFlags,
                cornerRadius: radius
            ))
            .modifier(ClipRadiusModifier(radius: radius))
            .overlay(borderOverlay(dark: dark, radius: radius))
            .shadow(
                color: shadowColor,
                radius: shadowRadius,
                x: 0,
                y: shadowY
            )
            .opacity(opacity)
    }

    // MARK: - Background Color

    /// The node's background: a linear gradient when one is declared
    /// (`bg-gradient-to-* from-* via-* to-*`), otherwise the flat `bg-*` color.
    ///
    /// A gradient wins over `bg_color` — matching CSS, where `background-image`
    /// paints over `background-color`.
    @ViewBuilder
    private func backgroundFill(dark: Bool) -> some View {
        if let gradient = linearGradient {
            gradient
        } else {
            backgroundColor(dark: dark)
        }
    }

    private func backgroundColor(dark: Bool) -> Color {
        let darkBg = dark ? props.getColor("dark_bg_color", default: 0) : 0
        let argb = darkBg != 0 ? darkBg : (style?.bgColor ?? 0)
        return colorFromARGB(argb)
    }

    // MARK: - Gradient

    /// Gradient props ride the props bag, not NodeStyle — the style block is a
    /// fixed-layout region of the packed binary node with no room for a
    /// variable-length stop list. `gradient_stops` is a comma-joined list of
    /// two or three `#AARRGGBB` values; `gradient_dx`/`gradient_dy` are the
    /// unit vector the gradient travels toward, in view space (y grows down).
    private var linearGradient: LinearGradient? {
        let raw = props.getString("gradient_stops", default: "")
        guard !raw.isEmpty else { return nil }

        let colors = raw
            .split(separator: ",")
            .map { colorFromARGB(ColorParser.parse(String($0))) }

        guard colors.count >= 2 else { return nil }

        let dx = CGFloat(props.getFloat("gradient_dx", default: 0))
        let dy = CGFloat(props.getFloat("gradient_dy", default: 1))

        // Tailwind's axis is a direction, not endpoints. Convert to the two
        // unit points SwiftUI wants by stepping half the vector either side of
        // center, so `to-t` runs bottom→top across the full height. Diagonals
        // (dx and dy both non-zero) land on the corners, as in CSS.
        let start = UnitPoint(x: 0.5 - dx / 2, y: 0.5 - dy / 2)
        let end = UnitPoint(x: 0.5 + dx / 2, y: 0.5 + dy / 2)

        return LinearGradient(colors: colors, startPoint: start, endPoint: end)
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

/// Applies the Liquid Glass material to an element when the `glass`
/// Tailwind class is set. iOS 26+ uses real `.glassEffect(...)`; older
/// iOS falls back to `.background(.regularMaterial / .ultraThinMaterial)`
/// — same shape inference, no specular reflection but still translucent
/// + blurred + adaptive to the content behind it.
///
/// Flags (matches `TailwindParser::parseGlassClass`):
///
///   bit 0 (1) — enabled
///   bit 1 (2) — prominent (button-only — ignored at this layer)
///   bit 2 (4) — interactive (touch-highlight feedback on the glass)
///   bit 3 (8) — clear (`.glassEffect(.clear)` — no tint backdrop;
///                fallback `.ultraThinMaterial`)
///
/// `prominent` does nothing here: Apple's `.glassEffect()` has no
/// prominent variant. Prominent flips a button to `.buttonStyle(.glassProminent)`
/// inside `NativeUIButtonRenderer`. This modifier just owns the generic
/// glass-on-arbitrary-surfaces path.
private struct GlassModifier: ViewModifier {
    let flags: Int
    let cornerRadius: CGFloat

    private var enabled: Bool      { (flags & 1) != 0 }
    private var interactive: Bool  { (flags & 4) != 0 }
    private var clear: Bool        { (flags & 8) != 0 }

    func body(content: Content) -> some View {
        if !enabled {
            content
        } else if #available(iOS 26.0, *) {
            if clear {
                content.glassEffect(.clear.interactive(interactive), in: glassShape)
            } else {
                content.glassEffect(.regular.interactive(interactive), in: glassShape)
            }
        } else {
            // Older-iOS fallback can't simulate touch-highlight — drop the
            // interactive flag silently. `.ultraThinMaterial` is the closest
            // analogue for `.clear`; `.regularMaterial` for the default.
            content.background(
                clear ? AnyShapeStyle(.ultraThinMaterial) : AnyShapeStyle(.regularMaterial),
                in: glassShape
            )
        }
    }

    /// Infer the glass shape from the element's borderRadius. The
    /// Tailwind parser maps `rounded-full` → 9999, `rounded-{xs..3xl}`
    /// → fixed pt values, no `rounded-*` → 0.
    private var glassShape: AnyShape {
        if cornerRadius >= 9999 {
            return AnyShape(Capsule())
        } else if cornerRadius > 0 {
            return AnyShape(RoundedRectangle(cornerRadius: cornerRadius))
        } else {
            return AnyShape(Rectangle())
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

/// Wraps content in `GlassEffectContainer` on iOS 26+, no-op on older iOS.
///
/// Apple recommends grouping glass effects in a container so they can
/// coordinate animations and avoid independent re-render artifacts. Without
/// a container, every glass effect operates standalone — when surrounding
/// state changes (e.g. a PHP re-render after a tap), each glass surface
/// tears down and rebuilds independently, producing a visible flicker
/// behind the touched element.
///
/// Apply this once at the screen root in each root renderer (Stack, Tabs,
/// etc.) — the container's effects are scoped to the subtree it wraps, so
/// one container per screen is the right granularity.
struct WithGlassContainer: ViewModifier {
    func body(content: Content) -> some View {
        if #available(iOS 26.0, *) {
            GlassEffectContainer { content }
        } else {
            content
        }
    }
}

extension View {
    /// Convenience for `.modifier(WithGlassContainer())`.
    func withGlassContainer() -> some View {
        modifier(WithGlassContainer())
    }
}
