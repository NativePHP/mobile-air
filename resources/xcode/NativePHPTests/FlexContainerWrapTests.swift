import Testing
import SwiftUI
import UIKit
@testable import NativePHP

@MainActor
struct FlexContainerWrapTests {

    /// Five 100×30 chips, 8pt apart, in a 250pt-wide row.
    private func height(wrap: Int, width: CGFloat = 250) -> CGFloat {
        let row = FlexContainer(direction: FlexDirection.row, align: AlignItems.start, gap: 8, wrap: wrap) {
            ForEach(0..<5, id: \.self) { _ in
                Color.clear.frame(width: 100, height: 30)
            }
        }
        return UIHostingController(rootView: row)
            .sizeThatFits(in: CGSize(width: width, height: .infinity))
            .height
    }

    @Test func wrappingRowStacksChildrenThatDoNotFitOnOneLine() {
        // Two chips per line (100 + 8 + 100 = 208 ≤ 250), so three lines:
        // 30 + 8 + 30 + 8 + 30.
        #expect(height(wrap: 1) == 106)
    }

    @Test func nowrapRowStaysOnOneLine() {
        #expect(height(wrap: 0) == 30)
    }

    @Test func wrappingRowThatFitsKeepsOneLine() {
        // 5 × 100 + 4 × 8 = 532.
        #expect(height(wrap: 1, width: 600) == 30)
    }

    @Test func marginsCountTowardsLineBreaksAndLineHeight() {
        // The middle chip has 30pt side margins, so 100 + 8 + 30 + 100 + 30
        // = 268 > 250 sends it to its own line, and its 10pt top and bottom
        // margins make that line 50pt: 30 + 8 + 50 + 8 + 30.
        let middle = NodeLayout.empty.applying(["margin": [10, 30, 10, 30]])
        #expect(height(layouts: [.empty, middle, .empty]) == 126)
    }

    @Test func flexBasisSetsAChildsStartingWidth() {
        // A 150pt basis leaves no room for a second chip (150 + 8 + 150 > 250).
        #expect(height(layouts: Array(repeating: basis(150), count: 3)) == 106)
    }

    /// Three 100×30 chips, 8pt apart, in a 250pt-wide wrapping row, each
    /// with the given layout.
    private func height(layouts: [NodeLayout]) -> CGFloat {
        let nodes = layouts.enumerated().map { i, layout in
            NativeUINode(id: i, type: "column", layout: layout, style: nil, props: GenericProps(),
                         onPress: 0, onLongPress: 0, children: [])
        }
        let row = FlexContainer(direction: FlexDirection.row, align: AlignItems.start, gap: 8, wrap: 1, childNodes: nodes) {
            ForEach(0..<nodes.count, id: \.self) { _ in
                Color.clear.frame(width: 100, height: 30)
            }
        }
        return UIHostingController(rootView: row)
            .sizeThatFits(in: CGSize(width: 250, height: CGFloat.infinity))
            .height
    }

    /// An explicit basis, as the element bridge sends it (mode 1 = set).
    private func basis(_ value: Float) -> NodeLayout {
        let e = NodeLayout.empty
        return NodeLayout(
            width: e.width, widthMode: e.widthMode, height: e.height, heightMode: e.heightMode,
            paddingTop: 0, paddingRight: 0, paddingBottom: 0, paddingLeft: 0,
            marginTop: 0, marginRight: 0, marginBottom: 0, marginLeft: 0,
            flexGrow: 0, flexShrink: 0, alignSelf: 0, alignItems: 0, justifyContent: 0, gap: 0, safeArea: 0,
            minWidth: 0, minHeight: 0, maxWidth: 0, maxHeight: 0,
            flexBasis: value, flexBasisMode: 1, flexWrap: 0, flexDirection: 0, positionType: 0,
            positionTop: 0, positionRight: 0, positionBottom: 0, positionLeft: 0,
            display: 0, overflow: 0, alignContent: 0, direction: 0, aspectRatio: 0, rowGap: 0
        )
    }
}
