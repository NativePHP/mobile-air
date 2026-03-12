import Foundation
import UIKit
import Bridge

/// Swift bridge to Yoga C++ layout engine.
///
/// Takes flat buffer data and a parsed NativeUITree, computes Yoga layout,
/// and returns an annotated tree with ComputedLayout on each node.
final class YogaBridge {

    /// Compute Yoga layout for a tree and return an annotated copy with ComputedLayout on each node.
    /// Cached safe area insets in points — set from main thread
    static var safeAreaTop: CGFloat = 0
    static var safeAreaBottom: CGFloat = 0

    static func computeLayout(
        tree: NativeUITree,
        flatData: Data,
        nodeCount: Int,
        typeTable: [String],
        viewportSize: CGSize
    ) -> NativeUITree {
        guard nodeCount > 0 else { return tree }

        let perNode = flatData.count / nodeCount
        print("YogaBridge: nodes=\(nodeCount) perNode=\(perNode) viewport=\(viewportSize.width)x\(viewportSize.height) flat=\(flatData.count)")

        guard perNode >= 160 else {
            print("YogaBridge: ERROR node size \(perNode) < 160 — rebuild PHP binaries")
            return tree
        }

        let t0 = CFAbsoluteTimeGetCurrent()

        // Inject safe area insets as Yoga padding for ANY node with the safe_area flag.
        // This must happen before Yoga compute so layout accounts for safe area correctly,
        // especially inside scroll views where post-layout adjustments cause clipping.
        // Byte 77 = safe_area flag in 160-byte flat node layout.
        var mutableFlatData = flatData
        let topPt = Float(safeAreaTop)
        let bottomPt = Float(safeAreaBottom)

        if topPt > 0 || bottomPt > 0 {
            for i in 0..<nodeCount {
                let offset = i * 160
                guard offset + 160 <= mutableFlatData.count else { break }
                let safeArea = mutableFlatData[offset + 77]
                guard safeArea != 0 else { continue }

                var existingTop: Float = 0
                var existingBottom: Float = 0
                mutableFlatData.withUnsafeBytes { buf in
                    existingTop = buf.loadUnaligned(fromByteOffset: offset + 30, as: Float.self)
                    existingBottom = buf.loadUnaligned(fromByteOffset: offset + 38, as: Float.self)
                }

                var newTop = existingTop + topPt
                var newBottom = existingBottom + bottomPt
                mutableFlatData.replaceSubrange((offset + 30)..<(offset + 34), with: withUnsafeBytes(of: &newTop) { Data($0) })
                mutableFlatData.replaceSubrange((offset + 38)..<(offset + 42), with: withUnsafeBytes(of: &newBottom) { Data($0) })
            }
        }

        // Phase 3: Pre-measure leaf nodes for intrinsic sizes
        var measurements = Array(repeating: Float(0), count: nodeCount * 2)
        preMeasure(node: tree.root, measurements: &measurements, index: 0, maxWidth: Float(viewportSize.width))

        let t1 = CFAbsoluteTimeGetCurrent()

        // Convert Swift strings to C strings that stay alive for the call
        var cStringPtrs: [UnsafePointer<CChar>?] = []
        cStringPtrs.reserveCapacity(typeTable.count)
        for str in typeTable {
            cStringPtrs.append((str as NSString).utf8String)
        }

        // Allocate output array
        let zeroLayout = YogaComputedLayout(x: 0, y: 0, width: 0, height: 0)
        var layouts = Array(repeating: zeroLayout, count: nodeCount)

        let resultCount: Int = mutableFlatData.withUnsafeBytes { flatBuf in
            cStringPtrs.withUnsafeBufferPointer { typePtr in
                measurements.withUnsafeBufferPointer { measPtr in
                    let typePtrBase = UnsafeRawPointer(typePtr.baseAddress!)
                        .assumingMemoryBound(to: UnsafePointer<CChar>?.self)
                    return Int(yoga_compute_layout(
                        flatBuf.baseAddress!.assumingMemoryBound(to: UInt8.self),
                        mutableFlatData.count,
                        Int32(nodeCount),
                        Float(viewportSize.width),
                        Float(viewportSize.height),
                        typePtrBase,
                        Int32(typeTable.count),
                        measPtr.baseAddress,
                        &layouts
                    ))
                }
            }
        }

        let t2 = CFAbsoluteTimeGetCurrent()

        guard resultCount > 0 else {
            print("YogaBridge: compute returned 0 results")
            return tree
        }

        // Walk tree in DFS pre-order (same order as flat buffer) and attach computed layouts
        var idx = 0

        func annotateNode(_ node: NativeUINode) -> NativeUINode {
            guard idx < resultCount else { return node }

            let cl = layouts[idx]
            let computed = ComputedLayout(
                x: cl.x, y: cl.y,
                width: cl.width, height: cl.height
            )
            idx += 1

            let annotatedChildren: [NativeUINode]
            if node.children.isEmpty {
                annotatedChildren = node.children
            } else {
                var list: [NativeUINode] = []
                list.reserveCapacity(node.children.count)
                for child in node.children {
                    list.append(annotateNode(child))
                }
                annotatedChildren = list
            }

            return node.copy(children: annotatedChildren, computed: computed)
        }

        let annotatedRoot = annotateNode(tree.root)

        let t3 = CFAbsoluteTimeGetCurrent()
        print("YogaBridge: PERF measure=\(String(format: "%.1f", (t1-t0)*1000))ms compute=\(String(format: "%.1f", (t2-t1)*1000))ms annotate=\(String(format: "%.1f", (t3-t2)*1000))ms nodes=\(nodeCount)")

        return NativeUITree(version: tree.version, callbackCount: tree.callbackCount, root: annotatedRoot)
    }

    /// Walk tree in DFS pre-order and fill measurements array with intrinsic sizes.
    /// Returns the next available index.
    @discardableResult
    private static func preMeasure(node: NativeUINode, measurements: inout [Float], index: Int, maxWidth: Float) -> Int {
        var idx = index
        guard idx * 2 + 1 < measurements.count else { return idx }

        let (w, h) = TextMeasurer.measure(node: node, maxWidth: maxWidth)
        measurements[idx * 2] = w
        measurements[idx * 2 + 1] = h
        idx += 1

        for child in node.children {
            idx = preMeasure(node: child, measurements: &measurements, index: idx, maxWidth: maxWidth)
        }

        return idx
    }
}
