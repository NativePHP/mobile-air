package com.nativephp.mobile.ui.nativerender

/**
 * Pure liveness ledger for the per-mount screen tokens a root chrome
 * frame carries (`screen_tokens`, bottom-up: root first, top screen
 * last). The list is PHP's authoritative truth about WHICH mounted
 * screens are alive; this helper turns two consecutive lists into the
 * exact eviction set — never a heuristic, never a cap.
 *
 * Lifecycle mapping (all driven by the source's own semantics):
 *  - push: a new token is appended; buried tokens are retained, so a
 *    plain back can restore the buried screen's saved state;
 *  - back / popTo unwind: popped tokens leave the list → evicted here,
 *    the resumed token stays → its saveable state survives the swap;
 *  - REPLACE / tab-switch replace / popTo replace-fallback: the old
 *    token leaves → evicted; the fresh mount's new token starts clean;
 *  - popTo no-op re-enter: the list is unchanged → nothing evicted;
 *  - account switch / process restart: every token is new → the whole
 *    previous ledger evicts on the first frame (tokens are random per
 *    mount, so a restarted process can never collide with stale ones).
 *
 * Memory is bounded by the logical stack: one saved bundle per LIVE
 * screen. No LRU, no global feed-key maps.
 */
object ScreenTokens {

    /**
     * Tokens present in [previous] but absent from [current]: mounts the
     * source no longer reports live. Their per-screen saveable state
     * must be dropped (`SaveableStateHolder.removeState`).
     */
    fun evicted(previous: List<String>, current: List<String>): List<String> =
        previous.filter { it !in current }
}
