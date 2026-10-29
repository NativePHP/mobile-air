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
}
