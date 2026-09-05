package com.nativephp.mobile.ui.nativerender

/**
 * Focus policy shared between the text-input renderers, which know
 * whether the focused field opted into `keep-focus-on-submit`, and
 * the gesture layer, which decides whether a tap on an interactive
 * element should also dismiss the keyboard (mobile-air #335).
 */
object KeyboardFocusPolicy {
    /** Set by the input renderers on every focus change, cleared on blur. */
    @Volatile
    var focusedFieldKeepsFocus = false

    /**
     * Registered by the root renderer from composition, since gesture
     * modifiers run outside composable scope and cannot reach the
     * FocusManager themselves. Cleared when the root leaves.
     */
    @Volatile
    var clearFocus: (() -> Unit)? = null

    /**
     * Clear focus for a tap on an interactive element, unless the focused
     * field asked to keep focus through sends. Plain-area taps skip
     * this and always clear, so tap-away keeps working.
     */
    fun dismissForInteractiveTap() {
        if (!focusedFieldKeepsFocus) {
            clearFocus?.invoke()
        }
    }
}
