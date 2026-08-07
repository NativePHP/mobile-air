
import Foundation
import UIKit // needed for UIScreen.main, UIApplication
import os
import Bridge // C prototypes: nphp_get_format_version / nphp_get_runtime_flags (Phase 0 format check)

/// Element Runtime bridge — reads flat buffer from PHP shared memory, posts tree to main thread.
///
/// Flat node layout (160 bytes packed, little-endian) — must match nphp_element.h:
///   0: id (u32)            4: type_idx (u16)       6: child_count (u16)
///   8: first_child_offset  12: on_press           16: on_long_press
///  20: width (f32)        24: width_mode (u8)    25: height (f32)
///  29: height_mode (u8)   30: padding[4] (4×f32) 46: margin[4] (4×f32)
///  62: flex_grow           66: flex_shrink         70: align_self (u8)
///  71: align_items (u8)   72: justify_content    73: gap (f32)
///  77: safe_area (u8)
///  --- Extended layout (flexbox) ---
///  78: min_width          82: min_height          86: max_width
///  90: max_height         94: flex_basis           98: flex_basis_mode (u8)
///  99: flex_wrap (u8)    100: flex_direction (u8) 101: position_type (u8)
/// 102: position[4] (4×f32)                       118: display (u8)
/// 119: overflow (u8)     120: align_content (u8)  121: direction (u8)
/// 122: aspect_ratio      126: row_gap
///  --- Style ---
/// 130: bg_color          134: border_radius       138: border_width
/// 142: border_color      146: opacity             150: elevation
/// 154: prop_offset       158: prop_size (u16)
final class NativeElementBridge {
    /// Node stride: 161 bytes — flex-layout base (160) plus the Phase 2
    /// appended `flags` byte at offset 160. Bumped in lockstep with
    /// NPHP_FORMAT_VERSION = 2. Legacy 108-byte and 160-byte nodes are
    /// not supported (format-version guard rejects mismatched producers).
    private static let nodeSize = 161

    /// Offset of the flags byte appended in format_version 2.
    private static let nodeFlagsOffset = 160

    /// Phase 2 — bit 0 in nphp_flat_node_t::flags. Marks a subtree as
    /// identical to the previous frame; this reader splices the prior
    /// node by id instead of parsing absent children.
    private static let nodeFlagReuse: UInt8 = 0x01

    // MARK: - Region struct offsets (arm64 Darwin, computed via offsetof)
    // Must match nphp_element_region_t in nphp_element.h

    private enum RegionOffset {
        static let magic: Int            = 0
        static let nodeCount: Int        = 16
        static let flatBufferSize: Int   = 20
        static let propBufferSize: Int   = 24
        // Event fields — DEAD, do not use. Kept only because removing them
        // would make the offsets below look like they'd shifted (they haven't).
        //
        // Post events with `nphp_element_post_event()` instead. As of format
        // v4 the channel is a queue of separately allocated frames: eventBuffer
        // is never written, eventSize is the head frame's size rather than "the"
        // event's, and eventCount is a depth rather than a 0/1 flag. Writing
        // here would be silently ignored; reading would be garbage.
        static let eventMutex: Int       = 144
        static let eventCond: Int        = 208
        static let eventSize: Int        = 256
        static let eventCount: Int       = 260
        static let eventBuffer: Int      = 264
        static let flatBuffer: Int       = 4360   // uint8_t* pointer
        static let propBuffer: Int       = 4368   // uint8_t* pointer
        static let typeCount: Int        = 4404   // uint8_t
        static let typeOffsets: Int       = 4406   // uint16_t[128]
        static let typeTable: Int        = 4662   // char[4096]
    }

    private static let elementMagic: UInt32 = 0x4E504845  // "NPHE"
    private static let eventMagic: UInt32   = 0x4E504556  // "NPEV"

    /// Phase 0 — wire-format version this reader was compiled against.
    /// Must match what the PHP extension reports via `nphp_get_format_version()`;
    /// a mismatch means libphp.a in the embedded PHP and this reader were
    /// built from divergent NPHP_FORMAT_VERSION values, and parsing the
    /// flat buffer would render garbage. Bump in lockstep with
    /// NPHP_FORMAT_VERSION in nphp_element.h (§5.4).
    ///
    /// v2 (Phase 2) — appended `flags` byte to flat node (stride 161).
    /// v3 — event-channel framing widened uint16 → uint32 (header data_size +
    ///      body string length prefixes); lifts the 64KB native→PHP cap.
    /// v4 — native→PHP event channel is a FIFO queue instead of a single slot,
    ///      so concurrent posts (async-task pool + watchdog) queue instead of
    ///      overwriting each other. Nothing changes for this reader: the
    ///      per-frame wire format is identical, the flat/prop buffers are
    ///      untouched, and events are still posted through
    ///      `nativeElementWriteEvent` → `nphp_element_post_event`. The version
    ///      moved because the region's event fields changed meaning, and a
    ///      stale libphp.a should fail loud rather than be quietly wrong.
    private static let expectedFormatVersion: UInt32 = 4

    /// Phase 0 — latched after the one-shot check in `postTreeUpdateFromRegion()`.
    /// NPHP_FLAG_* bitfield in nphp_element.h.
    static private(set) var runtimeFlags: UInt32 = 0

    /// Phase 0 — one-shot guard. Reset on `stopWatching()` so a new region
    /// registration (hot reload) re-verifies. Can't put the check only in
    /// `startWatching()` because publishes can arrive before the shadow
    /// thread is fully spun up — we need a guarantee that mismatched bytes
    /// never reach the parser (§5.4).
    private static var versionChecked: Bool = false

    /// Phase 0 — latched if a producer/reader format mismatch is detected.
    /// Once set, `postTreeUpdateFromRegion()` short-circuits.
    private static var versionMismatch: Bool = false

    /// Shared memory region pointer registered by PHP's nphp_element_init()
    private static var regionPtr: UnsafeMutableRawPointer?

    /// Type table from last update
    private static var cachedTypeTable: [String]?

    /// Previous tree for incremental diff
    private static var previousTree: NativeUITree?

