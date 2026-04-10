import SwiftUI

// MARK: - Layout Value Keys

/// Per-child flex properties communicated to FlexContainer via LayoutValueKey.
struct FlexGrowKey: LayoutValueKey { static let defaultValue: CGFloat = 0 }
struct FlexShrinkKey: LayoutValueKey { static let defaultValue: CGFloat = 0 }
struct AlignSelfKey: LayoutValueKey { static let defaultValue: Int = 0 }
struct MarginTopKey: LayoutValueKey { static let defaultValue: CGFloat = 0 }
struct MarginRightKey: LayoutValueKey { static let defaultValue: CGFloat = 0 }
struct MarginBottomKey: LayoutValueKey { static let defaultValue: CGFloat = 0 }
struct MarginLeftKey: LayoutValueKey { static let defaultValue: CGFloat = 0 }
struct PositionTypeKey: LayoutValueKey { static let defaultValue: Int = 0 }
struct PositionTopKey: LayoutValueKey { static let defaultValue: CGFloat = 0 }
struct PositionRightKey: LayoutValueKey { static let defaultValue: CGFloat = 0 }
struct PositionBottomKey: LayoutValueKey { static let defaultValue: CGFloat = 0 }
struct PositionLeftKey: LayoutValueKey { static let defaultValue: CGFloat = 0 }
struct DisplayKey: LayoutValueKey { static let defaultValue: Int = 0 }
struct FlexBasisKey: LayoutValueKey { static let defaultValue: CGFloat = -1 } // -1 = unset

// MARK: - Flex Enums (match PHP/binary protocol values)

enum FlexDirection {
    static let column = 0
    static let row = 1
}

enum JustifyContent {
    static let start = 0
    static let center = 1
    static let end = 2
    static let spaceBetween = 3
    static let spaceAround = 4
    static let spaceEvenly = 5
}

enum AlignItems {
    static let start = 0
    static let center = 1
    static let end = 2
    static let stretch = 3
}

enum PositionType {
    static let relative = 0
    static let absolute = 1
}

enum Display {
    static let flex = 0
    static let none = 1
}

// MARK: - FlexContainer Layout

/// A SwiftUI Layout that implements CSS Flexbox semantics.
/// Replaces Yoga C++ layout engine with a pure Swift implementation
/// that integrates directly with SwiftUI's layout system.
struct FlexContainer: Layout {
    let direction: Int   // FlexDirection
    let justify: Int     // JustifyContent
    let align: Int       // AlignItems
    let gap: CGFloat
    let wrap: Int        // 0 = nowrap, 1 = wrap
    /// Direct access to child nodes — LayoutValueKeys don't propagate through ViewModifiers,
    /// so we pass the node array and index into flex properties directly.
    let childNodes: [NativeUINode]

    init(
        direction: Int = FlexDirection.column,
        justify: Int = JustifyContent.start,
        align: Int = AlignItems.stretch,
        gap: CGFloat = 0,
        wrap: Int = 0,
        childNodes: [NativeUINode] = []
    ) {
        self.direction = direction
        self.justify = justify
        self.align = align
        self.gap = gap
        self.wrap = wrap
        self.childNodes = childNodes
    }

    // MARK: - Cache

    struct CacheData {
        var childInfos: [ChildInfo] = []
        var flowIndices: [Int] = []
        var absoluteIndices: [Int] = []
    }

    struct ChildInfo {
        let flexGrow: CGFloat
        let flexShrink: CGFloat
        let alignSelf: Int
        let marginTop: CGFloat
        let marginRight: CGFloat
        let marginBottom: CGFloat
        let marginLeft: CGFloat
        let positionType: Int
        let positionTop: CGFloat
        let positionRight: CGFloat
        let positionBottom: CGFloat
        let positionLeft: CGFloat
        let display: Int
        let flexBasis: CGFloat
        var idealSize: CGSize = .zero
    }

