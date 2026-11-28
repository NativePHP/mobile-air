package com.nativephp.mobile.ui.nativerender

/**
 * Per-mount screen tokens published on the native chrome sentinels
 * (`screen_tokens`): one opaque token per live screen on PHP's router
 * stack, bottom (root) first, one per line. A screen keeps its token
 * for as long as it stays mounted — re-renders and resumes included —
 * and every fresh mount gets a new one, even for the same URI.
 *
 * The chrome renderers key each screen's [androidx.compose.runtime.saveable.SaveableStateHolder]
 * entry by its token, so a pushed-over screen gets its saved view state
 * (a `native:list` scroll position) back on return, and a replaced or
 * popped screen's state is dropped instead of leaking into the next
 * mount of the same URI (#298).
 *
 * Derived from the screen token ledger in #588.
 */
object ScreenTokens {

    /** The `screen_tokens` prop as a list; empty when the frame carries none. */
    fun parse(raw: String): List<String> =
        if (raw.isEmpty()) emptyList() else raw.split('\n').filter { it.isNotEmpty() }

    /**
     * Tokens that were live in [previous] but are gone from [current]:
     * screens PHP popped or replaced. Their saved state must be removed.
     */
    fun evicted(previous: List<String>, current: List<String>): List<String> {
        val live = current.toHashSet()
        return previous.filter { it !in live }
    }

    /**
     * The token of the stack level at router depth [depth] (1 = the
     * bottom screen), or null when the frame doesn't cover that depth.
     */
    fun atDepth(tokens: List<String>, depth: Int): String? = tokens.getOrNull(depth - 1)
}
