package com.nativephp.mobile.ui.nativerender

import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * Exact branch contract of the bottom-tab tap decision — the unit-testable
 * half of NativeRootTabsRenderer's onClick. The renderer only maps the
 * returned action to its side effects (URL press bookkeeping,
 * sendPressEvent, scroll coroutine launch).
 */
class BottomTabTapDecisionTest {

    private fun target(atTop: Boolean) =
        NativeScrollTargetRegistry.Entry("log-feed", Any(), { atTop }, {})

    private fun decide(
        actualIdx: Int = 0,
        activeTabIdx: Int = 0,
        hasPendingNavigation: Boolean = false,
        isSearchTab: Boolean = false,
        urlPressId: Int = 42,
        reselectCbId: Int = 7,
        scrollTargetKey: String = "log-feed",
        scrollTarget: NativeScrollTargetRegistry.Entry? = null,
        scrollInFlight: Boolean = false,
    ) = decideBottomTabTap(
        actualIdx = actualIdx,
        activeTabIdx = activeTabIdx,
        hasPendingNavigation = hasPendingNavigation,
        isSearchTab = isSearchTab,
        urlPressId = urlPressId,
        reselectCbId = reselectCbId,
        scrollTargetKey = scrollTargetKey,
        scrollTarget = scrollTarget,
        scrollInFlight = scrollInFlight,
    )

    @Test
    fun inactiveTabTapNavigatesByUrl() {
        assertEquals(
            BottomTabTapAction.NavigateUrl,
            decide(
                actualIdx = 1,
                activeTabIdx = 0,
                scrollTarget = target(atTop = true),
            ),
        )
    }

    @Test
    fun tapBackToActiveTabWhileAnotherNavigationIsPendingIsNavigationNotReselect() {
        assertEquals(
            BottomTabTapAction.NavigateUrl,
            decide(
                actualIdx = 0,
                activeTabIdx = 0,
                hasPendingNavigation = true,
                scrollTarget = target(atTop = true),
            ),
        )
    }

    @Test
    fun pendingNavigationWithNoUrlPressDoesNothing() {
        assertEquals(
            BottomTabTapAction.Noop,
            decide(
                actualIdx = 0,
                activeTabIdx = 0,
                hasPendingNavigation = true,
                urlPressId = 0,
            ),
        )
    }

    @Test
    fun activeReselectWithoutScrollTargetFiresTheCallback() {
        assertEquals(
            BottomTabTapAction.FireReselectCallback,
            decide(scrollTargetKey = "", scrollTarget = null),
        )
    }

    @Test
    fun activeReselectAtExactTopFiresTheCallback() {
        assertEquals(
            BottomTabTapAction.FireReselectCallback,
            decide(scrollTarget = target(atTop = true)),
        )
    }

    @Test
    fun activeReselectScrolledDownScrollsToTopWithoutInvokingTheCallback() {
        assertEquals(
            BottomTabTapAction.ScrollTargetToTop,
            decide(scrollTarget = target(atTop = false)),
        )
    }

    @Test
    fun activeReselectWithMissingScrollTargetSafelyDoesNothing() {
        // Never assume at-top when the configured target is unregistered.
        assertEquals(
            BottomTabTapAction.Noop,
            decide(scrollTarget = null),
        )
    }

    @Test
    fun repeatedTapWhileScrollIsInFlightDoesNothing() {
        // The in-flight scroll is already heading to top; re-reading
        // isAtTop() on its last frame must not turn the tap into a
        // refresh.
        assertEquals(
            BottomTabTapAction.Noop,
            decide(scrollTarget = target(atTop = true), scrollInFlight = true),
        )
    }

    @Test
    fun activeTapWithoutReselectCallbackIsANoOp() {
        assertEquals(
            BottomTabTapAction.Noop,
            decide(reselectCbId = 0, scrollTarget = target(atTop = true)),
        )
    }

    @Test
    fun searchTabTapsNeverDispatch() {
        assertEquals(
            BottomTabTapAction.Noop,
            decide(isSearchTab = true, actualIdx = 1, activeTabIdx = 0),
        )
    }
}
