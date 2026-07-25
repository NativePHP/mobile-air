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
    /// When true this container behaves like a SwiftUI ZStack: EVERY visible
    /// child is z-overlaid via absolute placement (ignoring its own
    /// positionType) using a 9-point anchor, and the container sizes to the
    /// union of its children (its largest child on each axis).
    let isStack: Bool
    /// Direct access to child nodes — LayoutValueKeys don't propagate through ViewModifiers,
    /// so we pass the node array and index into flex properties directly.
    let childNodes: [NativeUINode]

    init(
        direction: Int = FlexDirection.column,
        justify: Int = JustifyContent.start,
        align: Int = AlignItems.stretch,
        gap: CGFloat = 0,
        wrap: Int = 0,
        isStack: Bool = false,
        childNodes: [NativeUINode] = []
    ) {
        self.direction = direction
        self.justify = justify
        self.align = align
        self.gap = gap
        self.wrap = wrap
        self.isStack = isStack
        self.childNodes = childNodes
    }

    // MARK: - Cache

    struct CacheData {
        var childInfos: [ChildInfo] = []
        var flowIndices: [Int] = []
        var absoluteIndices: [Int] = []
        /// Memoize `finalSize` results by proposal. SwiftUI calls sizeThatFits
        /// repeatedly with the same proposal during a single layout pass —
        /// returning the cached size avoids re-walking subviews. We deliberately
        /// do NOT restore `idealSize` on a cache hit: a previous attempt did
        /// that and got bitten when Phase 5's `sizeThatFits(.unspecified)` call
        /// would clobber wrapped heights set by an earlier constrained-cross
        /// measurement. Returning the cached size leaves whatever idealSize
        /// the most recent actual measurement set.
        var sizeCache: [ProposalKey: CGSize] = [:]
    }

    /// Quantized proposal hash. CGFloat sizes can drift sub-pixel between
    /// SwiftUI calls; rounding to 1/1000 pt absorbs the noise.
    struct ProposalKey: Hashable {
        let width: Int64?
        let height: Int64?

        init(_ proposal: ProposedViewSize) {
            self.width = Self.encode(proposal.width)
            self.height = Self.encode(proposal.height)
        }

        private static func encode(_ value: CGFloat?) -> Int64? {
            guard let value else { return nil }
            if value.isNaN { return 0 }
            if value.isInfinite { return value > 0 ? .max : .min }
            return Int64(value * 1000)
        }
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
        /// 9-point anchor (0=center … 8=bottom-right) read from the child's
        /// tolerant PROPS blob. This is the point ON THE PARENT that the child
        /// hooks onto. `nil` when the child has no `anchor` prop — which, with
        /// no `origin` prop and outside a stack, keeps legacy non-stack absolute
        /// children on the inset-based top-left placement. Stack children
        /// default to center (see `placeAbsolute`).
        let anchor: Int?
        /// 9-point origin (same 0–8 enum) read from the child's PROPS blob. This
        /// is the point ON THE CHILD that lands on the parent's `anchor` point.
        /// `nil` when absent; defaults to center inside the two-point path.
        let origin: Int?
        var idealSize: CGSize = .zero
    }

    func makeCache(subviews: Subviews) -> CacheData {
        var cache = CacheData()
        cache.childInfos.reserveCapacity(subviews.count)
        cache.flowIndices.reserveCapacity(subviews.count)
        cache.absoluteIndices.reserveCapacity(subviews.count)

        for (i, _) in subviews.enumerated() {
            let layout = i < childNodes.count ? childNodes[i].layout : nil
            // The `anchor` / `origin` values ride the tolerant PROPS blob, not
            // the fixed binary layout struct. Read them directly off the node's
            // props; `has` distinguishes an explicit `center` (0) from absence.
            // `anchor` = point on the PARENT the child hooks onto; `origin` =
            // point on the CHILD that lands on it.
            let anchor: Int?
            let origin: Int?
            if i < childNodes.count {
                let props = childNodes[i].props
                anchor = props.has("anchor") ? props.getInt("anchor") : nil
                origin = props.has("origin") ? props.getInt("origin") : nil
            } else {
                anchor = nil
                origin = nil
            }
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
                // flex_basis is "set" only when its mode is fixed (1) — that
                // distinguishes Tailwind's `flex-1` (which sends 0/fixed) from
                // an unset basis (mode=0/auto). Without this check, an explicit
                // basis of 0 was treated as "use content size", which made
                // flex-1 children inflate to their natural width (e.g. a long
                // single-line <native:text> ⇒ 600+pt column overflow).
                flexBasis: (layout?.flexBasisMode ?? 0) == 1 ? CGFloat(layout?.flexBasis ?? 0) : -1,
                anchor: anchor,
                origin: origin
            )
            cache.childInfos.append(info)

            if info.display == Display.none { continue }
            // In a stack, EVERY visible child overlays regardless of its own
            // positionType — they're all placed absolutely with the anchor.
            // Enumeration is in ascending index order, so absoluteIndices keeps
            // document order → z-order = document order (first child at back).
            if isStack || info.positionType == PositionType.absolute {
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
        // Stack containers overlay every child (all routed to absoluteIndices),
        // so `flowIndices` is empty and absolute children don't feed the flex
        // sizing below. Size like a SwiftUI ZStack instead: the union of the
        // children's intrinsic sizes (largest child on each axis). This makes a
        // stack with no explicit width/height wrap its largest child rather than
        // collapsing to zero (e.g. a wordmark <text> gives the stack its size,
        // and a small anchored dot rides on top). A finite proposal on an axis
        // still wins, so an explicitly filled/sized stack fills as before.
        if isStack {
            let key = ProposalKey(proposal)
            if let cached = cache.sizeCache[key] { return cached }

            var maxWidth: CGFloat = 0
            var maxHeight: CGFloat = 0
            for i in cache.absoluteIndices {
                let ideal = subviews[i].sizeThatFits(.unspecified)
                cache.childInfos[i].idealSize = ideal
                maxWidth = max(maxWidth, ideal.width)
                maxHeight = max(maxHeight, ideal.height)
            }

            let result = CGSize(
                width: proposal.width ?? maxWidth,
                height: proposal.height ?? maxHeight
            )
            cache.sizeCache[key] = result
            return result
        }

        guard !cache.flowIndices.isEmpty else {
            // Even with no children, fill the proposed space if finite
            return CGSize(
                width: proposal.width ?? 0,
                height: proposal.height ?? 0
            )
        }

        // Memoization: SwiftUI calls sizeThatFits multiple times per layout
        // pass with the same proposal. Skip the full subview walk on repeats.
        let key = ProposalKey(proposal)
        if let cached = cache.sizeCache[key] {
            return cached
        }

        let proposed = CGSize(
            width: proposal.width ?? .infinity,
            height: proposal.height ?? .infinity
        )
        let proposedMain = mainSize(proposed)
        let proposedCross = crossSize(proposed)

        // Phase A: hypothetical main size + first-pass cross measurement.
        // Each child's "hypothetical main" follows CSS flex-base-size semantics:
        //   - explicit flex_basis → that value
        //   - flex_grow > 0       → 0 (Tailwind `flex-1` = `1 1 0%`)
        //   - otherwise           → natural main size from .unspecified measure
        var totalMain: CGFloat = 0
        var maxCross: CGFloat = 0
        var hypotheticalMains = [Int: CGFloat]()
        var totalGrow: CGFloat = 0

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
            } else if info.flexGrow > 0 {
                childMain = 0
            } else {
                childMain = mainSize(ideal)
            }

            hypotheticalMains[i] = childMain
            totalMain += childMain + mainMargin(info)
            totalGrow += info.flexGrow
            maxCross = max(maxCross, crossSize(ideal) + crossMargin(info))
        }

        let gaps = gap * CGFloat(max(0, cache.flowIndices.count - 1))
        totalMain += gaps

        // Phase B: when the parent gave us a finite main proposal AND we have
        // flex-grow children, distribute the remaining main space, then RE-
        // measure ONLY the grow children at their distributed main. Non-grow
        // children's sizes don't change with distribution — Phase A already
        // measured them at the cross constraint, so their cross size is final.
        if proposedMain.isFinite && totalGrow > 0 {
            let remaining = proposedMain - totalMain
            if remaining > 0 {
                for i in cache.flowIndices {
                    let info = cache.childInfos[i]
                    if info.flexGrow > 0 {
                        hypotheticalMains[i, default: 0] += remaining * (info.flexGrow / totalGrow)
                    }
                }

                // Re-measure only flex-grow children with their distributed main.
                // This is essential for accurate cross-axis (height) sizing — a
                // text-heavy flex-1 column needs the constrained-width measure
                // to wrap text to the right number of lines.
                for i in cache.flowIndices {
                    let info = cache.childInfos[i]
                    guard info.flexGrow > 0 else { continue }
                    let distributedMain = hypotheticalMains[i, default: 0]
                    let crossAvail = proposedCross.isFinite ? proposedCross - crossMargin(info) : nil
                    let proposal: ProposedViewSize
                    if isRow {
                        proposal = ProposedViewSize(width: distributedMain, height: crossAvail)
                    } else {
                        proposal = ProposedViewSize(width: crossAvail, height: distributedMain)
                    }
                    let measured = subviews[i].sizeThatFits(proposal)
                    cache.childInfos[i].idealSize = measured
                    // maxCross was tracking Phase A measurements — replace this
                    // child's contribution with the new (constrained) one.
                    let newCross = crossSize(measured) + crossMargin(info)
                    if newCross > maxCross {
                        maxCross = newCross
                    }
                }
            }
        }

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

        let result = makeSize(main: finalMain, cross: finalCross)
        cache.sizeCache[key] = result
        return result
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
            // Reuse the cross-constrained measurement done in sizeThatFits.
            // Re-measuring here with .unspecified produced different results
            // than the flex base size (CSS hypothetical main size under the
            // container's cross constraint) AND was the dominant cost in
            // initial layout — Instruments showed 438 main-thread samples in
            // sizeThatFits during a 671ms hang on a dense tree.
            let ideal = info.idealSize

            let childMain: CGFloat
            if info.flexBasis >= 0 {
                childMain = info.flexBasis
            } else if info.flexGrow > 0 {
                // Tailwind's `flex-1` is shorthand for `flex: 1 1 0%`. When a
                // child has flex-grow set but no explicit flex-basis, treat
                // its hypothetical main size as 0 (CSS shorthand semantics).
                // Without this, the child's natural content size (often huge,
                // e.g. an unwrapped <native:text>) inflates totalIdealMain and
                // shrink can't recover.
                childMain = 0
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
        //
        // CSS flexbox default is `align-items: stretch` — children get the
        // cross-axis size proposed to them. We now propose crossAvail always
        // (was previously only when widthMode/heightMode == FILL), so Text
        // and other naturally-wide views receive a finite width and wrap
        // instead of claiming intrinsic (single-line) width. Views that
        // prefer less still get what they want via sizeThatFits — the
        // proposed size is only a suggestion.
        for i in cache.flowIndices {
            let info = cache.childInfos[i]
            let crossAvail = containerCross - crossMargin(info)
            // Propose only the cross-axis dimension; leave main as nil so
            // children (especially text) report the height they actually need
            // when constrained to the available width. Proposing childMains[i]
            // for the main axis would feed text a too-short height (e.g. its
            // single-line ideal) and Text could return that height back without
            // reporting its true wrapped requirement.
            let proposedChild: ProposedViewSize
            if isRow {
                proposedChild = ProposedViewSize(width: childMains[i], height: nil)
            } else {
                proposedChild = ProposedViewSize(width: crossAvail, height: nil)
            }
            let measured = subviews[i].sizeThatFits(proposedChild)
            childCrosses[i] = crossSize(measured)
            // Update main size when the cross constraint changes it (e.g. text
            // wrapping that grows height when width is constrained). Skip
            // flex-grow children — their main is already authoritative from
            // Phase 2's distribution, and a re-measure with `nil` main would
            // get back the child's intrinsic content size (e.g. a ScrollView's
            // full content height), inflating the placement back past the
            // allocated bound and breaking scroll viewport sizing.
            if info.flexGrow == 0 {
                let measuredMain = mainSize(measured)
                if measuredMain > childMains[i] {
                    childMains[i] = measuredMain
                }
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

            // `w-full` / `h-full` (crossFill) is the child's explicit opt-in
            // to occupy the full cross axis — equivalent to CSS
            // `align-self: stretch`. It must take precedence over the
            // parent's `items-*` so that e.g. a `w-full` row inside an
            // `items-center` column actually spans the full width instead
            // of collapsing to its content's natural width.
            if crossFill {
                finalCross = containerCross - crossMargin(info)
                crossPos = (isRow ? bounds.minY : bounds.minX) + crossMarginBefore(info)
            } else {
                switch effectiveAlign {
                case AlignItems.stretch:
                    // No FILL: use natural size, align to start (like Android).
                    // We can't reuse childCrosses[i] from Phase 3 here — Phase 3
                    // proposes crossAvail, which makes container children (e.g.
                    // a flex column) fill the cross axis and report container
                    // cross size, not their natural content size.
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

    /// Map a 9-point anchor id to its horizontal/vertical placement fractions.
    ///   0=center, 1=top-left, 2=top-center, 3=top-right, 4=center-left,
    ///   5=center-right, 6=bottom-left, 7=bottom-center, 8=bottom-right.
    private func anchorFractions(_ anchor: Int) -> (ax: CGFloat, ay: CGFloat) {
        switch anchor {
        case 1: return (0, 0)       // top-left
        case 2: return (0.5, 0)     // top-center
        case 3: return (1, 0)       // top-right
        case 4: return (0, 0.5)     // center-left
        case 5: return (1, 0.5)     // center-right
        case 6: return (0, 1)       // bottom-left
        case 7: return (0.5, 1)     // bottom-center
        case 8: return (1, 1)       // bottom-right
        default: return (0.5, 0.5)  // 0 = center (also the stack default)
        }
    }

    /// Place an absolute-positioned child.
    ///
    /// Two placement modes:
    ///   • Two-point anchor/origin — used for stack children and any child
    ///     carrying an explicit `anchor` and/or `origin` prop. The child's
    ///     `origin` point (a fraction of its own frame) is pinned to the
    ///     parent's `anchor` point (a fraction of the container), then the
    ///     position insets nudge it (left/top push right/down, right/bottom
    ///     push left/up). Both default to center (0). This can intentionally
    ///     draw partly outside the container — a plain container doesn't clip.
    ///   • Legacy inset — used for non-stack absolute children with neither
    ///     an `anchor` nor an `origin`. Byte-identical to the historical
    ///     top-left/inset behavior.
    private func placeAbsolute(_ subview: LayoutSubview, info: ChildInfo, in bounds: CGRect) {
        let ideal = subview.sizeThatFits(.unspecified)

        // Use the two-point model for stacks, or whenever the child opts in with
        // either prop. Otherwise fall through to the unchanged legacy inset path.
        let useAnchorModel = isStack || info.anchor != nil || info.origin != nil

        let x: CGFloat
        let y: CGFloat
        if useAnchorModel {
            let (aax, aay) = anchorFractions(info.anchor ?? 0)   // point on the parent
            let (oax, oay) = anchorFractions(info.origin ?? 0)   // point on the child
            x = bounds.minX + bounds.width * aax - ideal.width * oax
                + info.positionLeft - info.positionRight
            y = bounds.minY + bounds.height * aay - ideal.height * oay
                + info.positionTop - info.positionBottom
        } else {
            // Legacy inset-based placement (unchanged).
            var lx = bounds.minX + info.positionLeft
            if info.positionRight > 0 && info.positionLeft == 0 {
                lx = bounds.maxX - ideal.width - info.positionRight
            }

            var ly = bounds.minY + info.positionTop
            if info.positionBottom > 0 && info.positionTop == 0 {
                ly = bounds.maxY - ideal.height - info.positionBottom
            }

            x = lx
            y = ly
        }

        subview.place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(ideal))
    }
}
