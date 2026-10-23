package com.nativephp.mobile.ui.nativerender

import java.util.concurrent.ConcurrentHashMap

/**
 * Registry of the app's currently-mounted native scroll containers that a
 * bottom-tab **reselect** can act on.
 *
 * This is the core half of the reselect scroll seam. Core stays
 * list-agnostic: it never names a list type, a pager, or the UI plugin —
 * a renderer that owns a scrollable container (e.g. the mobile-ui
 * plugin's list) registers it here while it is composed and unregisters
 * it on dispose. [NativeRootTabsRenderer] only looks entries up by the
 * string key a `Tab::onReselect(method, scrollTarget:)` declared.
 *
 * Entry contract (register from a `DisposableEffect`, keyed by the
 * container's identity):
 *
 * ```
 * val owner = remember { Any() }
 * DisposableEffect(listKey) {
 *     NativeScrollTargetRegistry.register(
 *         key = listKey,
 *         owner = owner,
 *         isAtTop = { state.firstVisibleItemIndex == 0
 *                     && state.firstVisibleItemScrollOffset == 0 },
 *         scrollToTop = { state.animateScrollToItem(0) },
 *     )
 *     onDispose { NativeScrollTargetRegistry.unregister(listKey, owner) }
 * }
 * ```
 *
 * Rules:
 *  - `isAtTop` must report the EXACT top state — first visible item index
 *    0 AND a zero pixel offset within it. A visible first row with a
 *    non-zero offset is NOT top; PHP's visible-row approximation must
 *    never be used.
 *  - `scrollToTop` is a suspend function that brings the container to
 *    that exact top state; the tabs renderer launches it on its own
 *    composition-scoped coroutine and cancels it on dispose.
 *  - Register/unregister on the main thread (Compose composition).
 *  - `unregister` matches the owner token by identity, so a stale
 *    `onDispose` from a superseded composition can never remove a newer
 *    registration made under the same key — pass a fresh owner token per
 *    composition (as above) and re-registration replaces the old entry.
 *
 * The registry holds no PHP state and issues no network calls; it is only
 * consulted on a bottom-tab tap.
 */
object NativeScrollTargetRegistry {

    /** One mounted scroll target. */
    class Entry(
        val key: String,
        val owner: Any,
        val isAtTop: () -> Boolean,
        val scrollToTop: suspend () -> Unit,
    )

    private val entries = ConcurrentHashMap<String, Entry>()

    /**
     * Register the scroll target for [key], replacing any entry previously
     * registered under it (the newest composition wins).
     */
    fun register(
        key: String,
        owner: Any,
        isAtTop: () -> Boolean,
        scrollToTop: suspend () -> Unit,
    ) {
        entries[key] = Entry(key, owner, isAtTop, scrollToTop)
    }

    /**
     * Remove [key]'s entry only while it is still owned by [owner]
     * (identity match). Disposal from an old composition whose key a new
     * composition already re-registered leaves the live entry in place.
     */
    fun unregister(key: String, owner: Any) {
        val current = entries[key] ?: return
        if (current.owner === owner) {
            entries.remove(key, current)
        }
    }

    /** The entry currently registered for [key], or null when none is. */
    fun get(key: String): Entry? = entries[key]

    /** Drop every entry (test isolation). */
    fun clear() = entries.clear()
}