    func makeCache(subviews: Subviews) -> CacheData {
        var cache = CacheData()
        cache.childInfos.reserveCapacity(subviews.count)
        cache.flowIndices.reserveCapacity(subviews.count)
        cache.absoluteIndices.reserveCapacity(subviews.count)

        for (i, _) in subviews.enumerated() {
            let layout = i < childNodes.count ? childNodes[i].layout : nil
            let info = ChildInfo(
                flexGrow: CGFloat(layout?.flexGrow ?? 0),
                flexShrink: CGFloat(layout?.flexShrink ?? 0),
                alignSelf: layout?.alignSelf ?? 0,
                marginTop: CGFloat(layout?.marginTop ?? 0),
                marginRight: CGFloat(layout?.marginRight ?? 0),
                marginBottom: CGFloat(layout?.marginBottom ?? 0),
                marginLeft: CGFloat(layout?.marginLeft ?? 0),
                positionType: layout?.positionType ?? 0,
                positionTop: CGFloat(layout?.positionTop ?? 0),
                positionRight: CGFloat(layout?.positionRight ?? 0),
                positionBottom: CGFloat(layout?.positionBottom ?? 0),
                positionLeft: CGFloat(layout?.positionLeft ?? 0),
                display: layout?.display ?? 0,
                flexBasis: (layout?.flexBasis ?? 0) > 0 ? CGFloat(layout!.flexBasis) : -1
            )
            cache.childInfos.append(info)

            if info.display == Display.none { continue }
            if info.positionType == PositionType.absolute {
                cache.absoluteIndices.append(i)
            } else {
                cache.flowIndices.append(i)
            }
        }
        return cache
    }

    func updateCache(_ cache: inout CacheData, subviews: Subviews) {
        cache = makeCache(subviews: subviews)
    }

    // MARK: - Axis Helpers

    private var isRow: Bool { direction == FlexDirection.row }

    private func mainSize(_ size: CGSize) -> CGFloat {
        isRow ? size.width : size.height
    }

    private func crossSize(_ size: CGSize) -> CGFloat {
        isRow ? size.height : size.width
    }

    private func makeSize(main: CGFloat, cross: CGFloat) -> CGSize {
        isRow ? CGSize(width: main, height: cross) : CGSize(width: cross, height: main)
    }

    private func mainMargin(_ info: ChildInfo) -> CGFloat {
        isRow ? info.marginLeft + info.marginRight : info.marginTop + info.marginBottom
    }

    private func crossMargin(_ info: ChildInfo) -> CGFloat {
        isRow ? info.marginTop + info.marginBottom : info.marginLeft + info.marginRight
    }

    private func mainMarginBefore(_ info: ChildInfo) -> CGFloat {
        isRow ? info.marginLeft : info.marginTop
    }

    private func crossMarginBefore(_ info: ChildInfo) -> CGFloat {
        isRow ? info.marginTop : info.marginLeft
    }

    // MARK: - sizeThatFits

    func sizeThatFits(
        proposal: ProposedViewSize,
        subviews: Subviews,
        cache: inout CacheData
    ) -> CGSize {
        guard !cache.flowIndices.isEmpty else {
            // Even with no children, fill the proposed space if finite
            return CGSize(
                width: proposal.width ?? 0,
                height: proposal.height ?? 0
            )
        }

        let proposed = CGSize(
            width: proposal.width ?? .infinity,
            height: proposal.height ?? .infinity
        )
        let proposedMain = mainSize(proposed)
        let proposedCross = crossSize(proposed)

        // Measure each flow child. When the cross dimension (width for columns)
        // is known, measure at that width so text wrapping affects the height.
        var totalMain: CGFloat = 0
        var maxCross: CGFloat = 0

        for i in cache.flowIndices {
            let info = cache.childInfos[i]
            let crossAvail = proposedCross.isFinite ? proposedCross - crossMargin(info) : nil
            let measureProposal: ProposedViewSize
            if let crossAvail {
                measureProposal = isRow
                    ? ProposedViewSize(width: nil, height: crossAvail)
                    : ProposedViewSize(width: crossAvail, height: nil)
            } else {
                measureProposal = .unspecified
            }
            let ideal = subviews[i].sizeThatFits(measureProposal)
            cache.childInfos[i].idealSize = ideal

            let childMain: CGFloat
            if info.flexBasis >= 0 {
                childMain = info.flexBasis
            } else {
                childMain = mainSize(ideal)
            }

            totalMain += childMain + mainMargin(info)
            maxCross = max(maxCross, crossSize(ideal) + crossMargin(info))
        }

        let gaps = gap * CGFloat(max(0, cache.flowIndices.count - 1))
        totalMain += gaps

        // A FlexContainer fills its proposed space when the proposal is finite.
        // This matches CSS block-level flex container behavior.
        // The parent's .frame() modifier controls what gets proposed:
        //   fill mode → proposes full parent space
        //   wrap mode → proposes ideal size
        //   fixed mode → proposes explicit size
        let finalMain: CGFloat
        if proposedMain.isFinite {
            finalMain = max(totalMain, proposedMain)
        } else {
            finalMain = totalMain
        }

        let finalCross: CGFloat
        if proposedCross.isFinite {
            finalCross = proposedCross
        } else {
            finalCross = maxCross
        }

        return makeSize(main: finalMain, cross: finalCross)
    }

