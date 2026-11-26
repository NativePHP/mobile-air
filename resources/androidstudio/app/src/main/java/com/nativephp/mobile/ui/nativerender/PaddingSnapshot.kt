package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.calculateEndPadding
import androidx.compose.foundation.layout.calculateStartPadding
import androidx.compose.ui.unit.LayoutDirection

/**
 * The padding's values as they are now, detached from the original.
 *
 * Material 3's `Scaffold` passes its content one `PaddingValues` that reads
 * from internal state and is updated in place when the bars change. A
 * renderer that keeps an outgoing screen's padding across a transition must
 * keep a copy: a kept reference follows the incoming screen's bars, so the
 * outgoing screen is re-laid out mid-transition (a screen with a hidden top
 * bar or an overlaid tab bar visibly jumps as it leaves).
 */
internal fun PaddingValues.snapshot(layoutDirection: LayoutDirection): PaddingValues = PaddingValues(
    start = calculateStartPadding(layoutDirection),
    top = calculateTopPadding(),
    end = calculateEndPadding(layoutDirection),
    bottom = calculateBottomPadding(),
)
