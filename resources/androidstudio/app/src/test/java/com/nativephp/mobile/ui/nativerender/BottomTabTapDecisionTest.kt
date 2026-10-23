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
    fun activeReselectScrolledDownScrollsThenReselectsAfterCompletion() {
        // One-tap contract: the scroll happens natively first, and the
        // callback fires once after it completes — gated by
        // shouldFireReselectAfterScroll (covered below).
        assertEquals(
            BottomTabTapAction.ScrollThenReselect,
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
    fun repeatedTapWhileScrollIsInFlightCoalescesIntoTheRunningScrollAndRefresh() {
        // The in-flight scroll is already heading to top and will fire
        // the callback once it settles; the re-tap must not start a
        // second scroll or queue a second callback.
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

    // ── Post-scroll gate: one tap = top + refresh, only for the
    // world the scroll settled into ───────────────────

    private fun gate(
        activeTabStillTapped: Boolean = true,
        noPendingNavigation: Boolean = true,
        scrollEntryUnchanged: Boolean = true,
        tabStillDeclaresTarget: Boolean = true,
        freshReselectCallbackId: Int = 7,
    ) = shouldFireReselectAfterScroll(
        activeTabStillTapped = activeTabStillTapped,
        noPendingNavigation = noPendingNavigation,
        scrollEntryUnchanged = scrollEntryUnchanged,
        tabStillDeclaresTarget = tabStillDeclaresTarget,
        freshReselectCallbackId = freshReselectCallbackId,
    )

    @Test
    fun gateFiresOnceWhenTheWorldIsUnchangedAfterTheScroll() {
        assertEquals(true, gate())
    }

    @Test
    fun gateBlocksWhenTheUserSwitchedTabsMidScroll() {
        assertEquals(false, gate(activeTabStillTapped = false))
    }

    @Test
    fun gateBlocksAPendingCrossTabNavigationEvenWhileThePublishedTreeStillNamesTheTappedTab() {
        // The press→publish window: pendingTabId is set but PHP has not
        // republished yet, so activeTabStillTapped is still true. The
        // fire must stay silent — per-component callback registries
        // restart numbering, so a fire here can collide with an id on
        // the incoming screen's registry and invoke an arbitrary method.
        assertEquals(false, gate(noPendingNavigation = false))
    }

    @Test
    fun gateBlocksASameKeyEntryReplacementDuringTheScroll() {
        // A screen pushed over the feed and returned re-registers the
        // key with a NEW entry; the captured (scrolled) instance is an
        // abandoned view. Presence alone must not pass — identity must.
        assertEquals(false, gate(scrollEntryUnchanged = false))
    }

    @Test
    fun gateBlocksWhenTheFreshestTabSwitchedTargetKeysWhileTheOldNeighborStayedRegistered() {
        // PHP switched the selected feed and republished the tab with a
        // new reselect_scroll_target, but the old feed's neighbor page
        // is still composed and its entry still registered — identity
        // passes. Firing would refresh the feed the user just left.
        assertEquals(false, gate(tabStillDeclaresTarget = false))
    }

    @Test
    fun gateBlocksWhenTheFreshTreeHasNoReselectCallback() {
        // A republish that dropped the handler (id 0) must not fire a
        // stale tap-time id.
        assertEquals(false, gate(freshReselectCallbackId = 0))
    }
}