    // MARK: - placeSubviews

    func placeSubviews(
        in bounds: CGRect,
        proposal: ProposedViewSize,
        subviews: Subviews,
        cache: inout CacheData
    ) {
        let flowCount = cache.flowIndices.count
        guard flowCount > 0 || !cache.absoluteIndices.isEmpty else { return }

        let containerMain = mainSize(bounds.size)
        let containerCross = crossSize(bounds.size)

        // Phase 1: Measure ideal sizes for all flow children
        var childMains = [CGFloat](repeating: 0, count: subviews.count)
        var childCrosses = [CGFloat](repeating: 0, count: subviews.count)
        var totalIdealMain: CGFloat = 0
        var totalGrow: CGFloat = 0
        var totalShrink: CGFloat = 0

        for i in cache.flowIndices {
            let info = cache.childInfos[i]
            let ideal = subviews[i].sizeThatFits(.unspecified)
            cache.childInfos[i].idealSize = ideal

            let childMain: CGFloat
            if info.flexBasis >= 0 {
                childMain = info.flexBasis
            } else {
                childMain = mainSize(ideal)
            }

            childMains[i] = childMain
            childCrosses[i] = crossSize(ideal)
            totalIdealMain += childMain + mainMargin(info)
            totalGrow += info.flexGrow
            totalShrink += info.flexShrink
        }

        let gaps = gap * CGFloat(max(0, flowCount - 1))
        let remaining = containerMain - totalIdealMain - gaps

        // Phase 2: Distribute remaining space (grow or shrink)
        if remaining > 0 && totalGrow > 0 {
            // Grow: distribute extra space by flex_grow ratio
            for i in cache.flowIndices {
                let info = cache.childInfos[i]
                if info.flexGrow > 0 {
                    childMains[i] += remaining * (info.flexGrow / totalGrow)
                }
            }
        } else if remaining < 0 && totalShrink > 0 {
            // Shrink: reduce by flex_shrink ratio
            let deficit = -remaining
            for i in cache.flowIndices {
                let info = cache.childInfos[i]
                if info.flexShrink > 0 {
                    let reduction = deficit * (info.flexShrink / totalShrink)
                    childMains[i] = max(0, childMains[i] - reduction)
                }
            }
        }

        // Phase 3: Re-measure children with cross-axis constraint.
        // Children with FILL mode (w-full) get the full container cross size.
        // Others measure at natural size for proper content wrapping.
        for i in cache.flowIndices {
            let info = cache.childInfos[i]
            let childLayout = i < childNodes.count ? childNodes[i].layout : nil
            let crossIsFill = isRow ? (childLayout?.heightMode == 2) : (childLayout?.widthMode == 2)
            let crossAvail = crossIsFill ? containerCross - crossMargin(info) : nil
            let proposedChild: ProposedViewSize
            if isRow {
                proposedChild = ProposedViewSize(width: childMains[i], height: crossAvail)
            } else {
                proposedChild = ProposedViewSize(width: crossAvail, height: childMains[i])
            }
            let measured = subviews[i].sizeThatFits(proposedChild)
            childCrosses[i] = crossSize(measured)
            // Update main size when the cross constraint changes it (e.g. text wrapping)
            let measuredMain = mainSize(measured)
            if measuredMain > childMains[i] {
                childMains[i] = measuredMain
            }
        }

        // Phase 4: Compute justify_content offsets
        let (startOffset, interItemSpacing) = justifyOffsets(
            remaining: remaining > 0 && totalGrow <= 0 ? remaining : 0,
            count: flowCount
        )

        // Phase 5: Place flow children
        var mainCursor = (isRow ? bounds.minX : bounds.minY) + startOffset

        for (flowIdx, i) in cache.flowIndices.enumerated() {
            let info = cache.childInfos[i]
            let childMain = childMains[i]

            // Main-axis position
            let mainPos = mainCursor + mainMarginBefore(info)

            // Cross-axis: determine size and position based on alignment
            let effectiveAlign = info.alignSelf > 0 ? info.alignSelf : align
            let finalCross: CGFloat
            let crossPos: CGFloat

            // Check if child explicitly wants to fill the cross axis (widthMode/heightMode == 2 = FILL)
            let childLayout = i < childNodes.count ? childNodes[i].layout : nil
            let crossFill: Bool = isRow
                ? (childLayout?.heightMode == 2)
                : (childLayout?.widthMode == 2)

            switch effectiveAlign {
            case AlignItems.stretch where crossFill:
                // Stretch only when child has FILL mode (w-full / h-full)
                finalCross = containerCross - crossMargin(info)
                crossPos = (isRow ? bounds.minY : bounds.minX) + crossMarginBefore(info)

            case AlignItems.stretch:
                // No FILL: use natural size, align to start (like Android)
                let natural = crossSize(subviews[i].sizeThatFits(.unspecified))
                finalCross = min(natural, containerCross - crossMargin(info))
                crossPos = (isRow ? bounds.minY : bounds.minX) + crossMarginBefore(info)

            case AlignItems.center:
                // Center: measure natural size, center within container
                let natural = crossSize(subviews[i].sizeThatFits(.unspecified))
                finalCross = min(natural, containerCross - crossMargin(info))
                crossPos = (isRow ? bounds.minY : bounds.minX) + (containerCross - finalCross) / 2

            case AlignItems.end:
                // End: measure natural size, align to end
                let natural = crossSize(subviews[i].sizeThatFits(.unspecified))
                finalCross = min(natural, containerCross - crossMargin(info))
                crossPos = (isRow ? bounds.minY : bounds.minX) + containerCross - finalCross - crossMarginBefore(info)

            default: // start
                // Start: use measured cross size (from Phase 3), align to start
                finalCross = childCrosses[i]
                crossPos = (isRow ? bounds.minY : bounds.minX) + crossMarginBefore(info)
            }

            let childSize: CGSize
            let childOrigin: CGPoint
            if isRow {
                childSize = CGSize(width: childMain, height: finalCross)
                childOrigin = CGPoint(x: mainPos, y: crossPos)
            } else {
                childSize = CGSize(width: finalCross, height: childMain)
                childOrigin = CGPoint(x: crossPos, y: mainPos)
            }

            subviews[i].place(
                at: childOrigin,
                proposal: ProposedViewSize(childSize)
            )

            mainCursor = mainPos + childMain + mainMargin(info) - mainMarginBefore(info)
            if flowIdx < flowCount - 1 {
                mainCursor += gap + interItemSpacing
            }
        }

        // Phase 6: Place absolute children
        for i in cache.absoluteIndices {
            placeAbsolute(subviews[i], info: cache.childInfos[i], in: bounds)
        }
    }

