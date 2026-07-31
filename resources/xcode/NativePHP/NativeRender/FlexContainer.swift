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
                // flex_basis is "set" only when its mode is fixed (1) — that
                // distinguishes Tailwind's `flex-1` (which sends 0/fixed) from
                // an unset basis (mode=0/auto). Without this check, an explicit
                // basis of 0 was treated as "use content size", which made
                // flex-1 children inflate to their natural width (e.g. a long
                // single-line <native:text> ⇒ 600+pt column overflow).
                flexBasis: (layout?.flexBasisMode ?? 0) == 1 ? CGFloat(layout?.flexBasis ?? 0) : -1
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

    /// Whether the child's MAIN-axis size mode is FILL (`h-full` in a
    /// column, `w-full` in a row).
    private func mainFill(_ i: Int) -> Bool {
        guard i < childNodes.count, let l = childNodes[i].layout else { return false }
        if isRow {
            return l.widthMode == SizeMode.fill
        }

        return l.heightMode == SizeMode.fill
    }

    /// Main-axis FILL acts as an implicit `flex-grow: 1` whenever the
    /// container's main-axis extent is definite — mirroring
    /// ComposeFlexLayout's `weight(1f)` rule on Android so the platforms
    /// agree. Without this, a `h-full` child with flex_grow 0 is measured
    /// at its intrinsic main size: a fill-height ScrollView reports full
    /// CONTENT height and gets placed past the viewport — background
    /// bands stop short, absolute overlays anchor to the content bottom,
    /// and the scroll range collapses (rubber-band, bottom unreachable).
    /// Under an INDEFINITE proposal (finiteMain false) the promotion is
    /// skipped: 100% of an unknown extent is auto/content sizing, and
    /// callers measuring .unspecified want the intrinsic answer.
    private func effectiveGrow(_ info: ChildInfo, index: Int, finiteMain: Bool) -> CGFloat {
        if info.flexGrow > 0 { return info.flexGrow }

        return finiteMain && mainFill(index) ? 1 : 0
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
        var totalShrink: CGFloat = 0

        for i in cache.flowIndices {
            let info = cache.childInfos[i]
            let grow = effectiveGrow(info, index: i, finiteMain: proposedMain.isFinite)
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
            } else if grow > 0 {
                childMain = 0
            } else {
                childMain = mainSize(ideal)
            }

            hypotheticalMains[i] = childMain
            totalMain += childMain + mainMargin(info)
            totalGrow += grow
            totalShrink += info.flexShrink
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
                    let grow = effectiveGrow(info, index: i, finiteMain: true)
                    if grow > 0 {
                        hypotheticalMains[i, default: 0] += remaining * (grow / totalGrow)
                    }
                }

                // Re-measure only flex-grow children with their distributed main.
                // This is essential for accurate cross-axis (height) sizing — a
                // text-heavy flex-1 column needs the constrained-width measure
                // to wrap text to the right number of lines.
                for i in cache.flowIndices {
                    let info = cache.childInfos[i]
                    guard effectiveGrow(info, index: i, finiteMain: true) > 0 else { continue }
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

        // Phase B2: the mirror image — overflow with flex-shrink children.
        // `placeSubviews` has always shrunk them; `sizeThatFits` did not, so a
        // container reported its PRE-shrink main size to the parent. Inside a
        // scroll view (which permits overflow) that reported width won, and
        // e.g. a long chat bubble ran off-screen on one line instead of
        // wrapping — Android, whose measure pass shrinks, wrapped correctly.
        // Same ratio rule as placeSubviews so both passes agree.
        if proposedMain.isFinite && totalShrink > 0 {
            let remaining = proposedMain - totalMain
            if remaining < 0 {
                let deficit = -remaining
                // CSS weights shrink by SCALED base size — `shrink × base` —
                // not by shrink alone. It matters because flex-shrink defaults
                // to 1 on every child: a row of [bubble, spacer] has
                // totalShrink 2, so an unweighted ratio hands half the deficit
                // to the spacer, whose base is already 0. That half evaporates
                // (`max(0, 0 - x)`) and the bubble shrinks only halfway —
                // measured 1113pt against a 386pt proposal. Weighting gives the
                // zero-base spacer zero reduction and the bubble the full
                // deficit.
                var totalWeighted: CGFloat = 0
                for i in cache.flowIndices {
                    let info = cache.childInfos[i]
                    guard info.flexShrink > 0 else { continue }
                    totalWeighted += info.flexShrink * hypotheticalMains[i, default: 0]
                }
                if totalWeighted > 0 {
                    for i in cache.flowIndices {
                        let info = cache.childInfos[i]
                        guard info.flexShrink > 0 else { continue }
                        let weight = info.flexShrink * hypotheticalMains[i, default: 0]
                        let reduction = deficit * (weight / totalWeighted)
                        hypotheticalMains[i, default: 0] = max(0, hypotheticalMains[i, default: 0] - reduction)
                    }
                }

                // Re-measure the shrunk children at their reduced main: text
                // that now wraps reports a taller cross size, which is what
                // makes the container tall enough to show every line.
                for i in cache.flowIndices {
                    let info = cache.childInfos[i]
                    guard info.flexShrink > 0 else { continue }
                    let reducedMain = hypotheticalMains[i, default: 0]
                    let crossAvail = proposedCross.isFinite ? proposedCross - crossMargin(info) : nil
                    let proposal: ProposedViewSize
                    if isRow {
                        proposal = ProposedViewSize(width: reducedMain, height: crossAvail)
                    } else {
                        proposal = ProposedViewSize(width: crossAvail, height: reducedMain)
                    }
                    let measured = subviews[i].sizeThatFits(proposal)
                    cache.childInfos[i].idealSize = measured
                    let newCross = crossSize(measured) + crossMargin(info)
                    if newCross > maxCross {
                        maxCross = newCross
                    }
                }

                // Recompute from the shrunk values so `finalMain` below reports
                // the fitted size rather than the original overflow.
                totalMain = gaps
                for i in cache.flowIndices {
                    totalMain += hypotheticalMains[i, default: 0] + mainMargin(cache.childInfos[i])
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
            // Placement bounds are always definite, so main-axis FILL
            // children are grow-promoted unconditionally here.
            let grow = effectiveGrow(info, index: i, finiteMain: true)
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
            } else if grow > 0 {
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
            totalGrow += grow
            totalShrink += info.flexShrink
        }

        let gaps = gap * CGFloat(max(0, flowCount - 1))
        let remaining = containerMain - totalIdealMain - gaps

        // Phase 2: Distribute remaining space (grow or shrink)
        if remaining > 0 && totalGrow > 0 {
            // Grow: distribute extra space by (effective) flex_grow ratio
            for i in cache.flowIndices {
                let info = cache.childInfos[i]
                let grow = effectiveGrow(info, index: i, finiteMain: true)
                if grow > 0 {
                    childMains[i] += remaining * (grow / totalGrow)
                }
            }
        } else if remaining < 0 && totalShrink > 0 {
            // Shrink: reduce by CSS's SCALED shrink factor (`shrink × base`),
            // matching the measure pass. Weighting matters because shrink
            // defaults to 1 everywhere — an unweighted ratio sends part of the
            // deficit to zero-base children (spacers), where `max(0, …)`
            // discards it and the real content shrinks short of fitting.
            let deficit = -remaining
            var totalWeighted: CGFloat = 0
            for i in cache.flowIndices {
                let info = cache.childInfos[i]
                guard info.flexShrink > 0 else { continue }
                totalWeighted += info.flexShrink * childMains[i]
            }
            if totalWeighted > 0 {
                for i in cache.flowIndices {
                    let info = cache.childInfos[i]
                    guard info.flexShrink > 0 else { continue }
                    let reduction = deficit * ((info.flexShrink * childMains[i]) / totalWeighted)
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
            // flex-grow children (including grow-promoted main-axis FILL
            // children) — their main is already authoritative from
            // Phase 2's distribution, and a re-measure with `nil` main would
            // get back the child's intrinsic content size (e.g. a ScrollView's
            // full content height), inflating the placement back past the
            // allocated bound and breaking scroll viewport sizing.
            if effectiveGrow(info, index: i, finiteMain: true) == 0 {
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

    /// Place an absolute-positioned child using position insets.
    private func placeAbsolute(_ subview: LayoutSubview, info: ChildInfo, in bounds: CGRect) {
        let ideal = subview.sizeThatFits(.unspecified)

        // Resolve horizontal position. A NON-ZERO right inset (with no left)
        // anchors to the trailing edge; `!= 0` rather than `> 0` so NEGATIVE
        // insets work — `-right-8` resolves to maxX - width + 8, deliberately
        // overhanging the edge (Tailwind's `-right-8` bleed). Zero still means
        // "no right anchor", since the packed node struct has no spare byte to
        // distinguish an unset edge from an explicit `right-0`.
        var x = bounds.minX + info.positionLeft
        if info.positionRight != 0 && info.positionLeft == 0 {
            x = bounds.maxX - ideal.width - info.positionRight
        }

        // Resolve vertical position — same convention.
        var y = bounds.minY + info.positionTop
        if info.positionBottom != 0 && info.positionTop == 0 {
            y = bounds.maxY - ideal.height - info.positionBottom
        }

        subview.place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(ideal))
    }
}
