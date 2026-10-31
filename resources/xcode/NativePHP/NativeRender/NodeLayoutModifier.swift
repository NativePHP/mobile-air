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
    /// Keeps the padding layer when the padding is zero, so the node can
    /// gain padding later without SwiftUI rebuilding what's under it. Nodes
    /// that keep state (`NativeUINode.keepsState`) need that; others save a
    /// layer.
    var alwaysPads = true

    func body(content: Content) -> some View {
        apply(to: content)
    }

    /// The same layers as `body`, put straight onto `content`.
    /// `ResolvedNodeView` uses this for every node, which saves SwiftUI a
    /// modifier level per node.
    func apply<V: View>(to content: V) -> some View {
        content
            // NOTE: LayoutValueKeys are set in NodeView.body (outermost level)
            // because keys set inside ViewModifier.body don't propagate to parent Layouts.
            // Base padding (explicit padding from PHP, no safe area).
            // Combined into a single EdgeInsets-based call so each NodeView
            // adds one _PaddingLayout to the SwiftUI graph instead of four,
            // and none when there's no padding (see `alwaysPads`).
            .nodeModifier(if: alwaysPads || hasPadding) {
                $0.padding(EdgeInsets(
                    top: CGFloat(layout?.paddingTop ?? 0),
                    leading: CGFloat(layout?.paddingLeft ?? 0),
                    bottom: CGFloat(layout?.paddingBottom ?? 0),
                    trailing: CGFloat(layout?.paddingRight ?? 0)
                ))
            }
            // Apply size constraints — single .frame() to avoid stacking proposal issues.
            // The frame sits outside the padding so sizes include it, like CSS
            // `box-sizing: border-box` and like Android: `min-h-12 py-3` is
            // 48pt tall, not 72.
            .frame(
                minWidth: minWidth ?? 0,
                idealWidth: fixedWidth,
                maxWidth: resolvedMaxWidth,
                minHeight: minHeight ?? 0,
                idealHeight: fixedHeight,
                maxHeight: resolvedMaxHeight,
                alignment: .topLeading
            )
            // Safe area as safeAreaPadding — view extends behind notch,
            // content insets below it, scrollable content flows under it.
            // Only for nodes that opt in (the unconditional version showed up
            // heavily in SafeAreaInsets.adjust during steady-state profiling).
            // safe_area encodes which edges to inset:
            //   0 = none, 1 = both (legacy), 2 = top only, 3 = bottom only
            // Top-only / bottom-only let a layout's wrapper free one edge so
            // a chrome bar (NavBar at top, TabBar at bottom) can extend its
            // bg through the corresponding system inset zone, while the
            // wrapper still handles the other edge for the screen content.
            .nodeModifier(if: layout?.safeArea != 0) {
                $0.safeAreaPadding(.top, (layout?.safeArea == 1 || layout?.safeArea == 2) ? safeAreaTop : 0)
                    .safeAreaPadding(.bottom, (layout?.safeArea == 1 || layout?.safeArea == 3) ? safeAreaBottom : 0)
            }
            // Aspect ratio
            .nodeModifier(if: aspectRatio != nil) {
                $0.aspectRatio(aspectRatio, contentMode: .fit)
            }
            // Hidden (`display: none`) is applied by `ResolvedNodeView`,
            // outside the style layer. See the note there.
    }

    /// For fill mode, set max=.infinity so the frame respects the parent's
    /// actual proposed cross axis (instead of forcing the full screen size).
    /// `fixedWidth` still returns `availableWidth` as the *ideal*, which keeps
    /// SwiftUI's ideal-size discovery sensible at the root, but nested w-full
    /// elements no longer overflow when their parent is narrower than the screen.
    private var resolvedMaxWidth: CGFloat {
        guard let l = layout else { return .infinity }

        let base: CGFloat
        if l.widthMode == SizeMode.fill {
            base = .infinity
        } else if l.widthMode == SizeMode.percent, l.width > 0 {
            base = availableWidth * CGFloat(l.width) / 100
        } else if l.widthMode == SizeMode.fixed, l.width > 0 {
            base = CGFloat(l.width)
        } else {
            base = .infinity
        }

        // `max-w-*` clamps whatever the width mode resolved to rather than
        // being an alternative to it — `w-full max-w-[280px]` has to fill up
        // to 280, not fill unbounded. 0 means unset on the wire.
        return l.maxWidth > 0 ? min(base, CGFloat(l.maxWidth)) : base
    }

    private var resolvedMaxHeight: CGFloat {
        guard let l = layout else { return .infinity }

        let base: CGFloat
        if l.heightMode == SizeMode.fill {
            base = .infinity
        } else if l.heightMode == SizeMode.percent, l.height > 0 {
            base = availableHeight * CGFloat(l.height) / 100
        } else if l.heightMode == SizeMode.fixed, l.height > 0 {
            base = CGFloat(l.height)
        } else {
            base = .infinity
        }

        return l.maxHeight > 0 ? min(base, CGFloat(l.maxHeight)) : base
    }

    private var hasPadding: Bool {
        guard let l = layout else { return false }
        return l.paddingTop != 0 || l.paddingRight != 0 || l.paddingBottom != 0 || l.paddingLeft != 0
    }

    private var aspectRatio: CGFloat? {
        guard let ratio = layout?.aspectRatio, ratio > 0, ratio.isFinite else { return nil }
        return CGFloat(ratio)
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
