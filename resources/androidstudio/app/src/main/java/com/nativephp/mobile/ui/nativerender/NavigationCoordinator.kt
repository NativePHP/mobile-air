package com.nativephp.mobile.ui.nativerender

import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf

/**
 * Bridges PHP's authoritative router stack with the Compose renderer so:
 *
 * - PHP-initiated navigation (`navigate()` / `back()` / `replace()`)
 *   pushes / pops / swaps entries on `path`; AnimatedContent inside the
 *   stack renderer animates the result.
 * - User-initiated pop (system back button, predictive-back gesture)
 *   shrinks `path`; the renderer's BackHandler fires
 *   `sendSystemBackEvent` so the PHP runloop pops to match.
 *
 * **Path semantics.** `path` contains ONLY the pushed levels above the
 * root. The bottommost (root) URI is tracked separately as `rootUri`.
 *
 * **Per-URI cache.** `rootNodeCache` holds the most recent rendered tree
 * for each level (root and pushed). The renderer reads from this so each
 * stack level can render its actual content during transitions.
 *
 * Direct port of the iOS `NavigationCoordinator.swift`.
 */
object NavigationCoordinator {

    /** Pushed URIs only (excludes the root). Empty when on root. */
    val path = mutableStateListOf<String>()

    /** Bottommost URI in the stack — the persistent root. */
    val rootUri = mutableStateOf<String?>(null)

    /** Most-recent rendered tree per URI. */
    val rootNodeCache = mutableStateMapOf<String, NativeUINode>()

    /** Snapshot of `path` immediately after each PHP-driven mutation. */
    private var phpSnapshot: List<String> = emptyList()

    /**
     * Last node ref the path-reconciliation logic actually processed.
     * Used to recognise stale recompositions — see `receive(uri:rootNode:)`.
     */
    private var lastProcessedNode: NativeUINode? = null

    /**
     * PHP router stack depth when this stack session's root was seeded.
     * Absolute depth from PHP maps to a path length as `depth - rootDepth`.
     */
    private var rootDepth: Int = 1

    /**
     * Update the per-URI cache only. Safe to call from inside a Compose
     * composition since `rootNodeCache` is observable but writing it
     * during composition (when the value didn't change) is a no-op
     * regarding recomposition.
     */
    fun cache(uri: String, node: NativeUINode) {
        if (uri.isEmpty()) return
        rootNodeCache[uri] = node
    }

    /**
     * Called whenever PHP publishes a new `native_root_stack` tree.
     * Reconciles the stack with the published URI (push / pop / no-op).
     */
    fun receive(uri: String, depth: Int = 0, rootNode: NativeUINode) {
        if (uri.isEmpty()) return

        // Always cache the latest content for this URI.
        rootNodeCache[uri] = rootNode

        // Stale recomposition guard. The renderer schedules a deferred
        // receive on every recomposition; if Compose recomposed without
        // a new publish (e.g. from a state change in a sibling),
        // `node` will be the same ref we last acted on. Skipping the
        // reconciliation prevents path mutations on stale data.
        if (lastProcessedNode === rootNode) {
            return
        }
        lastProcessedNode = rootNode

        // Seed: very first publish for this stack — establish the root.
        if (rootUri.value == null) {
            rootUri.value = uri
            rootDepth = if (depth > 0) depth else 1
            phpSnapshot = emptyList()
            return
        }

        // Depth-authoritative reconciliation — distinguishes pushing a NEW
        // level whose URI already exists lower on the stack (a revisit)
        // from popping back to it; URI membership alone cannot.
        if (depth > 0) {
            receiveByDepth(uri, depth)
            return
        }

        // ── Legacy URI-based reconciliation (no stack_depth prop) ──

        // Re-render of root (PHP popped all the way back, or pure state
        // change at root). Clear any pushed levels.
        if (uri == rootUri.value) {
            if (path.isNotEmpty()) {
                path.clear()
            }
            phpSnapshot = path.toList()
            evictStaleCacheEntries()
            return
        }

        // Re-render of the current top-of-stack — state change, not nav.
        if (path.lastOrNull() == uri) {
            phpSnapshot = path.toList()
            return
        }

        // PHP popped to an intermediate pushed level — trim path.
        val idx = path.indexOf(uri)
        if (idx >= 0) {
            val nextPath = path.subList(0, idx + 1).toList()
            phpSnapshot = nextPath
            path.clear()
            path.addAll(nextPath)
            evictStaleCacheEntries()
            return
        }

        // New URI — push.
        phpSnapshot = path.toList() + uri
        path.add(uri)
        evictStaleCacheEntries()
    }