    /// Per-URI previous tree for native chrome stacks. Used so that when
    /// PHP republishes a URI we've seen before (e.g. popping back to the
    /// stack root), we can diff against the LAST tree at that URI rather
    /// than the most recent unrelated tree — preserving subtree refs that
    /// haven't structurally changed. Without this, every nav publish
    /// arrives with all-new refs, NodeView's `===` equality fails, and
    /// SwiftUI re-renders the entire tree mid-animation = visible jitter.
    private static var nativeChromePrevTrees: [String: NativeUITree] = [:]

    // MARK: - Shadow Thread with Frame Coalescing
    // Mirrors Android's AtomicReference<ShadowUpdate> + LockSupport pattern.
    // PHP thread sets pendingUpdate and signals the shadow thread.
    // Shadow thread grabs the latest update atomically — intermediate frames are dropped.

    /// Snapshot of raw buffer data passed from PHP thread to shadow thread.
    private struct ShadowUpdate {
        let flatData: Data
        let propData: Data?
        let typeTable: [String]
        let nodeCount: Int
        let isNav: Bool
    }

    /// Lock protecting pendingUpdate (os_unfair_lock is ~25ns, lighter than pthread_mutex)
    private static var pendingLock = os_unfair_lock()

    /// Latest pending update — set by PHP thread, consumed by shadow thread.
    /// Overwrites any unconsumed update (frame coalescing).
    private static var pendingUpdate: ShadowUpdate?

    /// Shadow thread condvar + mutex for sleeping/waking
    private static var shadowMutex = pthread_mutex_t()
    private static var shadowCond = pthread_cond_t()

    /// Shadow thread reference and run flag
    private static var shadowThread: pthread_t?
    private static var shadowRunning = false

    // MARK: - Region Management

    static func registerRegion(_ region: UnsafeMutableRawPointer) {
        regionPtr = region
        startWatching()
        print("NativeElementBridge: region registered at \(region)")
    }

    static func unregisterRegion() {
        regionPtr = nil
        cachedTypeTable = nil
        previousTree = nil
        print("NativeElementBridge: region unregistered")
    }

    // MARK: - Post Tree Update from Region

    /// Called by `NativeElement_PostTreeUpdate` (@_cdecl) after PHP writes the flat buffer.
    /// Reads buffers and type table directly from the registered region using raw offsets,
    /// then hands off to the existing tree parser on the shadow queue.
    static func postTreeUpdateFromRegion() {
        guard let ptr = regionPtr else {
            print("NativeElementBridge: postTreeUpdateFromRegion — no region registered")
            return
        }

        // Phase 0 — one-shot wire-format check. Mirrors the Kotlin guard:
        // every publish path lands here, so this is the only spot that catches
        // mismatches regardless of how the shadow thread came up.
        if !versionChecked {
            let producerVersion = nphp_get_format_version()
            if producerVersion != expectedFormatVersion {
                print("NativeElementBridge: FORMAT VERSION MISMATCH — reader expects " +
                      "\(expectedFormatVersion) but PHP extension reports \(producerVersion). " +
                      "Rebuild libphp.a and this reader from matching sources " +
                      "(REFACTOR-native-ui-performance.md §5.4). Dropping all tree updates.")
                versionMismatch = true
            } else {
                runtimeFlags = nphp_get_runtime_flags()
                print(String(format: "NativeElementBridge: first publish — format_version=%u runtime_flags=0x%08x",
                             producerVersion, runtimeFlags))
            }
            versionChecked = true
        }
        if versionMismatch { return }

        let raw = UnsafeRawPointer(ptr)

        let magic = raw.load(fromByteOffset: RegionOffset.magic, as: UInt32.self)
        guard magic == elementMagic else {
            print("NativeElementBridge: bad magic 0x\(String(magic, radix: 16))")
            return
        }

        let nodeCount = Int(raw.load(fromByteOffset: RegionOffset.nodeCount, as: UInt32.self))

        // Phase 3 — pull the active half of the A/B ring through the C
        // extension's accessor. The acquire-load on `active_buf` inside
        // those functions pairs with the producer's release-store at
        // the end of publish, so the buffer + size we see are guaranteed
        // to be the latest fully-written pair. When the double-buffer
        // flag is off, these still resolve to the original buffer A —
        // i.e. behavior is identical to pre-Phase-3.
        var flatSize32: UInt32 = 0
        var propSize32: UInt32 = 0
        let flatBufPtr = nphp_get_active_flat_buffer(&flatSize32)
        let propBufPtr = nphp_get_active_prop_buffer(&propSize32)
        let flatSize = Int(flatSize32)
        let propSize = Int(propSize32)

        guard nodeCount > 0, flatSize > 0, let flatBuf = flatBufPtr else {
            print("NativeElementBridge: empty tree (nodes=\(nodeCount) flat=\(flatSize))")
            return
        }

        // Read type table from the region
        let typeTable = readTypeTable(from: raw)

        print("NativeElementBridge: postTreeUpdateFromRegion nodes=\(nodeCount) flat=\(flatSize) prop=\(propSize) types=\(typeTable.count)")

        postTreeUpdate(
            flatPtr: UnsafeRawPointer(flatBuf), flatSize: flatSize,
            propPtr: propBufPtr.map { UnsafeRawPointer($0) }, propSize: propSize,
            typeTable: typeTable, nodeCount: nodeCount
        )
    }

    /// Read the interned type table from the region using raw byte offsets.
    private static func readTypeTable(from raw: UnsafeRawPointer) -> [String] {
        let count = Int(raw.load(fromByteOffset: RegionOffset.typeCount, as: UInt8.self))
        guard count > 0 else { return [] }

        let offsetsBase = raw.advanced(by: RegionOffset.typeOffsets)
            .assumingMemoryBound(to: UInt16.self)
        let tableBase = raw.advanced(by: RegionOffset.typeTable)
            .assumingMemoryBound(to: CChar.self)

        var table: [String] = []
        table.reserveCapacity(count)

        for i in 0..<count {
            let off = Int(offsetsBase[i])
            let str = String(cString: tableBase.advanced(by: off))
            table.append(str)
        }

        return table
    }

    // MARK: - Tree Update (called from PHP thread via C bridge)

