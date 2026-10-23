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
 *    composition-scoped coroutine, cancels it on dispose, and — once it
 *    completes — fires the tab's reselect callback (one tap = top +
 *    refresh; see `shouldFireReselectAfterScroll` for the gate).
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

    /**
     * One mounted scroll target.
     *
     * [isAvailable] is a LIVE predicate read at the two instants that
     * matter — the tap (`decideBottomTabTap`) and the post-scroll gate
     * — both synchronous, outside composition. It closes the pager
     * gap where a hidden NEIGHBOR page stays composed and registered:
     * its registration is live, but the page is not the one on screen.
     * The UI plugin supplies the context (e.g. pager target page);
     * the default `{ true }` keeps every non-pager caller unchanged.
     */
    class Entry(
        val key: String,
        val owner: Any,
        val isAtTop: () -> Boolean,
        val scrollToTop: suspend () -> Unit,
        val isAvailable: () -> Boolean = { true },
    )

    private val entries = ConcurrentHashMap<String, Entry>()

    /**
     * Register the scroll target for [key], replacing any entry previously
     * registered under it (the newest composition wins). [isAvailable] is
     * evaluated LIVE on every [get] — see [Entry].
     */
    fun register(
        key: String,
        owner: Any,
        isAtTop: () -> Boolean,
        scrollToTop: suspend () -> Unit,
        isAvailable: () -> Boolean = { true },
    ) {
        entries[key] = Entry(key, owner, isAtTop, scrollToTop, isAvailable)
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

    /**
     * The entry currently registered for [key], or null when none is —
     * or when the entry itself reports it is NOT available (hidden
     * pager neighbor): both the tap-time and post-scroll reads go
     * through here, so an unavailable target behaves exactly like a
     * missing one at the moment it matters.
     */
    fun get(key: String): Entry? = entries[key]?.takeIf { it.isAvailable() }

    /** Drop every entry (test isolation). */
    fun clear() = entries.clear()
}
