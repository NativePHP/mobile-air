import Testing
@testable import NativePHP

/// A pushed screen belongs to the tab it was opened from, found on PHP's
/// router stack, not to whichever tab's URL it starts with.
struct PerTabNavigationCoordinatorPushOwnerTests {

    let tabs = ["/", "/people", "/settings"]

    func owner(_ stack: [String], tabs: [String]? = nil) -> Int? {
        PerTabNavigationCoordinator.tabIndex(pushing: stack.last ?? "", stack: stack, tabUrls: tabs ?? self.tabs)
    }

    @Test func aScreenUnderAnotherTabsUrlStaysInTheTabThatOpenedIt() {
        #expect(owner(["/", "/people/42"]) == 0)
        #expect(owner(["/", "/upcoming", "/people/42", "/people/42/edit"]) == 0)
        #expect(owner(["/settings", "/people/42"]) == 2)
    }

    @Test func aScreenPushedInItsOwnTabStaysThere() {
        #expect(owner(["/people", "/people/42"]) == 1)
    }

    /// A tab opened from a push notification that opens what it's about
    /// straight away (#742): the screen belongs to that tab, not the one
    /// underneath it.
    @Test func theHighestTabOnTheStackWins() {
        #expect(owner(["/log", "/notifications", "/notifications/commits/c1"], tabs: ["/log", "/notifications"]) == 1)
        #expect(owner(["/log", "/notifications", "/commits/c1"], tabs: ["/log", "/notifications"]) == 1)
    }

    /// A tab tap, or a navigation to another tab's screen, still switches
    /// tabs through the URL prefix match.
    @Test func aTabsOwnScreenIsLeftToTheUrlMatch() {
        #expect(owner(["/people"]) == nil)
        #expect(owner(["/", "/people"]) == nil)
        #expect(owner(["/", "/people?sort=birthday"]) == nil)
    }

    /// An app launched straight into a sub-route (#663) has no tab screen
    /// under it, and an older runtime sends no stack.
    @Test func noTabOnTheStackOrNoStackIsLeftToTheUrlMatch() {
        #expect(owner(["/people/42"]) == nil)
        #expect(PerTabNavigationCoordinator.tabIndex(pushing: "/people/42", stack: [""], tabUrls: tabs) == nil)
        #expect(PerTabNavigationCoordinator.tabIndex(pushing: "/people/42", stack: ["/", "/people/7"], tabUrls: tabs) == nil)
    }
}
