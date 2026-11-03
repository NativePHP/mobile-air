import Combine
import Testing
@testable import NativePHP

/// `NavigationCoordinator` is a singleton, so these run one at a time.
@Suite(.serialized)
struct NavigationCoordinatorRootReplaceTests {

    let coordinator = NavigationCoordinator.shared

    func screen(_ id: Int) -> NativeUINode {
        NativeUINode(
            id: id, type: "native_root_stack", layout: nil, style: nil,
            props: GenericProps(), onPress: 0, onLongPress: 0, children: []
        )
    }

    /// Counts `objectWillChange` sends while `body` runs.
    func changes(during body: () -> Void) -> Int {
        var count = 0
        let subscription = coordinator.objectWillChange.sink { _ in count += 1 }
        body()
        subscription.cancel()

        return count
    }

    @Test func replaceAtTheStackRootPublishesTheNewRoot() {
        coordinator.reset()
        coordinator.receive(uri: "/welcome", depth: 1, rootNode: screen(1))

        let published = changes {
            coordinator.receive(uri: "/installation", depth: 1, rootNode: screen(2))
        }

        #expect(coordinator.rootUri == "/installation")
        #expect(coordinator.path.isEmpty)
        #expect(published > 0)
    }
}
