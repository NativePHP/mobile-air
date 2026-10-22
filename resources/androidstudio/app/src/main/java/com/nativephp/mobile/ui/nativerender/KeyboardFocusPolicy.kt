package com.nativephp.mobile.ui.nativerender

/**
 * Focus policy shared between the text-input renderers, which know
 * whether the focused field opted into `keep-focus-on-submit`, and
 * the gesture layer, which decides whether a tap on an interactive
 * element should also dismiss the keyboard (mobile-air #335).
 */
object KeyboardFocusPolicy {
    /**
     * Token for the field that owns [focusedFieldKeepsFocus], null when
     * no field is focused. When focus moves between two fields the old
     * field's blur can arrive after the new field's focus, so a blur only
     * clears the flag when it comes from the current owner.
     */
    @Volatile
    private var focusedField: Any? = null

    /** Publishes the focused input's pending text before an interactive action. */
    @Volatile
    private var flushFocusedField: (() -> Unit)? = null

    /** Whether the focused field opted into `keep-focus-on-submit`. */
    @Volatile
    var focusedFieldKeepsFocus = false
        private set

    /**
     * Registered by the root renderer from composition, since gesture
     * modifiers run outside composable scope and cannot reach the
     * FocusManager themselves. Cleared when the root leaves.
     */
    @Volatile
    var clearFocus: (() -> Unit)? = null

    /**
     * Called by an input when it gains focus. [field] identifies the
     * input and must be the same instance it later passes to
     * [fieldBlurred]. [flush] must synchronously publish pending text
     * (and cancel any debounce), without changing focus. Like the iOS
     * policy, this hook runs on the UI thread before the press is posted.
     */
    fun fieldFocused(field: Any, keepsFocus: Boolean, flush: (() -> Unit)? = null) {
        focusedField = field
        focusedFieldKeepsFocus = keepsFocus
        flushFocusedField = flush
    }

    /**
     * Called by an input when it blurs or leaves composition. Ignored
     * unless [field] is the current owner (compared by identity), so a
     * late blur from the previous field cannot clear the flag of the one
     * that took over.
     */
    fun fieldBlurred(field: Any) {
        if (focusedField !== field) return

        focusedField = null
        focusedFieldKeepsFocus = false
        flushFocusedField = null
    }

    /**
     * Flush pending input before the caller posts its interactive event,
     * then clear focus unless the field asked to keep it through sends.
     * Keeping focus must not skip model publication. Plain-area taps skip
     * this and always clear, so tap-away keeps working. This orders event
     * posting; it does not wait for PHP to process the change.
     */
    fun dismissForInteractiveTap() {
        flushFocusedField?.invoke()
        if (!focusedFieldKeepsFocus) {
            clearFocus?.invoke()
        }
    }
}
