import SwiftUI

// MARK: - Node Layout Modifier

/// Applies layout properties from a NativeUINode to a SwiftUI view.
/// Sets LayoutValueKeys for FlexContainer to read, and applies
/// padding/frame/aspectRatio modifiers directly.
struct NodeLayoutModifier: ViewModifier {
    let layout: NodeLayout?
    let availableWidth: CGFloat
    let availableHeight: CGFloat
    let safeAreaTop: CGFloat
    let safeAreaBottom: CGFloat

    func body(content: Content) -> some View {
        content
            // NOTE: LayoutValueKeys are set in NodeView.body (outermost level)
            // because keys set inside ViewModifier.body don't propagate to parent Layouts.
            // Apply size constraints — single .frame() to avoid stacking proposal issues
            .frame(
                minWidth: minWidth ?? 0,
                idealWidth: fixedWidth,
                maxWidth: resolvedMaxWidth,
                minHeight: minHeight ?? 0,
                idealHeight: fixedHeight,
                maxHeight: resolvedMaxHeight,
                alignment: .topLeading
            )
            // Base padding (explicit padding from PHP, no safe area).
            // Combined into a single EdgeInsets-based call so each NodeView
            // adds one _PaddingLayout to the SwiftUI graph instead of four.
            .padding(EdgeInsets(
                top: CGFloat(layout?.paddingTop ?? 0),
                leading: CGFloat(layout?.paddingLeft ?? 0),
                bottom: CGFloat(layout?.paddingBottom ?? 0),
                trailing: CGFloat(layout?.paddingRight ?? 0)
            ))
            // Safe area as safeAreaPadding — view extends behind notch,
            // content insets below it, scrollable content flows under it.
            // Wrapped in a conditional modifier so nodes that don't opt in
            // pay zero cost (the unconditional version showed up heavily in
            // SafeAreaInsets.adjust during steady-state profiling).
            // safe_area encodes which edges to inset:
            //   0 = none, 1 = both (legacy), 2 = top only, 3 = bottom only
            // Top-only / bottom-only let a layout's wrapper free one edge so
            // a chrome bar (NavBar at top, TabBar at bottom) can extend its
            // bg through the corresponding system inset zone, while the
            // wrapper still handles the other edge for the screen content.
            .modifier(ConditionalSafeAreaModifier(
                enabled: layout?.safeArea != 0,
                top: (layout?.safeArea == 1 || layout?.safeArea == 2) ? safeAreaTop : 0,
                bottom: (layout?.safeArea == 1 || layout?.safeArea == 3) ? safeAreaBottom : 0
            ))
            // Aspect ratio
            .modifier(AspectRatioModifier(ratio: layout?.aspectRatio))
            // Hidden
            .opacity(layout?.display == Display.none ? 0 : 1)
    }

    /// For fill mode, set max=.infinity so the frame respects the parent's
    /// actual proposed cross axis (instead of forcing the full screen size).
    /// `fixedWidth` still returns `availableWidth` as the *ideal*, which keeps
    /// SwiftUI's ideal-size discovery sensible at the root, but nested w-full
    /// elements no longer overflow when their parent is narrower than the screen.
    private var resolvedMaxWidth: CGFloat {
        if let l = layout {
            if l.widthMode == SizeMode.fill { return .infinity }
            if l.widthMode == SizeMode.percent, l.width > 0 {
                return availableWidth * CGFloat(l.width) / 100
            }
            if l.widthMode == SizeMode.fixed, l.width > 0 { return CGFloat(l.width) }
            if l.maxWidth > 0 { return CGFloat(l.maxWidth) }
        }
        return .infinity
    }

    private var resolvedMaxHeight: CGFloat {
        if let l = layout {
            if l.heightMode == SizeMode.fill { return .infinity }
            if l.heightMode == SizeMode.percent, l.height > 0 {
                return availableHeight * CGFloat(l.height) / 100
            }
            if l.heightMode == SizeMode.fixed, l.height > 0 { return CGFloat(l.height) }
            if l.maxHeight > 0 { return CGFloat(l.maxHeight) }
        }
        return .infinity
    }

    // MARK: - Size Resolution

    private var fixedWidth: CGFloat? {
        guard let l = layout else { return nil }
        if l.widthMode == SizeMode.fixed, l.width > 0 { return CGFloat(l.width) }
        // FILL: don't set idealWidth — let SwiftUI use the parent's proposal
        // directly via the maxWidth=.infinity bound. Forcing idealWidth here
        // pulls nested w-full elements past their parent's actual width.
        // Percent mode: resolve as a fraction of the available parent width.
        // `l.width` is the percent numeric (e.g. 75 for "75%").
        if l.widthMode == SizeMode.percent, l.width > 0 {
            return availableWidth * CGFloat(l.width) / 100
        }
        return nil
    }

    private var fixedHeight: CGFloat? {
        guard let l = layout else { return nil }
        if l.heightMode == SizeMode.fixed, l.height > 0 { return CGFloat(l.height) }
        // FILL: same reasoning as fixedWidth above.
        if l.heightMode == SizeMode.percent, l.height > 0 {
            return availableHeight * CGFloat(l.height) / 100
        }
        return nil
    }

    private var minWidth: CGFloat? {
        guard let l = layout, l.minWidth > 0 else { return nil }
        return CGFloat(l.minWidth)
    }

    private var minHeight: CGFloat? {
        guard let l = layout, l.minHeight > 0 else { return nil }
        return CGFloat(l.minHeight)
    }


}

// MARK: - Aspect Ratio Modifier

/// Conditionally applies .aspectRatio() only when a valid ratio is set.
private struct AspectRatioModifier: ViewModifier {
    let ratio: Float?

    func body(content: Content) -> some View {
        if let ratio, ratio > 0, ratio.isFinite {
            content.aspectRatio(CGFloat(ratio), contentMode: .fit)
        } else {
            content
        }
    }
}

// MARK: - Conditional Safe Area Modifier

/// Applies `.safeAreaPadding(.top/.bottom)` only when the node opts in.
/// Skipping the modifier entirely (vs. passing 0) avoids per-node
/// SafeAreaInsets.adjust work that SwiftUI does for any safeAreaPadding
/// in the chain, which dominated steady-state main-thread samples.
private struct ConditionalSafeAreaModifier: ViewModifier {
    let enabled: Bool
    let top: CGFloat
    let bottom: CGFloat

    func body(content: Content) -> some View {
        if enabled {
            content
                .safeAreaPadding(.top, top)
                .safeAreaPadding(.bottom, bottom)
        } else {
            content
        }
    }
}
