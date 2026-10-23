import SwiftUI
import UIKit

/// Renders shared elements in transit, ABOVE both screens.
///
/// This is the layer that makes the morph read cleanly. While the two screens
/// cross-fade, a copy of each travelling element is drawn here at full opacity
/// for the whole journey, so nothing is occluded by the screen fading in over
/// it. See `HeroFlightStore` for why SwiftUI needs this done by hand.
///
/// Inert whenever nothing is flying — the overwhelming majority of the time —
/// and never interactive: the copy is a picture of an element, and taps belong
/// to the real one underneath it.
struct HeroFlightOverlay: View {
    @ObservedObject private var store = HeroFlightStore.shared

    var body: some View {
        if store.flights.isEmpty {
            // No ZStack, no GeometryReader, nothing in the hierarchy at all.
            Color.clear.frame(width: 0, height: 0).hidden()
        } else {
            GeometryReader { geo in
                // Same environment the real tree renders with, so a copied
                // subtree lays out identically to the original — a node that
                // sizes itself against the viewport or safe area would
                // otherwise measure differently up here.
                let insets = Self.windowSafeAreaInsets
                ZStack(alignment: .topLeading) {
                    ForEach(store.flights) { flight in
                        HeroFlightView(flight: flight)
                            // Without this the copy hides itself: its ref is
                            // the one in flight, and NodeHeroModifier hides
                            // every element whose ref is mid-flight.
                            .environment(\.heroIsFlyingCopy, true)
                            .environment(\.nativeSafeAreaTop, insets.top)
                            .environment(\.nativeSafeAreaBottom, insets.bottom)
                            .environment(\.availableWidth, geo.size.width)
                            .environment(\.availableHeight, geo.size.height)
                    }
                }
                .frame(width: geo.size.width, height: geo.size.height, alignment: .topLeading)
            }
            .ignoresSafeArea(.container, edges: .all)
            .allowsHitTesting(false)
        }
    }

    /// Read from the window rather than a nested reader: the overlay ignores
    /// the safe area, so a `GeometryReader` here reports zero insets and a
    /// copied node would sit at a different offset than its original.
    private static var windowSafeAreaInsets: (top: CGFloat, bottom: CGFloat) {
        guard let insets = UIApplication.shared.connectedScenes
            .compactMap({ $0 as? UIWindowScene })
            .first?.windows.first?.safeAreaInsets else {
            return (0, 0)
        }
        return (insets.top, insets.bottom)
    }
}

/// One element in transit: mounts at its origin frame, then animates to its
/// destination.
private struct HeroFlightView: View {
    let flight: HeroFlightStore.Flight

    @State private var arrived = false

    var body: some View {
        let rect = arrived ? flight.to : flight.from

        NodeView(node: flight.node)
            .frame(width: rect.width, height: rect.height)
            .position(x: rect.midX, y: rect.midY)
            // Declared on the value that moves, NOT wrapped around the
            // mutation. `withAnimation` around an @Published change does not
            // reliably carry its transaction into observing views — measured
            // on device as the frame snapping while the state flipped
            // correctly.
            .animation(flight.animation, value: arrived)
            .onAppear {
                // A LATER runloop turn than the mount. Setting this inline
                // coalesces with the insertion, so the copy appears already at
                // its destination and never travels.
                DispatchQueue.main.async { arrived = true }
            }
    }
}
