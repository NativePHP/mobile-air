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
            // Base padding (explicit padding from PHP, no safe area)
            .padding(.top, CGFloat(layout?.paddingTop ?? 0))
            .padding(.trailing, CGFloat(layout?.paddingRight ?? 0))
            .padding(.bottom, CGFloat(layout?.paddingBottom ?? 0))
            .padding(.leading, CGFloat(layout?.paddingLeft ?? 0))
            // Safe area as safeAreaPadding — view extends behind notch,
            // content insets below it, scrollable content flows under it
            .safeAreaPadding(.top, layout?.safeArea != 0 ? safeAreaTop : 0)
            .safeAreaPadding(.bottom, layout?.safeArea != 0 ? safeAreaBottom : 0)
            // Aspect ratio
            .modifier(AspectRatioModifier(ratio: layout?.aspectRatio))
            // Hidden
            .opacity(layout?.display == Display.none ? 0 : 1)
    }

    /// For fill mode, use the exact available size so the FlexContainer
    /// receives a finite proposal and lays out children within it.
    /// Using .infinity causes SwiftUI to ask for ideal size first (which is tiny).
    private var resolvedMaxWidth: CGFloat {
        if let l = layout {
            if l.widthMode == SizeMode.fill { return availableWidth }
            if l.widthMode == SizeMode.fixed, l.width > 0 { return CGFloat(l.width) }
            if l.maxWidth > 0 { return CGFloat(l.maxWidth) }
        }
        return .infinity
    }

    private var resolvedMaxHeight: CGFloat {
        if let l = layout {
            if l.heightMode == SizeMode.fill { return availableHeight }
            if l.heightMode == SizeMode.fixed, l.height > 0 { return CGFloat(l.height) }
            if l.maxHeight > 0 { return CGFloat(l.maxHeight) }
        }
        return .infinity
    }

    // MARK: - Size Resolution

    private var fixedWidth: CGFloat? {
        guard let l = layout else { return nil }
        if l.widthMode == SizeMode.fixed, l.width > 0 { return CGFloat(l.width) }
        if l.widthMode == SizeMode.fill { return availableWidth }
        return nil
    }

    private var fixedHeight: CGFloat? {
        guard let l = layout else { return nil }
        if l.heightMode == SizeMode.fixed, l.height > 0 { return CGFloat(l.height) }
        if l.heightMode == SizeMode.fill { return availableHeight }
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
