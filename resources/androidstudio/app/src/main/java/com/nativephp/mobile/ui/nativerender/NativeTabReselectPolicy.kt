package com.nativephp.mobile.ui.nativerender

internal fun nativeTabCanReselect(
    selectedIndex: Int,
    activeIndex: Int,
    requestedIndex: Int,
    hasPendingNavigation: Boolean,
    isNavigationTab: Boolean,
): Boolean = isNavigationTab && !hasPendingNavigation &&
    selectedIndex == requestedIndex && activeIndex == requestedIndex