    // MARK: - Helpers

    /// Compute start offset and inter-item spacing for justify_content.
    private func justifyOffsets(remaining: CGFloat, count: Int) -> (startOffset: CGFloat, interItemSpacing: CGFloat) {
        guard remaining > 0 && count > 0 else { return (0, 0) }

        switch justify {
        case JustifyContent.center:
            return (remaining / 2, 0)
        case JustifyContent.end:
            return (remaining, 0)
        case JustifyContent.spaceBetween:
            if count <= 1 { return (0, 0) }
            return (0, remaining / CGFloat(count - 1))
        case JustifyContent.spaceAround:
            let spacing = remaining / CGFloat(count)
            return (spacing / 2, spacing)
        case JustifyContent.spaceEvenly:
            let spacing = remaining / CGFloat(count + 1)
            return (spacing, spacing)
        default: // start
            return (0, 0)
        }
    }

    /// Place an absolute-positioned child using position insets.
    private func placeAbsolute(_ subview: LayoutSubview, info: ChildInfo, in bounds: CGRect) {
        let ideal = subview.sizeThatFits(.unspecified)

        // Resolve horizontal position
        var x = bounds.minX + info.positionLeft
        if info.positionRight > 0 && info.positionLeft == 0 {
            x = bounds.maxX - ideal.width - info.positionRight
        }

        // Resolve vertical position
        var y = bounds.minY + info.positionTop
        if info.positionBottom > 0 && info.positionTop == 0 {
            y = bounds.maxY - ideal.height - info.positionBottom
        }

        subview.place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(ideal))
    }
}