    /**
     * Depth-authoritative reconciliation. `target` is the number of
     * pushed levels this publish implies above the stack session's root.
     * Mirrors the iOS NavigationCoordinator.receiveByDepth.
     */
    private fun receiveByDepth(uri: String, depth: Int) {
        val target = depth - rootDepth

        // At (or somehow below) the session root — re-root and clear.
        if (target <= 0) {
            rootUri.value = uri
            rootDepth = depth
            if (path.isNotEmpty()) {
                path.clear()
            }
            phpSnapshot = path.toList()
            evictStaleCacheEntries()
            return
        }

        // State-change republish of the current top — no path mutation.
        if (path.size == target && path.lastOrNull() == uri) {
            phpSnapshot = path.toList()
            return
        }

        val next = path.toMutableList()
        if (next.size > target) {
            // Popped one or more levels.
            while (next.size > target) {
                next.removeAt(next.lastIndex)
            }
            if (next.lastOrNull() != uri) {
                // replace() landed at this level with a different URI.
                next[target - 1] = uri
            }
        } else if (next.size == target) {
            // Same level, different URI — replace() semantics.
            next[target - 1] = uri
        } else {
            // Pushed. Normally one level; backfill defensively if PHP
            // skipped levels.
            while (next.size < target - 1) {
                next.add(uri)
            }
            next.add(uri)
        }
        phpSnapshot = next.toList()
        path.clear()
        path.addAll(next)
        evictStaleCacheEntries()
    }

    /**
     * Called from the renderer when the user-driven path shrinks (system
     * back button, predictive-back gesture). Fires
     * `sendSystemBackEvent` for each level lost so the PHP runloop pops
     * its own stack to match.
     *
     * Cache for the popped URI is NOT evicted here — Compose may still
     * be animating the popping destination through its exit transition,
     * and dropping the cache mid-animation would replace the popping
     * view's content with the empty fallback. The follow-up `receive()`
     * from PHP's republish handles eviction.
     */
    fun onPathChange(newPath: List<String>) {
        if (newPath == phpSnapshot) {
            return
        }
        val popsNeeded = phpSnapshot.size - newPath.size
        if (popsNeeded > 0) {
            repeat(popsNeeded) {
                NativeElementBridge.sendSystemBackEvent()
            }
        }
        phpSnapshot = newPath.toList()
    }

    /**
     * Drop cache entries whose URI is no longer on the stack. Keeps
     * memory bounded under deep navigation.
     */
    private fun evictStaleCacheEntries() {
        val live = mutableSetOf<String>()
        live.addAll(path)
        rootUri.value?.let { live.add(it) }
        val stale = rootNodeCache.keys.filter { it !in live }
        stale.forEach { rootNodeCache.remove(it) }
    }

    /**
     * Clear all stack state. Called by NativeElementBridge's shadow loop
     * when a `native_root_stack` tree cold-mounts (previous publish was a
     * different root sentinel) — the singleton survives renderer teardown,
     * so without this the next stack session would treat its first URI as
     * a push on top of the previous session's stale root.
     */
    fun reset() {
        rootUri.value = null
        rootDepth = 1
        path.clear()
        phpSnapshot = emptyList()
        rootNodeCache.clear()
        lastProcessedNode = null
    }
}
