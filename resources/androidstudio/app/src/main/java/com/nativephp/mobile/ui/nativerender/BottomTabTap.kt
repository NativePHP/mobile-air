package com.nativephp.mobile.ui.nativerender

/**
 * What a bottom NavigationBar tap should do. Computed by
 * [decideBottomTabTap] so the exact branch order is unit-testable
 * without a Compose host; [NativeRootTabsRenderer] maps the returned
 * action to its side effects (URL-press bookkeeping, `sendPressEvent`,
 * the scroll coroutine launch).
 */
enum class BottomTabTapAction {
    /** Cross-tab (or pending-superseded) tap: fire the tab's URL
     *  `on_press` navigation callback — exactly the pre-reselect
     *  behavior, including the pendingTabId/superseded bookkeeping. */
    NavigateUrl,

    /** Active-tab reselect with no scroll work to do (no target
     *  configured, or the target is at exact top): fire the registered
     *  `on_reselect` callback (the PHP-side refresh). */
    FireReselectCallback,

    /** Active-tab reselect whose registered target is NOT at exact top:
     *  ONE tap does both — animate the target to top natively, then,
     *  after the scroll completes successfully, fire the `on_reselect`
     *  callback once. No PHP round trip before the scroll, no remount.
     *  Whether the fire actually happens after the suspend is gated by
     *  [shouldFireReselectAfterScroll] (tab still active, no pending
     *  navigation, scroll entry identity unchanged, tab still declaring
     *  the same target key, fresh callback id) — a canceled scroll, a
     *  tab switch, a navigation in flight, or a replaced tree must
     *  never refresh the wrong or an abandoned view. */
    ScrollThenReselect,

    /** Nothing to do — search tab, unconfigured callback, missing scroll
     *  target, no URL press available, or a scroll already in flight
     *  (re-taps coalesce into the running scroll+refresh). */
    Noop,
}

/**
 * Branch order for a bottom-tab tap:
 *
 *  1. Search-role tabs never dispatch (iOS/Android-side overlay).
 *  2. A tap that navigates — a different tab, or the active tab while
 *     another navigation is still in flight (`pendingTabId != null`;
 *     the user tapping back mid-navigation is NAVIGATION, not a
 *     reselect) — fires the URL `on_press` when one exists.
 *  3. Otherwise the tap reselects the already-active tab:
 *     `on_reselect` unset → no-op (the pre-reselect behavior);
 *     no scroll target → always fire the callback;
 *     target registered and at EXACT top → fire the callback;
 *     target registered and scrolled down → scroll it to top natively
 *     and then fire the callback once the scroll completes (one tap =
 *     top + refresh; the post-scroll fire is gated, see above);
 *     target configured but unregistered → safely do nothing (never
 *     assume at-top);
 *     a scroll already in flight → let it settle; the re-tap coalesces
 *     into the running scroll+refresh (no second scroll, no double
 *     callback).
 */
fun decideBottomTabTap(
    actualIdx: Int,
    activeTabIdx: Int,
    hasPendingNavigation: Boolean,
    isSearchTab: Boolean,
    urlPressId: Int,
    reselectCbId: Int,
    scrollTargetKey: String,
    scrollTarget: NativeScrollTargetRegistry.Entry?,
    scrollInFlight: Boolean,
): BottomTabTapAction {
    if (isSearchTab) return BottomTabTapAction.Noop

    val tapNavigates = actualIdx != activeTabIdx || hasPendingNavigation
    if (tapNavigates) {
        return if (urlPressId != 0) BottomTabTapAction.NavigateUrl else BottomTabTapAction.Noop
    }

    if (reselectCbId == 0) return BottomTabTapAction.Noop

    if (scrollTargetKey.isEmpty()) return BottomTabTapAction.FireReselectCallback

    val target = scrollTarget ?: return BottomTabTapAction.Noop

    if (scrollInFlight) return BottomTabTapAction.Noop

    return if (target.isAtTop()) {
        BottomTabTapAction.FireReselectCallback
    } else {
        BottomTabTapAction.ScrollThenReselect
    }
}

/**
 * Post-scroll gate for the one-tap reselect contract. Evaluated AFTER
 * the scroll-to-top suspend completes, against the world as it is THEN
 * — never against the tap-time snapshot:
 *
 *  - [activeTabStillTapped]: the tab the user tapped is still the
 *    active tab (latest published tree AND the local selection). A
 *    tab switch mid-scroll is user navigation — the previous tab's
 *    feed must not be refreshed from under the user.
 *  - [noPendingNavigation]: no cross-tab navigation is in flight
 *    (`pendingTabId == null`, read live at gate time). Between a
 *    cross-tab press and PHP's replacement publish the published tree
 *    still names the tapped tab as active — but per-component
 *    callback registries restart numbering, so firing there can land
 *    an id collision on the incoming screen's registry and invoke an
 *    arbitrary method. The press window must be silent.
 *  - [scrollEntryUnchanged]: the entry registered for the captured key
 *    is still the EXACT instance that scrolled (identity, not mere
 *    presence). A same-key re-registration during the animation — a
 *    screen pushed over the feed and returned, a re-composed
 *    container — replaces the entry; the captured instance is then an
 *    abandoned view and must not be refreshed.
 *  - [tabStillDeclaresTarget]: the freshest published tab for the
 *    tapped id still declares the SAME `reselect_scroll_target` key.
 *    PHP can switch the selected feed and republish with a new key
 *    while the OLD target's neighbor page is still composed and its
 *    entry still registered — identity alone would pass, but the fire
 *    would refresh the feed the user just left.
 *  - [freshReselectCallbackId]: the reselect callback id re-read from
 *    the CURRENT tree. A republish may have re-registered callbacks
 *    with fresh ids; the tap-time id can be stale or dropped (0).
 *
 * A canceled scroll never reaches this gate (the coroutine dies in the
 * suspend). All five must hold for exactly one fire.
 */
fun shouldFireReselectAfterScroll(
    activeTabStillTapped: Boolean,
    noPendingNavigation: Boolean,
    scrollEntryUnchanged: Boolean,
    tabStillDeclaresTarget: Boolean,
    freshReselectCallbackId: Int,
): Boolean = activeTabStillTapped
    && noPendingNavigation
    && scrollEntryUnchanged
    && tabStillDeclaresTarget
    && freshReselectCallbackId != 0
