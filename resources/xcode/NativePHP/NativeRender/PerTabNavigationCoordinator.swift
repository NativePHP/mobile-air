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
    /// Plain storage, NOT `@Published`. The tabs
    /// renderer fills it from inside `body` (`cache(uri:node:)`), and a
    /// published write there is "Publishing changes from within view
    /// updates" — undefined behaviour that SwiftUI logged as a fault on
    /// every tab-root publish. Observers still recompose when the cache fills
    /// in for an inactive tab (sibling tabs would otherwise stay at their
    /// `Color.clear` cache-miss fallback): every write bumps
    /// `cacheGeneration` on the next main-queue turn, outside the update.
    private var nodeCache: [String: NativeUINode] = [:]

    @Published private(set) var cacheGeneration: Int = 0

    var rootNodeCache: [String: NativeUINode] { nodeCache }

    private func storeNode(_ node: NativeUINode?, for uri: String) {
        if let node {
            nodeCache[uri] = node
        } else {
            nodeCache.removeValue(forKey: uri)
        }
        DispatchQueue.main.async { [weak self] in
            self?.cacheGeneration &+= 1
        }
    }

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
    /// a SwiftUI `body` evaluation: the store is plain and the
    /// recompose trigger is deferred.
    func cache(uri: String, node: NativeUINode) {
        guard !uri.isEmpty else { return }
        storeNode(node, for: uri)
    }

    /// Reconcile this tab's path with a PHP publish whose `currentUri`
    /// has been determined to belong to this tab.
    func receive(uri: String, rootNode: NativeUINode) {
        guard !uri.isEmpty else { return }

        storeNode(rootNode, for: uri)

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
            scheduleEviction()
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
            scheduleEviction()
            return
        }

        // Push a new pushed level onto this tab's stack.
        let nextPath = path + [uri]
        phpSnapshot = nextPath
        path = nextPath
        scheduleEviction()
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

    /// Evict AFTER the transition window, never synchronously — same
    /// rationale as `NavigationCoordinator.scheduleEviction()`: PHP
    /// republishes within ~10ms of a back event, so a synchronous evict
    /// blanks the popping screen to `Color.clear` mid-animation and
    /// flashes the level below. Liveness is recomputed at fire time, so
    /// overlapping schedules are harmless.
    private func scheduleEviction() {
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.6) { [weak self] in
            self?.evictStaleCacheEntries()
        }
    }

    /// Drop cache entries whose URI is no longer on this tab's stack.
    /// `rootUri` is always live; pushed entries are live while present.
    private func evictStaleCacheEntries() {
        var live = Set(path)
        live.insert(rootUri)
        for uri in nodeCache.keys where !live.contains(uri) {
            storeNode(nil, for: uri)
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