    /// Called from C bridge after nativephp_element_publish().
    /// Heap-copies buffers, sets pending update (coalescing any stale frame),
    /// and wakes the shadow thread. Returns immediately to unblock PHP.
    static func postTreeUpdate(
        flatPtr: UnsafeRawPointer, flatSize: Int,
        propPtr: UnsafeRawPointer?, propSize: Int,
        typeTable: [String], nodeCount: Int
    ) {
        // Phase 3 — when NPHP_FLAG_DOUBLE_BUFFER is on, wrap the C-side
        // buffer pointer as a no-copy Data view. The producer alternates
        // between buffer A and B, so the buffer we're viewing is
        // guaranteed to be untouched until the publish AFTER the next
        // one — safe as long as parse < 2× average publish interval.
        // Single-buffer + zero-copy would be use-after-overwrite unsafe,
        // hence the flag gate. Re-read runtime_flags each call so the
        // toggle takes effect on the next frame.
        let zeroCopy = (nphp_get_runtime_flags() & 0x04) != 0

        let flatData: Data
        let propData: Data?
        if zeroCopy {
            flatData = Data(
                bytesNoCopy: UnsafeMutableRawPointer(mutating: flatPtr),
                count: flatSize,
                deallocator: .none
            )
            propData = if let propPtr, propSize > 0 {
                Data(
                    bytesNoCopy: UnsafeMutableRawPointer(mutating: propPtr),
                    count: propSize,
                    deallocator: .none
                )
            } else {
                nil
            }
        } else {
            flatData = Data(bytes: flatPtr, count: flatSize)
            propData = if let propPtr, propSize > 0 {
                Data(bytes: propPtr, count: propSize)
            } else {
                nil
            }
        }

        let isNav = NativeUIBridge.shared.navigationPending
        if isNav { NativeUIBridge.shared.navigationPending = false }

        cachedTypeTable = typeTable

        let update = ShadowUpdate(
            flatData: flatData, propData: propData,
            typeTable: typeTable, nodeCount: nodeCount, isNav: isNav
        )

        // Atomically set pending update — overwrites any unconsumed frame (coalescing)
        os_unfair_lock_lock(&pendingLock)
        pendingUpdate = update
        os_unfair_lock_unlock(&pendingLock)

        // Ensure shadow thread is running (self-healing)
        ensureShadowThread()

        // Wake shadow thread
        pthread_mutex_lock(&shadowMutex)
        pthread_cond_signal(&shadowCond)
        pthread_mutex_unlock(&shadowMutex)
    }

    // MARK: - Shadow Thread

    /// Ensures the shadow thread is running. Starts it if not.
    private static func ensureShadowThread() {
        guard !shadowRunning else { return }

        shadowRunning = true
        pthread_mutex_init(&shadowMutex, nil)
        pthread_cond_init(&shadowCond, nil)

        var thread = pthread_t(bitPattern: 0)
        var attr = pthread_attr_t()
        pthread_attr_init(&attr)
        pthread_attr_setdetachstate(&attr, PTHREAD_CREATE_DETACHED)
        // High QoS for UI responsiveness
        pthread_attr_set_qos_class_np(&attr, QOS_CLASS_USER_INTERACTIVE, 0)

        pthread_create(&thread, &attr, { _ in
            NativeElementBridge.shadowThreadLoop()
            return nil
        }, nil)

        pthread_attr_destroy(&attr)
        shadowThread = thread
    }

