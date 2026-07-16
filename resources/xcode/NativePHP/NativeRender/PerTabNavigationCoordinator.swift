import SwiftUI

/// Per-tab navigation coordinator for `NativeRootTabsRenderer`.
///
/// Same shape as `NavigationCoordinator` but parameterized by the tab's
/// declared URL — every coordinator knows which URL is its tab's root,
/// so we don't seed a `rootUri` from the first publish (which would mix
/// up tabs when the publish belongs to a *different* tab).
///
/// Multiple tabs each get their own instance; the `TabCoordinatorBag`
/// holds them by tab index so they survive recompositions.
final class PerTabNavigationCoordinator: ObservableObject {

    /// The tab's declared URL — pushes onto this tab's stack must
    /// either equal this URL (re-render of root) or start with
    /// `rootUri + "/"` (push of a sub-route within the tab).
    let rootUri: String

    /// SwiftUI's `NavigationStack(path:)` binds to this. Pushed levels
    /// only — the root URI is implicit and rendered by the closure body.
    @Published var path: [String] = []

    /// Most-recent rendered tree per URI for this tab (root + pushed).
    /// `@Published` so `PerTabContent` recomposes when the cache fills
    /// in for an inactive tab — without it, sibling tabs stay at their
    /// `Color.clear` cache-miss fallback even after their own content
    /// has been published, since `path` (the only other observable)
    /// hasn't changed for the cache-only update.
    @Published var rootNodeCache: [String: NativeUINode] = [:]

    /// Snapshot of `path` immediately after each PHP-driven mutation.
    /// `onPathChange` compares against this to differentiate PHP changes
    /// (no-op) from user-initiated pops (fire `sendSystemBackEvent`).
    private var phpSnapshot: [String] = []

    /// Last node ref the path-reconciliation logic acted on. Used to
    /// recognize stale body re-runs that would otherwise re-push the
    /// same URI; mirrors the guard in `NavigationCoordinator`.
    private var lastProcessedNode: NativeUINode?

    init(rootUri: String) {
        self.rootUri = rootUri
    }

    /// Synchronously update the cache for this URI. Safe to call during
    /// a SwiftUI `body` evaluation since the cache isn't @Published.
    func cache(uri: String, node: NativeUINode) {
        guard !uri.isEmpty else { return }
        rootNodeCache[uri] = node
    }

    /// Reconcile this tab's path with a PHP publish whose `currentUri`
    /// has been determined to belong to this tab.
    func receive(uri: String, rootNode: NativeUINode) {
        guard !uri.isEmpty else { return }

        rootNodeCache[uri] = rootNode

        if lastProcessedNode === rootNode {
            return
        }
        lastProcessedNode = rootNode

        // Re-render of tab root — pop all pushed levels.
        if uri == rootUri {
            if !path.isEmpty {
                path = []
            }
            phpSnapshot = path
            evictStaleCacheEntries()
            return
        }

        // Re-render of the current top-of-stack (state change at pushed level).
        if path.last == uri {
            phpSnapshot = path
            return
        }

        // PHP popped to an intermediate pushed level — trim path.
        if let idx = path.firstIndex(of: uri) {
            let nextPath = Array(path.prefix(idx + 1))
            phpSnapshot = nextPath
            path = nextPath
            evictStaleCacheEntries()
            return
        }

        // Push a new pushed level onto this tab's stack.
        let nextPath = path + [uri]
        phpSnapshot = nextPath
        path = nextPath
        evictStaleCacheEntries()
    }

    /// Called from the renderer's `.onChange(of: coord.path)` for this
    /// tab. If the path matches our last PHP-driven snapshot the change
    /// is ours (no-op); otherwise a user-initiated swipe-back / system-
    /// back gesture shrunk the path — fire `sendSystemBackEvent` for
    /// each level lost so the PHP runloop pops to match.
    func onPathChange(newPath: [String]) {
        if newPath == phpSnapshot {
            return
        }
        let popsNeeded = phpSnapshot.count - newPath.count
        if popsNeeded > 0 {
            for _ in 0..<popsNeeded {
                NativeElementBridge.sendSystemBackEvent()
            }
        }
        phpSnapshot = newPath
    }

    /// Drop cache entries whose URI is no longer on this tab's stack.
    /// `rootUri` is always live; pushed entries are live while present.
    private func evictStaleCacheEntries() {
        var live = Set(path)
        live.insert(rootUri)
        for uri in rootNodeCache.keys where !live.contains(uri) {
            rootNodeCache.removeValue(forKey: uri)
        }
    }
}

/// Holds per-tab coordinators by tab index. Used as `@StateObject` on
/// the tabs renderer so coordinators survive across publishes / body
/// re-runs (the bag is created once per renderer instance).
final class TabCoordinatorBag: ObservableObject {
    private var coords: [Int: PerTabNavigationCoordinator] = [:]

    /// Returns the coordinator for the given tab index, lazily creating
    /// one bound to `rootUri` on first access. Identity is (index +
    /// rootUri): a tabs→tabs chrome swap (two layouts that both use
    /// native tab chrome) reuses the renderer instance — and with it
    /// this bag — so index alone would hand the new layout's tab a
    /// stale coordinator rooted at the OLD layout's URL. `receive()`
    /// then treats the new tab root as a pushed level, which hides the
    /// tab bar. A mismatched `rootUri` mints a fresh coordinator
    /// (empty path → root level) instead.
    func coordinator(forIdx idx: Int, rootUri: String) -> PerTabNavigationCoordinator {
        if let existing = coords[idx], existing.rootUri == rootUri {
            return existing
        }
        let fresh = PerTabNavigationCoordinator(rootUri: rootUri)
        coords[idx] = fresh
        return fresh
    }
}
