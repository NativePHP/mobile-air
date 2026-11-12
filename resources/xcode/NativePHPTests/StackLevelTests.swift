import Combine
import Testing
@testable import NativePHP

extension SharedNavigationCoordinatorTests {
    /// `NavigationCoordinator` is a singleton, so these run one at a time.
    @Suite
    struct StackLevelTests {

        func screen(_ id: Int) -> NativeUINode {
            NativeUINode(
                id: id, type: "native_root_stack", layout: nil, style: nil,
                props: GenericProps(), onPress: 0, onLongPress: 0, children: []
            )
        }

        /// Counts `objectWillChange` sends from `level` while `body` runs.
        func changes(of level: StackLevel, during body: () -> Void) -> Int {
            var count = 0
            let subscription = level.objectWillChange.sink { _ in count += 1 }
            body()
            subscription.cancel()

            return count
        }

        @Test func republishingAPushedScreenRedrawsItsLevel() {
            let coordinator = NavigationCoordinator.shared
            coordinator.reset()
            coordinator.receive(uri: "/servers", depth: 1, rootNode: screen(1))
            coordinator.receive(uri: "/servers/7/logs", depth: 2, rootNode: screen(2))
            let pushed = coordinator.level(for: "/servers/7/logs")
            let root = coordinator.level(for: "/servers")
            let loaded = screen(3)

            var rootChanges = 0
            let rootSubscription = root.objectWillChange.sink { _ in rootChanges += 1 }
            let pushedChanges = changes(of: pushed) {
                coordinator.receive(uri: "/servers/7/logs", depth: 2, rootNode: loaded)
            }
            rootSubscription.cancel()

            #expect(coordinator.path == ["/servers/7/logs"])
            #expect(pushed.node === loaded)
            #expect(pushedChanges == 1)
            #expect(rootChanges == 0)
        }

        @Test func republishingTheSameTreeDoesNotRedrawTheLevel() {
            let coordinator = NavigationCoordinator.shared
            coordinator.reset()
            coordinator.receive(uri: "/servers", depth: 1, rootNode: screen(1))
            let tree = screen(2)
            coordinator.receive(uri: "/servers/7/logs", depth: 2, rootNode: tree)
            let pushed = coordinator.level(for: "/servers/7/logs")
            coordinator.receive(uri: "/servers/7/logs", depth: 2, rootNode: tree)

            let pushedChanges = changes(of: pushed) {
                coordinator.receive(uri: "/servers/7/logs", depth: 2, rootNode: tree)
            }

            #expect(pushedChanges == 0)
        }

        @Test func republishingAScreenPushedInsideATabRedrawsItsLevel() {
            let coordinator = PerTabNavigationCoordinator(rootUri: "/servers")
            coordinator.receive(uri: "/servers", depth: 1, rootNode: screen(1))
            coordinator.receive(uri: "/servers/7", depth: 2, rootNode: screen(2))
            let pushed = coordinator.level(for: "/servers/7")
            let loaded = screen(3)

            let pushedChanges = changes(of: pushed) {
                coordinator.receive(uri: "/servers/7", depth: 2, rootNode: loaded)
            }

            #expect(coordinator.path == ["/servers/7"])
            #expect(pushed.node === loaded)
            #expect(pushedChanges == 1)
        }
    }
}