    /// Shadow thread main loop. Sleeps on condvar, wakes when postTreeUpdate
    /// signals. Grabs the latest pending update atomically (coalescing — any
    /// intermediate frames are dropped). Parses tree, diffs, posts to main thread.
    private static func shadowThreadLoop() {
        while shadowRunning {
            // Grab latest pending update (atomic swap to nil)
            os_unfair_lock_lock(&pendingLock)
            let update = pendingUpdate
            pendingUpdate = nil
            os_unfair_lock_unlock(&pendingLock)

            guard let update else {
                // No work — sleep until signaled
                pthread_mutex_lock(&shadowMutex)
                // Double-check after acquiring mutex to avoid missed wake
                os_unfair_lock_lock(&pendingLock)
                let hasWork = pendingUpdate != nil
                os_unfair_lock_unlock(&pendingLock)
                if !hasWork && shadowRunning {
                    pthread_cond_wait(&shadowCond, &shadowMutex)
                }
                pthread_mutex_unlock(&shadowMutex)
                continue
            }

            // Mark "tree update arrived from PHP" — this is the T1
            // checkpoint InteractionTracker uses to derive
            // event_delivery_ms. Recording before parse so the time
            // includes PHP processing + wire only, not native parse.
            InteractionTracker.shared.onTreeUpdateReceived()

            // Parse tree from flat buffer
            guard let tree = readTreeFromFlatBuffer(
                update.flatData, propData: update.propData,
                typeTable: update.typeTable, nodeCount: update.nodeCount
            ) else {
                continue
            }

            // Determine whether this is a continuation within native chrome
            // (same root sentinel type as the previous publish). Used both
            // to pick the right diff baseline below AND to suppress the
            // screen-level transition further down.
            let prevRootType = NativeUIBridge.shared.currentTree?.root.type
            let newRootType = tree.root.type
            let nativeChromeContinuation =
                (prevRootType == "native_root_stack" && newRootType == "native_root_stack") ||
                (prevRootType == "native_root_tabs"  && newRootType == "native_root_tabs")

            // A `native_root_stack` renderer is about to COLD-mount (the
            // previous publish was a different root sentinel — tabs,
            // WebView, or nothing). `NavigationCoordinator.shared` is a
            // singleton that survives renderer teardown, so at this point
            // it still holds the PREVIOUS stack session's `rootUri` and
            // per-URI tree cache. Without a reset, the new stack's first
            // publish falls through `receive()`'s seed/root checks into
            // the PUSH branch — stacking the new screen on top of a stale
            // root — and every subsequent pop animates through the OLD
            // page (e.g. a product screen visited minutes ago). Reset on
            // main below, atomically with the tree swap.
            let isFreshStackMount =
                newRootType == "native_root_stack" && prevRootType != "native_root_stack"

            // Diff against previous tree (reuse unchanged subtrees so
            // NodeView's `===` equality short-circuits and SwiftUI skips
            // re-rendering them).
            //   - For state changes (!isNav): diff against the immediate
            //     previous tree — same screen, almost everything matches.
            //   - Pop back to a cached URI within native chrome: diff
            //     against the LAST tree at that URI so unchanged subtrees
            //     reuse refs across the pop animation.
            //   - Tab switch / push within native chrome: diff against the
            //     immediate previous tree — the chrome's tab bar /
            //     toolbar config is identical across tabs declared by the
            //     same layout, and matching subtrees keep their refs so
            //     SwiftUI doesn't rebuild the toolbar / tab bar from
            //     scratch on each publish (which manifests as toolbar
            //     items briefly losing their tinted Liquid Glass treatment).
            //   - Otherwise (cross-layout nav, first publish): no diff.
            let finalTree: NativeUITree
            let newUri = tree.root.props.getString("current_uri", default: "")
            if !update.isNav, let prev = previousTree {
                let diffedRoot = diffNode(old: prev.root, new: tree.root)
                finalTree = NativeUITree(version: tree.version, callbackCount: tree.callbackCount, root: diffedRoot)
            } else if update.isNav, nativeChromeContinuation, !newUri.isEmpty,
                      let prevAtUri = nativeChromePrevTrees[newUri] {
                let diffedRoot = diffNode(old: prevAtUri.root, new: tree.root)
                finalTree = NativeUITree(version: tree.version, callbackCount: tree.callbackCount, root: diffedRoot)
            } else if update.isNav, nativeChromeContinuation, let prev = previousTree {
                let diffedRoot = diffNode(old: prev.root, new: tree.root)
                finalTree = NativeUITree(version: tree.version, callbackCount: tree.callbackCount, root: diffedRoot)
            } else {
                finalTree = tree
            }
            previousTree = finalTree

            // Track the most recent tree per native chrome URI so future
            // nav publishes back to the same URI can diff against it.
            if newRootType == "native_root_stack" || newRootType == "native_root_tabs" {
                let uri = finalTree.root.props.getString("current_uri", default: "")
                if !uri.isEmpty {
                    nativeChromePrevTrees[uri] = finalTree
                }
            }

            let isNav = update.isNav
            DispatchQueue.main.async {
                // T2 checkpoint — tree is now on the main thread about
                // to drive SwiftUI. The next CADisplayLink tick is T3.
                InteractionTracker.shared.onTreePostedToMain()
                let bridge = NativeUIBridge.shared

                // In a Jump WebView session the shell is showing the forwarded
                // remote app in the WebView. The LOCAL native-ui runloop (e.g.
                // the Jump home screen still polling discovery) keeps publishing
                // frames — drop them here so a local publish can't flip isActive
                // back to true and yank the WebView off screen.
                if JumpWebViewSession.shared.isActive {
                    return
                }

                let wasActive = bridge.isActive
                bridge.isActive = true

                // Router-level swap between two native screens: two-layer
                // swap. The old screen is NOT removed — it's reclassified as
                // `outgoingScreen` so ContentView keeps it mounted (same
                // ForEach identity, so no removal transition fires and view
                // state survives) beneath the incoming screen, and drives
                // its exit with plain animated modifiers. The incoming
                // screen gets a fresh key and animates in via its insertion
                // transition — the ONE side of SwiftUI's transition system
                // that works reliably (removal transitions are captured at
                // insertion and ignore later updates; AnyTransition.modifier
                // removals don't interpolate — both verified on-device).
                // The outgoing entry is dropped after the transition window,
                // invisibly beneath the opaque new screen. Staging order is
                // guaranteed: `NativeUI.Transition.Set` runs via main.sync
                // before PHP publishes the tree.
                if isNav && !nativeChromeContinuation {
                    if wasActive, let oldTree = bridge.currentTree {
                        let outgoingKey = bridge.screenKey
                        bridge.outgoingScreen = NativeUIBridge.OutgoingScreen(
                            tree: oldTree,
                            key: outgoingKey,
                            transition: bridge.pendingTransition
                        )
                        // Drop the held screen once the transition is over
                        // (longest is parallax_push at ~0.7s). Key-guarded
                        // so a rapid follow-up navigation (which overwrites
                        // `outgoingScreen`) isn't clobbered by this earlier
                        // cleanup firing late.
                        DispatchQueue.main.asyncAfter(deadline: .now() + 1.0) {
                            if bridge.outgoingScreen?.key == outgoingKey {
                                bridge.outgoingScreen = nil
                            }
                        }
                    }
                    bridge.screenKey += 1
                }
                if isFreshStackMount { NavigationCoordinator.shared.reset() }
                bridge.currentTree = finalTree
                // First publish after a hot-reload dismisses the
                // "Reloading…" pill. Set by `HotReloadCoordinator.reload`
                // at the start of the reboot; cleared here when the
                // fresh tree from the rebooted PHP runtime lands.
                if bridge.isReloading { bridge.isReloading = false }
            }
        }
    }

    // MARK: - Event Sending

    /// Write event to PHP shared memory event ring buffer.
    /// Event format: [magic:u32][type:u8][callback_id:u32][node_id:u32][timestamp:u64][data_size:u16][data...]
    static func nativeElementWriteEvent(_ type: Int32, _ callbackId: Int32, _ nodeId: Int32, _ data: UnsafePointer<UInt8>?, _ dataSize: Int32) {
        // Hand the body bytes to the PHP extension, which owns the event mutex,
        // the event queue, and the header framing (nphp_element_post_event in
        // nphp_element.c). No more region poking by offset, no 4KB cap, no
        // hand-rolled framing.
        //
        // The return value (1 = queued, 0 = dropped) is discarded: a UI event
        // has nothing useful to do about a drop, and a dropped one is already
        // logged by the extension with the callback_id it would have woken.
        // A caller that DOES care — an async-task completion, say — should
        // check it rather than copy this.
        _ = nphp_element_post_event(type, callbackId, nodeId, data, UInt32(max(0, dataSize)))
    }

