import Testing
@testable import NativePHP

/// The tabs renderer reconciles a tab only when SwiftUI redraws the tabs,
/// so of two publishes that land before a redraw it sees the second. A tab
/// root that pushes a screen as it mounts (a push notification opening the
/// commit it's about, on top of the Notifications tab) loses its own publish
/// that way, and the tab has to find its root from the router stack.
struct PerTabNavigationCoordinatorSkippedRootTests {

    func screen(_ id: Int) -> NativeUINode {
        NativeUINode(
            id: id, type: "native_root_tabs", layout: nil, style: nil,
            props: GenericProps(), onPress: 0, onLongPress: 0, children: []
        )
    }

    /// The tab was last drawn at the bottom of the stack. A tap on a push
    /// while the app shows the Log puts the tab on top of the Log, and its
    /// root's publish is never seen.
    @Test func aScreenPushedByATabRootThatWasNeverDrawnIsPushedOnce() {
        let coordinator = PerTabNavigationCoordinator(rootUri: "/notifications")
        coordinator.receive(uri: "/notifications", depth: 1, stack: ["/notifications"], rootNode: screen(1))

        coordinator.receive(
            uri: "/notifications/commits/c1", depth: 3,
            stack: ["/log", "/notifications", "/notifications/commits/c1"],
            rootNode: screen(2)
        )

        #expect(coordinator.path == ["/notifications/commits/c1"])
    }

    /// Two screens deep in the Log, the tab lands one level higher still.
    @Test func itStaysOneLevelHoweverDeepTheStackBelowTheTabIs() {
        let coordinator = PerTabNavigationCoordinator(rootUri: "/notifications")
        coordinator.receive(uri: "/notifications", depth: 1, stack: ["/notifications"], rootNode: screen(1))

        coordinator.receive(
            uri: "/notifications/commits/c1", depth: 5,
            stack: ["/log", "/log/commits/a", "/log/users/b", "/notifications", "/notifications/commits/c1"],
            rootNode: screen(2)
        )

        #expect(coordinator.path == ["/notifications/commits/c1"])

        // Back on the tab's root, where PHP now is.
        coordinator.receive(
            uri: "/notifications", depth: 4,
            stack: ["/log", "/log/commits/a", "/log/users/b", "/notifications"],
            rootNode: screen(3)
        )

        #expect(coordinator.path.isEmpty)
    }

    /// A screen that pushes another as it mounts loses its publish the same
    /// way. The stack names it, so its level shows it rather than a second
    /// copy of the screen on top.
    @Test func aPushedLevelThatWasNeverDrawnTakesItsUriFromTheStack() {
        let coordinator = PerTabNavigationCoordinator(rootUri: "/notifications")
        coordinator.receive(uri: "/notifications", depth: 1, stack: ["/notifications"], rootNode: screen(1))

        coordinator.receive(
            uri: "/notifications/users/ada", depth: 3,
            stack: ["/notifications", "/notifications/commits/c1", "/notifications/users/ada"],
            rootNode: screen(2)
        )

        #expect(coordinator.path == ["/notifications/commits/c1", "/notifications/users/ada"])
    }

    /// A runtime that sends no stack keeps the old depth arithmetic.
    @Test func withoutAStackItBackfillsAsBefore() {
        let coordinator = PerTabNavigationCoordinator(rootUri: "/notifications")
        coordinator.receive(uri: "/notifications", depth: 1, rootNode: screen(1))

        coordinator.receive(uri: "/notifications/commits/c1", depth: 3, rootNode: screen(2))

        #expect(coordinator.path == ["/notifications/commits/c1", "/notifications/commits/c1"])
    }
}
