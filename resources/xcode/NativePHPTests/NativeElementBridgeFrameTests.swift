import Foundation
import Testing
@testable import NativePHP

/// The flat-buffer reader across frames: what a REUSE marker can splice, and
/// that no published frame goes unparsed.
struct NativeElementBridgeFrameTests {

    private static let types = ["column", "list", "list_item"]

    /// One flat-buffer node: id, type index, child count and the REUSE flag.
    /// Everything else (layout, style, props) is left zero.
    private static func node(_ id: UInt32, type: UInt16 = 0, children: UInt16 = 0, reuse: Bool = false) -> Data {
        var bytes = [UInt8](repeating: 0, count: 161)
        withUnsafeBytes(of: id.littleEndian) { bytes.replaceSubrange(0..<4, with: $0) }
        withUnsafeBytes(of: type.littleEndian) { bytes.replaceSubrange(4..<6, with: $0) }
        withUnsafeBytes(of: children.littleEndian) { bytes.replaceSubrange(6..<8, with: $0) }
        bytes[160] = reuse ? 1 : 0
        return Data(bytes)
    }

    private static func read(_ nodes: [Data], previous: NativeUITree?) -> NativeUITree? {
        NativeElementBridge.readTreeFromFlatBuffer(
            nodes.reduce(Data(), +), propData: nil, typeTable: types,
            nodeCount: nodes.count, previous: previous
        )
    }

    private static func rowIds(_ tree: NativeUITree?) -> [Int] {
        tree?.root.children.first?.children.map(\.id) ?? []
    }

    @Test func aReuseMarkerTheReaderDoesNotHoldDropsOnlyThatNode() {
        // A list with a new row 10, a REUSE of row 9 it never parsed, then
        // REUSEs of rows 8 and 7 it has.
        let first = Self.read([
            Self.node(1, children: 1),
            Self.node(2, type: 1, children: 2),
            Self.node(8, type: 2),
            Self.node(7, type: 2),
        ], previous: nil)

        let next = Self.read([
            Self.node(1, children: 1),
            Self.node(2, type: 1, children: 4),
            Self.node(10, type: 2),
            Self.node(9, type: 2, reuse: true),
            Self.node(8, type: 2, reuse: true),
            Self.node(7, type: 2, reuse: true),
        ], previous: first)

        #expect(Self.rowIds(next) == [10, 8, 7])
    }

    @Test func aFrameReusesARowTheFrameBeforeBroughtIn() {
        let first = Self.read([
            Self.node(1, children: 1),
            Self.node(2, type: 1, children: 1),
            Self.node(8, type: 2),
        ], previous: nil)

        // Row 9 arrives in this frame, which the main thread may never draw.
        let middle = Self.read([
            Self.node(1, children: 1),
            Self.node(2, type: 1, children: 2),
            Self.node(9, type: 2),
            Self.node(8, type: 2, reuse: true),
        ], previous: first)

        let last = Self.read([
            Self.node(1, children: 1),
            Self.node(2, type: 1, children: 3),
            Self.node(10, type: 2),
            Self.node(9, type: 2, reuse: true),
            Self.node(8, type: 2, reuse: true),
        ], previous: middle)

        #expect(Self.rowIds(last) == [10, 9, 8])
    }

    @Test func theQueueKeepsEveryFrameInOrder() {
        var queue = NativeElementBridge.ShadowQueue()

        for count in 1...3 {
            queue.push(.init(flatData: Data(), propData: nil, typeTable: [], nodeCount: count, isNav: false))
        }

        #expect(queue.drain().map(\.nodeCount) == [1, 2, 3])
        #expect(queue.isEmpty)
    }

    @Test func theMainThreadTakesOnlyTheNewestFrame() throws {
        func settled(_ id: UInt32, fresh: Bool = false) throws -> NativeElementBridge.Settled {
            let tree = try #require(Self.read([Self.node(id)], previous: nil))
            return .init(finalTree: tree, nativeChromeContinuation: false, isFreshStackMount: fresh)
        }

        _ = NativeElementBridge.takeForMain()

        // Only the first frame asks for a main-queue block.
        #expect(NativeElementBridge.offerToMain(try settled(1, fresh: true), isNav: true))
        #expect(!NativeElementBridge.offerToMain(try settled(2), isNav: false))
        #expect(!NativeElementBridge.offerToMain(try settled(3), isNav: false))

        // The block gets the newest tree, still flagged as the navigation
        // and fresh stack mount the first frame was.
        let frame = try #require(NativeElementBridge.takeForMain())
        #expect(frame.settled.finalTree.root.id == 3)
        #expect(frame.isNav)
        #expect(frame.settled.isFreshStackMount)
        #expect(NativeElementBridge.takeForMain() == nil)
    }
}
