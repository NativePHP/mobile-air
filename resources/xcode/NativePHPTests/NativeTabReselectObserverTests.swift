import Testing
import UIKit
@testable import NativePHP

@MainActor
struct NativeTabReselectObserverTests {
    @Test func selectedViewControllerDispatchesOncePerSelection() async {
        let tabs = UITabBarController()
        let home = UIViewController()
        tabs.viewControllers = [home, UIViewController()]
        var calls = 0
        let proxy = NativeTabReselectDelegate { calls += 1 }

        #expect(proxy.tabBarController(tabs, shouldSelect: home))
        #expect(proxy.tabBarController(tabs, shouldSelect: home))
        await drainMainQueue()

        #expect(calls == 1)

        #expect(proxy.tabBarController(tabs, shouldSelect: home))
        await drainMainQueue()

        #expect(calls == 2)
    }

    @available(iOS 18.0, *)
    @Test func selectedTabDispatchesWithoutDuplicatingTheLegacyCallback() async {
        let tabs = UITabBarController()
        let homeController = UIViewController()
        let home = UITab(title: "Home", image: nil, identifier: "home") { _ in homeController }
        tabs.tabs = [home]
        tabs.selectedTab = home
        var calls = 0
        let proxy = NativeTabReselectDelegate { calls += 1 }

        #expect(proxy.tabBarController(tabs, shouldSelectTab: home))
        #expect(proxy.tabBarController(tabs, shouldSelect: homeController))
        await drainMainQueue()

        #expect(calls == 1)
    }

    @Test func switchingTabsCancelsAnUndeliveredReselect() async {
        let tabs = UITabBarController()
        let home = UIViewController()
        let other = UIViewController()
        tabs.viewControllers = [home, other]
        var calls = 0
        let proxy = NativeTabReselectDelegate { calls += 1 }

        #expect(proxy.tabBarController(tabs, shouldSelect: home))
        #expect(proxy.tabBarController(tabs, shouldSelect: other))
        await drainMainQueue()

        #expect(calls == 0)
    }

    @Test func preservesTheOriginalDelegatesSelectionDecisionAndNotifications() async {
        let tabs = UITabBarController()
        let home = UIViewController()
        tabs.viewControllers = [home]
        let original = SelectionDelegate()
        original.allowsSelection = false
        var calls = 0
        let proxy = NativeTabReselectDelegate { calls += 1 }
        proxy.original = original

        #expect(!proxy.tabBarController(tabs, shouldSelect: home))
        let delegate: UITabBarControllerDelegate = proxy
        delegate.tabBarController?(tabs, didSelect: home)
        await drainMainQueue()

        #expect(calls == 0)
        #expect(original.selections == 1)
    }

    @Test func detachingRestoresTheOriginalDelegateAndCancelsQueuedWork() async {
        let tabs = UITabBarController()
        let home = UIViewController()
        tabs.viewControllers = [home]
        let original = SelectionDelegate()
        tabs.delegate = original
        var calls = 0
        let observer = NativeTabReselectController { calls += 1 }
        home.addChild(observer)
        observer.didMove(toParent: home)
        #expect(tabs.delegate === observer.proxy)

        #expect(observer.proxy.tabBarController(tabs, shouldSelect: home))
        observer.detach()
        await drainMainQueue()

        #expect(tabs.delegate === original)
        #expect(calls == 0)
    }

    @Test(arguments: [false, true])
    func replacingAnObserverDoesNotChainItsCallbacks(hasOriginalDelegate: Bool) async {
        let tabs = UITabBarController()
        let home = UIViewController()
        tabs.viewControllers = [home]
        let original = hasOriginalDelegate ? SelectionDelegate() : nil
        tabs.delegate = original
        var oldCalls = 0
        var newCalls = 0
        let old = NativeTabReselectController { oldCalls += 1 }
        let new = NativeTabReselectController { newCalls += 1 }
        home.addChild(old)
        old.didMove(toParent: home)
        home.addChild(new)
        new.didMove(toParent: home)

        old.detach()
        #expect(tabs.delegate === new.proxy)
        #expect(new.proxy.original === original)
        #expect(new.proxy.tabBarController(tabs, shouldSelect: home))
        await drainMainQueue()

        #expect(oldCalls == 0)
        #expect(newCalls == 1)
        new.detach()
        #expect(tabs.delegate === original)
    }

    private func drainMainQueue() async {
        await withCheckedContinuation { continuation in
            DispatchQueue.main.async { continuation.resume() }
        }
    }
}

@MainActor
private final class SelectionDelegate: NSObject, UITabBarControllerDelegate {
    var allowsSelection = true
    var selections = 0

    func tabBarController(_ controller: UITabBarController, shouldSelect viewController: UIViewController) -> Bool {
        allowsSelection
    }

    func tabBarController(_ controller: UITabBarController, didSelect viewController: UIViewController) {
        selections += 1
    }
}