    static func sendPressEvent(_ callbackId: Int, nodeId: Int) {
        InteractionTracker.shared.onInteractionStart(callbackId: callbackId, type: "press")
        var buf = Data(count: 8)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: Float(0).bitPattern.littleEndian, toByteOffset: 0, as: UInt32.self)
            ptr.storeBytes(of: Float(0).bitPattern.littleEndian, toByteOffset: 4, as: UInt32.self)
        }
        writeEvent(type: EventType.press, callbackId: callbackId, nodeId: nodeId, data: buf)
    }

    static func sendLongPressEvent(_ callbackId: Int, nodeId: Int) {
        InteractionTracker.shared.onInteractionStart(callbackId: callbackId, type: "long_press")
        var buf = Data(count: 8)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: Float(0).bitPattern.littleEndian, toByteOffset: 0, as: UInt32.self)
            ptr.storeBytes(of: Float(0).bitPattern.littleEndian, toByteOffset: 4, as: UInt32.self)
        }
        writeEvent(type: EventType.longPress, callbackId: callbackId, nodeId: nodeId, data: buf)
    }

    static func sendTextChangeEvent(_ callbackId: Int, nodeId: Int, text: String) {
        InteractionTracker.shared.onInteractionStart(callbackId: callbackId, type: "text_change")
        let textBytes = Array(text.utf8)
        var buf = Data(count: 4 + textBytes.count)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: UInt32(textBytes.count).littleEndian, toByteOffset: 0, as: UInt32.self)
            if !textBytes.isEmpty {
                ptr.baseAddress!.advanced(by: 4).copyMemory(from: textBytes, byteCount: textBytes.count)
            }
        }
        writeEvent(type: EventType.textChange, callbackId: callbackId, nodeId: nodeId, data: buf)
    }

    static func sendToggleChangeEvent(_ callbackId: Int, nodeId: Int, value: Bool) {
        InteractionTracker.shared.onInteractionStart(callbackId: callbackId, type: "toggle_change")
        writeEvent(type: EventType.toggleChange, callbackId: callbackId, nodeId: nodeId, data: Data([value ? 1 : 0]))
    }

    static func sendSubmitEvent(_ callbackId: Int, nodeId: Int, text: String) {
        let textBytes = Array(text.utf8)
        var buf = Data(count: 4 + textBytes.count)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: UInt32(textBytes.count).littleEndian, toByteOffset: 0, as: UInt32.self)
            if !textBytes.isEmpty {
                ptr.baseAddress!.advanced(by: 4).copyMemory(from: textBytes, byteCount: textBytes.count)
            }
        }
        writeEvent(type: EventType.submit, callbackId: callbackId, nodeId: nodeId, data: buf)
    }

    static func sendSliderChangeEvent(_ callbackId: Int, nodeId: Int, value: Float) {
        var buf = Data(count: 4)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: value.bitPattern.littleEndian, toByteOffset: 0, as: UInt32.self)
        }
        writeEvent(type: EventType.sliderChange, callbackId: callbackId, nodeId: nodeId, data: buf)
    }

    static func sendCheckboxChangeEvent(_ callbackId: Int, nodeId: Int, value: Bool) {
        writeEvent(type: EventType.checkboxChange, callbackId: callbackId, nodeId: nodeId, data: Data([value ? 1 : 0]))
    }

    static func sendRadioChangeEvent(_ callbackId: Int, nodeId: Int, value: String) {
        let textBytes = Array(value.utf8)
        var buf = Data(count: 4 + textBytes.count)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: UInt32(textBytes.count).littleEndian, toByteOffset: 0, as: UInt32.self)
            if !textBytes.isEmpty {
                ptr.baseAddress!.advanced(by: 4).copyMemory(from: textBytes, byteCount: textBytes.count)
            }
        }
        writeEvent(type: EventType.radioChange, callbackId: callbackId, nodeId: nodeId, data: buf)
    }

    static func sendSelectChangeEvent(_ callbackId: Int, nodeId: Int, value: String) {
        let textBytes = Array(value.utf8)
        var buf = Data(count: 4 + textBytes.count)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: UInt32(textBytes.count).littleEndian, toByteOffset: 0, as: UInt32.self)
            if !textBytes.isEmpty {
                ptr.baseAddress!.advanced(by: 4).copyMemory(from: textBytes, byteCount: textBytes.count)
            }
        }
        writeEvent(type: EventType.selectChange, callbackId: callbackId, nodeId: nodeId, data: buf)
    }

    static func sendTabChangeEvent(_ callbackId: Int, nodeId: Int, index: Int) {
        var buf = Data(count: 2)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: UInt16(index).littleEndian, toByteOffset: 0, as: UInt16.self)
        }
        writeEvent(type: EventType.tabChange, callbackId: callbackId, nodeId: nodeId, data: buf)
    }

    static func sendSheetDismissEvent(_ callbackId: Int, nodeId: Int) {
        writeEvent(type: EventType.sheetDismiss, callbackId: callbackId, nodeId: nodeId, data: nil)
    }

    static func sendHotReloadEvent() {
        writeEvent(type: EventType.hotReload, callbackId: 0, nodeId: 0, data: nil)
    }

    /// Fire a system-back event into the PHP event queue. Equivalent to
    /// the device hardware back button on Android — PHP's NativeComponent
    /// runloop catches type 8 and calls onBackPressed() → back().
    static func sendSystemBackEvent() {
        writeEvent(type: EventType.systemBack, callbackId: 0, nodeId: 0, data: nil)
    }

    /// Inject a native event into the element event queue.
    /// Wakes up nativephp_element_wait_event() on the PHP side.
    /// Data format: two length-prefixed UTF-8 strings (event name, payload JSON).
    static func sendNativeEvent(eventName: String, payloadJson: String) {
        let nameBytes = Array(eventName.utf8)
        let payloadBytes = Array(payloadJson.utf8)
        var buf = Data(capacity: 4 + nameBytes.count + 4 + payloadBytes.count)
        var nameLen = UInt32(nameBytes.count).littleEndian
        buf.append(Data(bytes: &nameLen, count: 4))
        buf.append(contentsOf: nameBytes)
        var payloadLen = UInt32(payloadBytes.count).littleEndian
        buf.append(Data(bytes: &payloadLen, count: 4))
        buf.append(contentsOf: payloadBytes)
        writeEvent(type: EventType.native, callbackId: 0, nodeId: 0, data: buf)
    }

    private static func writeEvent(type: Int, callbackId: Int, nodeId: Int, data: Data?) {
        // nodeId carries an FNV-1a 32-bit hash widened to Int — when bit
        // 31 is set the value exceeds Int32.max and the checked
        // `Int32(nodeId)` initializer traps. Use `truncatingIfNeeded`
        // so the 32-bit wire pattern survives intact (C reads it as
        // int32_t and treats it as an opaque id either way).
        let t = Int32(truncatingIfNeeded: type)
        let c = Int32(truncatingIfNeeded: callbackId)
        let n = Int32(truncatingIfNeeded: nodeId)
        if let data, data.count > 0 {
            data.withUnsafeBytes { ptr in
                if let base = ptr.baseAddress {
                    nativeElementWriteEvent(
                        t, c, n,
                        base.assumingMemoryBound(to: UInt8.self),
                        Int32(truncatingIfNeeded: data.count)
                    )
                } else {
                    nativeElementWriteEvent(t, c, n, nil, 0)
                }
            }
        } else {
            nativeElementWriteEvent(t, c, n, nil, 0)
        }
    }

    // MARK: - Flat Buffer Tree Reader

    private static func readTreeFromFlatBuffer(_ flatData: Data, propData: Data?, typeTable: [String], nodeCount: Int) -> NativeUITree? {
        guard nodeCount > 0 else { return nil }

        let perNode = flatData.count / nodeCount
        guard perNode >= nodeSize else {
            print("NativeElementBridge: ERROR node size \(perNode) < \(nodeSize) — rebuild PHP binaries with \(nodeSize)-byte struct (flat=\(flatData.count) nodes=\(nodeCount))")
            return nil
        }
        let stride = nodeSize

        guard flatData.count >= nodeCount * stride else { return nil }

        // Phase 2 — flatten the previous tree into an id → node map so
        // REUSE markers in this frame splice prior subtrees in O(1).
        let prevIndex = buildIdNodeIndex(previousTree)

        var offset = 0
        guard let root = readNodeDFS(flatData, propData: propData, typeTable: typeTable, stride: stride, offset: &offset, prevIndex: prevIndex) else {
            return nil
        }
        return NativeUITree(version: 0, callbackCount: 0, root: root)
    }

    /// Phase 2 — DFS-flatten the previous tree into id → node so REUSE
    /// node parsing can splice prior subtrees by id in O(1).
    private static func buildIdNodeIndex(_ tree: NativeUITree?) -> [Int: NativeUINode] {
        guard let tree else { return [:] }
        var out: [Int: NativeUINode] = [:]
        out.reserveCapacity(64)
        func walk(_ n: NativeUINode) {
            out[n.id] = n
            for c in n.children { walk(c) }
        }
        walk(tree.root)
        return out
    }

    private static func readNodeDFS(_ flatData: Data, propData: Data?, typeTable: [String], stride: Int, offset: inout Int, prevIndex: [Int: NativeUINode]) -> NativeUINode? {
        guard offset + stride <= flatData.count else { return nil }

        // Phase 2 — peek the flag byte at offset 160 BEFORE advancing
        // past this node. If REUSE, splice the prior subtree and skip
        // the rest of the parse for this node.
        let nodeStart = offset
        let reuseFlag = flatData.withUnsafeBytes { buf -> UInt8 in
            buf.baseAddress!.advanced(by: nodeStart).load(fromByteOffset: nodeFlagsOffset, as: UInt8.self)
        }
        if reuseFlag & nodeFlagReuse != 0 {
            let reuseId = flatData.withUnsafeBytes { buf -> Int in
                Int(buf.baseAddress!.advanced(by: nodeStart).loadUnaligned(fromByteOffset: 0, as: UInt32.self).littleEndian)
            }
            offset += stride
            if let prior = prevIndex[reuseId] {
                return prior
            }
            // PHP shouldn't emit REUSE for an id we haven't seen — that's a
            // forceFullFrame oversight. Drop the subtree and let the empty
            // hole make the bug visible.
            print("NativeElementBridge: REUSE flag set for id=\(reuseId) but no prior node in index — skipping")
            return nil
        }

        return flatData.withUnsafeBytes { buf in
            let base = buf.baseAddress!.advanced(by: offset)
            offset += stride

            let id = Int(base.loadUnaligned(fromByteOffset: 0, as: UInt32.self).littleEndian)
            let typeIdx = Int(base.loadUnaligned(fromByteOffset: 4, as: UInt16.self).littleEndian)
            let childCount = Int(base.loadUnaligned(fromByteOffset: 6, as: UInt16.self).littleEndian)
            // first_child_offset at 8 (unused — we read DFS)
            let onPress = Int(base.loadUnaligned(fromByteOffset: 12, as: UInt32.self).littleEndian)
            let onLongPress = Int(base.loadUnaligned(fromByteOffset: 16, as: UInt32.self).littleEndian)

            let width = Float(bitPattern: base.loadUnaligned(fromByteOffset: 20, as: UInt32.self).littleEndian)
            let widthMode = Int(base.load(fromByteOffset: 24, as: UInt8.self))
            let height = Float(bitPattern: base.loadUnaligned(fromByteOffset: 25, as: UInt32.self).littleEndian)
            let heightMode = Int(base.load(fromByteOffset: 29, as: UInt8.self))

            let paddingTop = Float(bitPattern: base.loadUnaligned(fromByteOffset: 30, as: UInt32.self).littleEndian)
            let paddingRight = Float(bitPattern: base.loadUnaligned(fromByteOffset: 34, as: UInt32.self).littleEndian)
            let paddingBottom = Float(bitPattern: base.loadUnaligned(fromByteOffset: 38, as: UInt32.self).littleEndian)
            let paddingLeft = Float(bitPattern: base.loadUnaligned(fromByteOffset: 42, as: UInt32.self).littleEndian)

            let marginTop = Float(bitPattern: base.loadUnaligned(fromByteOffset: 46, as: UInt32.self).littleEndian)
            let marginRight = Float(bitPattern: base.loadUnaligned(fromByteOffset: 50, as: UInt32.self).littleEndian)
            let marginBottom = Float(bitPattern: base.loadUnaligned(fromByteOffset: 54, as: UInt32.self).littleEndian)
            let marginLeft = Float(bitPattern: base.loadUnaligned(fromByteOffset: 58, as: UInt32.self).littleEndian)

            let flexGrow = Float(bitPattern: base.loadUnaligned(fromByteOffset: 62, as: UInt32.self).littleEndian)
            let flexShrink = Float(bitPattern: base.loadUnaligned(fromByteOffset: 66, as: UInt32.self).littleEndian)
            let alignSelf = Int(base.load(fromByteOffset: 70, as: UInt8.self))
            let alignItems = Int(base.load(fromByteOffset: 71, as: UInt8.self))
            let justifyContent = Int(base.load(fromByteOffset: 72, as: UInt8.self))
            let gap = Float(bitPattern: base.loadUnaligned(fromByteOffset: 73, as: UInt32.self).littleEndian)
            let safeArea = Int(base.load(fromByteOffset: 77, as: UInt8.self))

            // Extended layout fields (flexbox) — only present in 160-byte nodes
            let minWidth: Float, minHeight: Float, maxWidth: Float, maxHeight: Float
            let flexBasis: Float, flexBasisMode: Int, flexWrap: Int, flexDirection: Int
            let positionType: Int
            let positionTop: Float, positionRight: Float, positionBottom: Float, positionLeft: Float
            let display: Int, overflow: Int, alignContent: Int, direction: Int
            let aspectRatio: Float, rowGap: Float

            // Style and props offsets differ based on stride
            let bgColor: Int, borderRadius: Float, borderWidth: Float
            let borderColor: Int, opacity: Float, elevation: Float
            let propOffset: Int, propSize: Int

            // 160-byte node: extended flex fields at 78..129, style at 130, props at 154
            minWidth = Float(bitPattern: base.loadUnaligned(fromByteOffset: 78, as: UInt32.self).littleEndian)
            minHeight = Float(bitPattern: base.loadUnaligned(fromByteOffset: 82, as: UInt32.self).littleEndian)
            maxWidth = Float(bitPattern: base.loadUnaligned(fromByteOffset: 86, as: UInt32.self).littleEndian)
            maxHeight = Float(bitPattern: base.loadUnaligned(fromByteOffset: 90, as: UInt32.self).littleEndian)
            flexBasis = Float(bitPattern: base.loadUnaligned(fromByteOffset: 94, as: UInt32.self).littleEndian)
            flexBasisMode = Int(base.load(fromByteOffset: 98, as: UInt8.self))
            flexWrap = Int(base.load(fromByteOffset: 99, as: UInt8.self))
            flexDirection = Int(base.load(fromByteOffset: 100, as: UInt8.self))
            positionType = Int(base.load(fromByteOffset: 101, as: UInt8.self))
            positionTop = Float(bitPattern: base.loadUnaligned(fromByteOffset: 102, as: UInt32.self).littleEndian)
            positionRight = Float(bitPattern: base.loadUnaligned(fromByteOffset: 106, as: UInt32.self).littleEndian)
            positionBottom = Float(bitPattern: base.loadUnaligned(fromByteOffset: 110, as: UInt32.self).littleEndian)
            positionLeft = Float(bitPattern: base.loadUnaligned(fromByteOffset: 114, as: UInt32.self).littleEndian)
            display = Int(base.load(fromByteOffset: 118, as: UInt8.self))
            overflow = Int(base.load(fromByteOffset: 119, as: UInt8.self))
            alignContent = Int(base.load(fromByteOffset: 120, as: UInt8.self))
            direction = Int(base.load(fromByteOffset: 121, as: UInt8.self))
            aspectRatio = Float(bitPattern: base.loadUnaligned(fromByteOffset: 122, as: UInt32.self).littleEndian)
            rowGap = Float(bitPattern: base.loadUnaligned(fromByteOffset: 126, as: UInt32.self).littleEndian)

            bgColor = Int(Int32(bitPattern: base.loadUnaligned(fromByteOffset: 130, as: UInt32.self).littleEndian))
            borderRadius = Float(bitPattern: base.loadUnaligned(fromByteOffset: 134, as: UInt32.self).littleEndian)
            borderWidth = Float(bitPattern: base.loadUnaligned(fromByteOffset: 138, as: UInt32.self).littleEndian)
            borderColor = Int(Int32(bitPattern: base.loadUnaligned(fromByteOffset: 142, as: UInt32.self).littleEndian))
            opacity = Float(bitPattern: base.loadUnaligned(fromByteOffset: 146, as: UInt32.self).littleEndian)
            elevation = Float(bitPattern: base.loadUnaligned(fromByteOffset: 150, as: UInt32.self).littleEndian)
            propOffset = Int(base.loadUnaligned(fromByteOffset: 154, as: UInt32.self).littleEndian)
            propSize = Int(base.loadUnaligned(fromByteOffset: 158, as: UInt16.self).littleEndian)

            let type = typeIdx < typeTable.count ? typeTable[typeIdx] : "column"

            let layout = NodeLayout(
                width: width, widthMode: widthMode, height: height, heightMode: heightMode,
                paddingTop: paddingTop, paddingRight: paddingRight, paddingBottom: paddingBottom, paddingLeft: paddingLeft,
                marginTop: marginTop, marginRight: marginRight, marginBottom: marginBottom, marginLeft: marginLeft,
                flexGrow: flexGrow, flexShrink: flexShrink,
                alignSelf: alignSelf, alignItems: alignItems, justifyContent: justifyContent, gap: gap, safeArea: safeArea,
                minWidth: minWidth, minHeight: minHeight, maxWidth: maxWidth, maxHeight: maxHeight,
                flexBasis: flexBasis, flexBasisMode: flexBasisMode, flexWrap: flexWrap,
                flexDirection: flexDirection, positionType: positionType,
                positionTop: positionTop, positionRight: positionRight,
                positionBottom: positionBottom, positionLeft: positionLeft,
                display: display, overflow: overflow, alignContent: alignContent, direction: direction,
                aspectRatio: aspectRatio, rowGap: rowGap
            )

            let style = NodeStyle(
                bgColor: bgColor, borderRadius: borderRadius, borderWidth: borderWidth,
                borderColor: borderColor, opacity: opacity, elevation: elevation
            )

            let props: GenericProps
            if propSize > 0, let propData {
                props = readPropsFromBuffer(propData, offset: propOffset, size: propSize)
            } else {
                props = GenericProps()
            }

            var children: [NativeUINode] = []
            children.reserveCapacity(childCount)
            for _ in 0..<childCount {
                guard let child = readNodeDFS(flatData, propData: propData, typeTable: typeTable, stride: stride, offset: &offset, prevIndex: prevIndex) else { break }
                children.append(child)
            }

            return NativeUINode(
                id: id, type: type, layout: layout, style: style,
                props: props, onPress: onPress, onLongPress: onLongPress, children: children
            )
        }
    }

    // MARK: - Props Reader

    private static func readPropsFromBuffer(_ data: Data, offset: Int, size: Int) -> GenericProps {
        guard size > 0, offset + size <= data.count else { return GenericProps() }

        return data.withUnsafeBytes { buf in
            let base = buf.baseAddress!
            var pos = offset

            let propCount = Int(base.load(fromByteOffset: pos, as: UInt8.self))
            pos += 1
            guard propCount > 0 else { return GenericProps() }

            var map: [String: Any] = [:]
            map.reserveCapacity(propCount)

            for _ in 0..<propCount {
                guard pos + 2 <= offset + size else { break }

                let keyIndex = Int(base.load(fromByteOffset: pos, as: UInt8.self))
                pos += 1

                let key: String
                if keyIndex != PropKey.fallback && keyIndex < PropKey.table.count {
                    key = PropKey.table[keyIndex]
                } else {
                    let (s, newPos) = readString(base, pos: pos, limit: offset + size)
                    key = s
                    pos = newPos
                }

                let typeTag = Int(base.load(fromByteOffset: pos, as: UInt8.self))
                pos += 1

                switch typeTag {
                case ValType.u8:
                    guard pos + 1 <= offset + size else { break }
                    map[key] = Int(base.load(fromByteOffset: pos, as: UInt8.self))
                    pos += 1
                case ValType.u16:
                    guard pos + 2 <= offset + size else { break }
                    map[key] = Int(base.loadUnaligned(fromByteOffset: pos, as: UInt16.self).littleEndian)
                    pos += 2
                case ValType.u32, ValType.i32, ValType.color, ValType.callback:
                    guard pos + 4 <= offset + size else { break }
                    map[key] = Int(Int32(bitPattern: base.loadUnaligned(fromByteOffset: pos, as: UInt32.self).littleEndian))
                    pos += 4
                case ValType.f32:
                    guard pos + 4 <= offset + size else { break }
                    map[key] = Float(bitPattern: base.loadUnaligned(fromByteOffset: pos, as: UInt32.self).littleEndian)
                    pos += 4
                case ValType.bool_:
                    guard pos + 1 <= offset + size else { break }
                    map[key] = base.load(fromByteOffset: pos, as: UInt8.self) != 0
                    pos += 1
                case ValType.string:
                    let (s, newPos) = readString(base, pos: pos, limit: offset + size)
                    map[key] = s
                    pos = newPos
                case ValType.stringArray:
                    guard pos + 2 <= offset + size else { break }
                    let count = Int(base.loadUnaligned(fromByteOffset: pos, as: UInt16.self).littleEndian)
                    pos += 2
                    var list: [String] = []
                    list.reserveCapacity(count)
                    for _ in 0..<count {
                        let (s, newPos) = readString(base, pos: pos, limit: offset + size)
                        list.append(s)
                        pos = newPos
                    }
                    map[key] = list
                default:
                    // Skip unknown type
                    guard pos + 1 <= offset + size else { break }
                    pos += 1
                }
            }

            return GenericProps(map)
        }
    }

    private static func readString(_ base: UnsafeRawPointer, pos: Int, limit: Int) -> (String, Int) {
        guard pos + 2 <= limit else { return ("", pos) }
        let len = Int(base.loadUnaligned(fromByteOffset: pos, as: UInt16.self).littleEndian)
        let newPos = pos + 2 + len
        guard len > 0, newPos <= limit else { return ("", pos + 2) }
        let bytes = Data(bytes: base.advanced(by: pos + 2), count: len)
        return (String(data: bytes, encoding: .utf8) ?? "", newPos)
    }

    // MARK: - Tree Diff

    private static func diffNode(old: NativeUINode, new: NativeUINode) -> NativeUINode {
        guard old.id == new.id, old.type == new.type, old.children.count == new.children.count else {
            return new
        }

        var allChildrenReused = true
        let diffedChildren: [NativeUINode]
        if new.children.isEmpty {
            diffedChildren = new.children
        } else {
            var list: [NativeUINode] = []
            list.reserveCapacity(new.children.count)
            for i in new.children.indices {
                let diffed = diffNode(old: old.children[i], new: new.children[i])
                if diffed !== old.children[i] { allChildrenReused = false }
                list.append(diffed)
            }
            diffedChildren = list
        }

        let fieldsMatch = old.layout == new.layout &&
            old.style == new.style &&
            old.onPress == new.onPress &&
            old.onLongPress == new.onLongPress &&
            old.props == new.props

        if fieldsMatch && allChildrenReused {
            return old
        }

        if fieldsMatch {
            return old.copy(children: diffedChildren)
        }

        return new.copy(children: diffedChildren)
    }

    // MARK: - Lifecycle

    static func startWatching() {
        // Stop any existing shadow thread before resetting state
        stopShadowThread()
        previousTree = nil
        nativeChromePrevTrees.removeAll()
        cachedTypeTable = nil
        os_unfair_lock_lock(&pendingLock)
        pendingUpdate = nil
        os_unfair_lock_unlock(&pendingLock)
        // Format-version check lives in postTreeUpdateFromRegion() — it covers
        // every publish path, not just startWatching().
    }

    /// Stop the shadow thread and clear all bridge-internal caches. The
    /// `preserveTree` flag controls whether the visible UI state is also
    /// cleared:
    ///   - `false` (default): clear `NativeUIBridge.isActive` and
    ///     `currentTree` too — SwiftUI swaps to the WebView branch
    ///     immediately. Right for "leaving native UI entirely".
    ///   - `true`: keep the last published tree visible. Used by the
    ///     hot-reload path so the user keeps seeing their screen during
    ///     the ~500ms PHP reboot, instead of a brief flash of the
    ///     WebView root. The next `registerRegion` + publish replaces
    ///     the (stale) tree atomically.
    static func stopWatching(preserveTree: Bool = false) {
        stopShadowThread()
        previousTree = nil
        nativeChromePrevTrees.removeAll()
        cachedTypeTable = nil
        os_unfair_lock_lock(&pendingLock)
        pendingUpdate = nil
        os_unfair_lock_unlock(&pendingLock)

        // Phase 0 — re-verify wire format on next region registration.
        // A hot-reload cycle can swap libphp.a out from under us.
        versionChecked = false
        versionMismatch = false

        if !preserveTree {
            DispatchQueue.main.async {
                NativeUIBridge.shared.isActive = false
                NativeUIBridge.shared.currentTree = nil
            }
        }
    }

    private static func stopShadowThread() {
        guard shadowRunning else { return }
        shadowRunning = false
        // Wake the thread so it can exit its loop
        pthread_mutex_lock(&shadowMutex)
        pthread_cond_signal(&shadowCond)
        pthread_mutex_unlock(&shadowMutex)
        shadowThread = nil
    }
}
