import SwiftUI

/// Bridges PHP's authoritative router stack with SwiftUI's
/// `NavigationStack` `path` binding, so:
///
/// - **PHP-initiated navigation** (`navigate()` / `back()` / `replace()`)
///   pushes / pops / swaps entries on `path`; SwiftUI animates.
/// - **User-initiated pop** (edge-swipe-back, system back button) shrinks
///   `path`; the coordinator detects the shrink and fires
///   `sendSystemBackEvent` so the PHP runloop pops to match.
///
/// **Path semantics.** `path` contains ONLY the pushed levels above the
/// root. The bottommost (root) URI is tracked separately as `rootUri` —
/// SwiftUI's NavigationStack treats every entry in its `path` binding as
/// a pushed destination on top of the implicit root closure, so storing
/// the root in `path` would duplicate it (root + identical pushed copy =
/// two back chevrons, broken pop behavior).
///
/// Per-URI cache (`rootNodeCache`) holds the most recent rendered tree
/// for each level (root and pushed). The renderer's destination resolver
/// reads from this so during push / pop animations, both the from- and
/// to-screens render their actual content (not a blank placeholder).
final class NavigationCoordinator: ObservableObject {
    static let shared = NavigationCoordinator()

    /// SwiftUI's `NavigationStack(path:)` binds to this. Each entry is a
    /// pushed destination *above* the implicit root. Empty when on root.
    @Published var path: [String] = []

    /// Bottommost URI in the stack — the root of the NavigationStack
    /// closure. Set once on the first publish, then updated only when the
    /// renderer detects a fresh stack (different layout / cold remount).
    private(set) var rootUri: String?

    /// Most-recent rendered tree per URI (root and pushed levels). Used
    /// by the renderer's destination resolver so each stack level can
    /// render its actual content during transitions.
    private(set) var rootNodeCache: [String: NativeUINode] = [:]

    /// Snapshot of `path` immediately after each PHP-driven mutation.
    /// `onPathChange` compares against this to differentiate PHP changes
    /// (no-op) from user-initiated pops (fire `sendSystemBackEvent`).
    private var phpSnapshot: [String] = []

    /// Last node ref the path-reconciliation logic actually processed.
    /// Used to recognise stale body re-runs — see `receive(uri:rootNode:)`.
    private var lastProcessedNode: NativeUINode?

    private init() {}

    /// Update the per-URI cache only. Safe to call from inside a SwiftUI
    /// `body` since `rootNodeCache` is a plain (non-@Published) property
    /// — writing it does not trigger a re-render. The renderer calls
    /// this synchronously so destinations always render from the freshest
    /// tree, including mid-animation when a state-change publish lands.
    func cache(uri: String, node: NativeUINode) {
        guard !uri.isEmpty else { return }
        rootNodeCache[uri] = node
    }

    /// Called by the renderer's `body` whenever PHP publishes a new
    /// `native_root_stack` tree. Reconciles the stack with the published
    /// URI (push / pop / no-op). Cache is updated separately via
    /// `cache(uri:node:)` synchronously in body.
    func receive(uri: String, rootNode: NativeUINode) {
        guard !uri.isEmpty else { return }

        // Always cache the latest content for this URI.
        rootNodeCache[uri] = rootNode

        // Stale body re-run guard. SwiftUI re-runs the renderer's body
        // whenever `@Published path` changes — including the moment a
        // user-driven gesture-pop fires. At that point the parent
        // NodeView hasn't re-rendered yet, so the renderer still holds
        // the OLD `node` (the popping screen's tree). The deferred
        // receive(uri="/detail", node=oldRef) would then run AFTER the
        // gesture shrunk the path, see "/detail" not in path and not the
        // rootUri, and fall through to the PUSH branch — re-pushing the
        // destination the user just popped. SwiftUI would animate a push
        // on top of the in-flight pop, then a second pop once PHP
        // republishes the actual root, producing the "stop and start the
        // navigation over" jitter.
        //
        // `lastProcessedNode` tracks the ref this logic last acted on
        // (separate from the cache, which is also written synchronously
        // in body). If the same ref comes through again, it's a stale
        // body re-run — bail before mutating path.
        if lastProcessedNode === rootNode {
            return
        }
        lastProcessedNode = rootNode

        // Seed: very first publish for this stack — establish the root.
        if rootUri == nil {
            rootUri = uri
            phpSnapshot = []
            return
        }

        // Re-render of root (PHP popped all the way back, or pure state
        // change at root). Clear any pushed levels.
        if uri == rootUri {
            if !path.isEmpty {
                path = []
            }
            phpSnapshot = path
            scheduleEviction()
            return
        }

        // Re-render of the current top-of-stack — state change, not nav.
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

        // New URI — push.
        let nextPath = path + [uri]
        phpSnapshot = nextPath
        path = nextPath
        scheduleEviction()
    }

    /// Called from the renderer's `.onChange(of: path)`. If the new path
    /// matches our last PHP-driven snapshot, the change came from us —
    /// no action needed. Otherwise the user popped via gesture / back
    /// button; fire `sendSystemBackEvent` for each level lost.
    ///
    /// Cache for the popped URI is intentionally NOT evicted here:
    /// SwiftUI keeps rendering that destination through its pop animation,
    /// and removing the cache mid-flight would replace the popping view's
    /// content with `Color.clear` — visible as a jitter / flash. The
    /// follow-up `receive()` (after PHP processes `back()` and republishes)
    /// will evict.
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

    /// Evict AFTER the transition window, never synchronously. PHP
    /// republishes the destination screen within ~10ms of a back event —
    /// far inside the ~350ms pop animation — so evicting in `receive()`
    /// yanks the popping screen's cache entry mid-flight and its
    /// destination re-resolves to `Color.clear`: the screen blanks
    /// instantly (flashing the level below) before the animation has
    /// visibly run. Eviction is only memory hygiene; delaying it past any
    /// possible animation costs nothing. `evictStaleCacheEntries()`
    /// recomputes liveness from the path at fire time, so overlapping
    /// schedules are harmless.
    private func scheduleEviction() {
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.6) { [weak self] in
            self?.evictStaleCacheEntries()
        }
    }

    /// Drop cache entries whose URI is no longer on the stack. Keeps
    /// memory bounded under deep navigation.
    private func evictStaleCacheEntries() {
        var live = Set(path)
        if let r = rootUri { live.insert(r) }
        for uri in rootNodeCache.keys where !live.contains(uri) {
            rootNodeCache.removeValue(forKey: uri)
        }
    }

    /// Clear all stack state. Called by `NativeElementBridge`'s shadow
    /// loop when a `native_root_stack` tree cold-mounts (previous publish
    /// was a different root sentinel) — the singleton survives renderer
    /// teardown, so without this the next stack session would treat its
    /// first URI as a push on top of the previous session's stale root.
    /// Replace the storage directly to avoid notifying a stack during teardown.
    func reset() {
        rootUri = nil
        _path = Published(initialValue: [])
        phpSnapshot = []
        rootNodeCache = [:]
        lastProcessedNode = nil
    }
}
