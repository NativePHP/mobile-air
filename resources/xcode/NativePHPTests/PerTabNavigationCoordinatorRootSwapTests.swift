import Testing
@testable import NativePHP

struct PerTabNavigationCoordinatorRootSwapTests {

    func screen(_ id: Int) -> NativeUINode {
        NativeUINode(
            id: id, type: "native_root_tabs", layout: nil, style: nil,
            props: GenericProps(), onPress: 0, onLongPress: 0, children: []
        )
    }

    /// An app launched at a sub-route of a tab (`NATIVEPHP_START_URL`) has
    /// one PHP level, so the screen arrives at the tab's root depth.
    @Test func aSubRouteAtTheTabsRootDepthIsShownAtTheRootLevel() {
        let coordinator = PerTabNavigationCoordinator(rootUri: "/components")
        let launched = screen(1)

        coordinator.receive(uri: "/components/icon-button", depth: 1, rootNode: launched)

        #expect(coordinator.rootLevelUri == "/components/icon-button")
        #expect(coordinator.rootNodeCache[coordinator.rootLevelUri] === launched)
        #expect(coordinator.path.isEmpty)

        coordinator.receive(uri: "/components", depth: 1, rootNode: screen(2))

        #expect(coordinator.rootLevelUri == "/components")
        #expect(coordinator.path.isEmpty)
    }
}
