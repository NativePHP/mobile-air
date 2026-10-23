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
     *  animate it to top natively — no PHP round trip, no navigation,
     *  no remount. */
    ScrollTargetToTop,

    /** Nothing to do — search tab, unconfigured callback, missing scroll
     *  target, no URL press available, or a scroll already in flight. */
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
 *     target registered and scrolled down → scroll it to top natively;
 *     target configured but unregistered → safely do nothing (never
 *     assume at-top);
 *     a scroll already in flight → let it settle (re-reading
 *     `isAtTop()` on the scroll's last frame could turn a stray tap
 *     into an accidental refresh).
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
        BottomTabTapAction.ScrollTargetToTop
    }
}
