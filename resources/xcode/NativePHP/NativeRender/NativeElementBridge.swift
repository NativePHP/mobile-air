
import Foundation
import UIKit // needed for UIScreen.main, UIApplication
import os

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
///  --- Extended layout (Yoga) ---
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
    /// Node stride: 160 bytes (Yoga-aware). Legacy 108-byte nodes are not supported.
    private static let nodeSize = 160

    // MARK: - Region struct offsets (arm64 Darwin, computed via offsetof)
    // Must match nphp_element_region_t in nphp_element.h

    private enum RegionOffset {
        static let magic: Int            = 0
        static let nodeCount: Int        = 16
        static let flatBufferSize: Int   = 20
        static let propBufferSize: Int   = 24
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

    /// Shared memory region pointer registered by PHP's nphp_element_init()
    private static var regionPtr: UnsafeMutableRawPointer?

    /// Type table from last update
    private static var cachedTypeTable: [String]?

    /// Previous tree for incremental diff
    private static var previousTree: NativeUITree?

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

        let raw = UnsafeRawPointer(ptr)

        let magic = raw.load(fromByteOffset: RegionOffset.magic, as: UInt32.self)
        guard magic == elementMagic else {
            print("NativeElementBridge: bad magic 0x\(String(magic, radix: 16))")
            return
        }

        let nodeCount = Int(raw.load(fromByteOffset: RegionOffset.nodeCount, as: UInt32.self))
        let flatSize  = Int(raw.load(fromByteOffset: RegionOffset.flatBufferSize, as: UInt32.self))
        let propSize  = Int(raw.load(fromByteOffset: RegionOffset.propBufferSize, as: UInt32.self))

        // flat_buffer and prop_buffer are heap-allocated pointers stored in the region
        let flatBufPtr = raw.load(fromByteOffset: RegionOffset.flatBuffer, as: UnsafePointer<UInt8>?.self)
        let propBufPtr = raw.load(fromByteOffset: RegionOffset.propBuffer, as: UnsafePointer<UInt8>?.self)

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
        // Copy buffers off PHP thread (heap copy required for thread safety)
        let flatData = Data(bytes: flatPtr, count: flatSize)
        let propData: Data? = if let propPtr, propSize > 0 {
            Data(bytes: propPtr, count: propSize)
        } else {
            nil
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

            // Parse tree from flat buffer
            guard let tree = readTreeFromFlatBuffer(
                update.flatData, propData: update.propData,
                typeTable: update.typeTable, nodeCount: update.nodeCount
            ) else {
                continue
            }

            // Diff against previous tree (reuse unchanged subtrees for SwiftUI Equatable optimization)
            let finalTree: NativeUITree
            if let prev = previousTree, !update.isNav {
                let diffedRoot = diffNode(old: prev.root, new: tree.root)
                finalTree = NativeUITree(version: tree.version, callbackCount: tree.callbackCount, root: diffedRoot)
            } else {
                finalTree = tree
            }
            previousTree = finalTree

            let isNav = update.isNav
            DispatchQueue.main.async {
                let bridge = NativeUIBridge.shared
                bridge.isActive = true
                if isNav { bridge.screenKey += 1 }
                bridge.currentTree = finalTree
            }
        }
    }

    // MARK: - Event Sending

    /// Write event to PHP shared memory event ring buffer.
    /// Event format: [magic:u32][type:u8][callback_id:u32][node_id:u32][timestamp:u64][data_size:u16][data...]
    static func nativeElementWriteEvent(_ type: Int32, _ callbackId: Int32, _ nodeId: Int32, _ data: UnsafePointer<UInt8>?, _ dataSize: Int32) {
        guard let ptr = regionPtr else { return }

        // Event header: magic(4) + type(1) + callback_id(4) + node_id(4) + timestamp(8) + data_size(2) = 23 bytes
        let headerSize = 23
        let totalSize = headerSize + Int(dataSize)

        // Get pointers to the mutex/cond via raw offsets
        let mutexPtr = ptr.advanced(by: RegionOffset.eventMutex)
            .assumingMemoryBound(to: pthread_mutex_t.self)
        let condPtr = ptr.advanced(by: RegionOffset.eventCond)
            .assumingMemoryBound(to: pthread_cond_t.self)
        let eventSizePtr = ptr.advanced(by: RegionOffset.eventSize)
            .assumingMemoryBound(to: UInt32.self)
        let eventCountPtr = ptr.advanced(by: RegionOffset.eventCount)
            .assumingMemoryBound(to: UInt32.self)

        pthread_mutex_lock(mutexPtr)
        defer {
            pthread_cond_signal(condPtr)
            pthread_mutex_unlock(mutexPtr)
        }

        let currentSize = Int(eventSizePtr.pointee)
        guard currentSize + totalSize <= 4096 else {
            print("NativeElementBridge: event buffer full (\(currentSize)/4096)")
            return
        }

        let evBufBase = ptr.advanced(by: RegionOffset.eventBuffer)
        let base = evBufBase.advanced(by: currentSize)

        // Magic (4 bytes)
        base.storeBytes(of: eventMagic.littleEndian, as: UInt32.self)
        // Type (1 byte)
        base.storeBytes(of: UInt8(type), toByteOffset: 4, as: UInt8.self)
        // Callback ID (4 bytes)
        base.storeBytes(of: UInt32(bitPattern: callbackId).littleEndian, toByteOffset: 5, as: UInt32.self)
        // Node ID (4 bytes)
        base.storeBytes(of: UInt32(bitPattern: nodeId).littleEndian, toByteOffset: 9, as: UInt32.self)
        // Timestamp (8 bytes)
        var tv = timeval()
        gettimeofday(&tv, nil)
        let timestampMs = UInt64(tv.tv_sec) * 1000 + UInt64(tv.tv_usec) / 1000
        base.storeBytes(of: timestampMs.littleEndian, toByteOffset: 13, as: UInt64.self)
        // Data size (2 bytes)
        base.storeBytes(of: UInt16(dataSize).littleEndian, toByteOffset: 21, as: UInt16.self)
        // Data payload
        if let data, dataSize > 0 {
            base.advanced(by: headerSize).copyMemory(from: data, byteCount: Int(dataSize))
        }

        eventSizePtr.pointee = UInt32(currentSize + totalSize)
        eventCountPtr.pointee += 1
    }

    static func sendPressEvent(_ callbackId: Int, nodeId: Int) {
        var buf = Data(count: 8)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: Float(0).bitPattern.littleEndian, toByteOffset: 0, as: UInt32.self)
            ptr.storeBytes(of: Float(0).bitPattern.littleEndian, toByteOffset: 4, as: UInt32.self)
        }
        writeEvent(type: EventType.press, callbackId: callbackId, nodeId: nodeId, data: buf)
    }

    static func sendLongPressEvent(_ callbackId: Int, nodeId: Int) {
        var buf = Data(count: 8)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: Float(0).bitPattern.littleEndian, toByteOffset: 0, as: UInt32.self)
            ptr.storeBytes(of: Float(0).bitPattern.littleEndian, toByteOffset: 4, as: UInt32.self)
        }
        writeEvent(type: EventType.longPress, callbackId: callbackId, nodeId: nodeId, data: buf)
    }

    static func sendTextChangeEvent(_ callbackId: Int, nodeId: Int, text: String) {
        let textBytes = Array(text.utf8)
        var buf = Data(count: 2 + textBytes.count)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: UInt16(textBytes.count).littleEndian, toByteOffset: 0, as: UInt16.self)
            if !textBytes.isEmpty {
                ptr.baseAddress!.advanced(by: 2).copyMemory(from: textBytes, byteCount: textBytes.count)
            }
        }
        writeEvent(type: EventType.textChange, callbackId: callbackId, nodeId: nodeId, data: buf)
    }

    static func sendToggleChangeEvent(_ callbackId: Int, nodeId: Int, value: Bool) {
        writeEvent(type: EventType.toggleChange, callbackId: callbackId, nodeId: nodeId, data: Data([value ? 1 : 0]))
    }

    static func sendSubmitEvent(_ callbackId: Int, nodeId: Int, text: String) {
        let textBytes = Array(text.utf8)
        var buf = Data(count: 2 + textBytes.count)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: UInt16(textBytes.count).littleEndian, toByteOffset: 0, as: UInt16.self)
            if !textBytes.isEmpty {
                ptr.baseAddress!.advanced(by: 2).copyMemory(from: textBytes, byteCount: textBytes.count)
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
        var buf = Data(count: 2 + textBytes.count)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: UInt16(textBytes.count).littleEndian, toByteOffset: 0, as: UInt16.self)
            if !textBytes.isEmpty {
                ptr.baseAddress!.advanced(by: 2).copyMemory(from: textBytes, byteCount: textBytes.count)
            }
        }
        writeEvent(type: EventType.radioChange, callbackId: callbackId, nodeId: nodeId, data: buf)
    }

    static func sendSelectChangeEvent(_ callbackId: Int, nodeId: Int, value: String) {
        let textBytes = Array(value.utf8)
        var buf = Data(count: 2 + textBytes.count)
        buf.withUnsafeMutableBytes { ptr in
            ptr.storeBytes(of: UInt16(textBytes.count).littleEndian, toByteOffset: 0, as: UInt16.self)
            if !textBytes.isEmpty {
                ptr.baseAddress!.advanced(by: 2).copyMemory(from: textBytes, byteCount: textBytes.count)
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

    /// Inject a native event into the element event queue.
    /// Wakes up nativephp_element_wait_event() on the PHP side.
    /// Data format: two length-prefixed UTF-8 strings (event name, payload JSON).
    static func sendNativeEvent(eventName: String, payloadJson: String) {
        let nameBytes = Array(eventName.utf8)
        let payloadBytes = Array(payloadJson.utf8)
        var buf = Data(capacity: 2 + nameBytes.count + 2 + payloadBytes.count)
        var nameLen = UInt16(nameBytes.count).littleEndian
        buf.append(Data(bytes: &nameLen, count: 2))
        buf.append(contentsOf: nameBytes)
        var payloadLen = UInt16(payloadBytes.count).littleEndian
        buf.append(Data(bytes: &payloadLen, count: 2))
        buf.append(contentsOf: payloadBytes)
        writeEvent(type: EventType.native, callbackId: 0, nodeId: 0, data: buf)
    }

    private static func writeEvent(type: Int, callbackId: Int, nodeId: Int, data: Data?) {
        if let data {
            data.withUnsafeBytes { ptr in
                nativeElementWriteEvent(Int32(type), Int32(callbackId), Int32(nodeId), ptr.baseAddress!.assumingMemoryBound(to: UInt8.self), Int32(data.count))
            }
        } else {
            nativeElementWriteEvent(Int32(type), Int32(callbackId), Int32(nodeId), nil, 0)
        }
    }

    // MARK: - Flat Buffer Tree Reader

    private static func readTreeFromFlatBuffer(_ flatData: Data, propData: Data?, typeTable: [String], nodeCount: Int) -> NativeUITree? {
        guard nodeCount > 0 else { return nil }

        let perNode = flatData.count / nodeCount
        guard perNode >= nodeSize else {
            print("NativeElementBridge: ERROR node size \(perNode) < \(nodeSize) — rebuild PHP binaries with 160-byte struct (flat=\(flatData.count) nodes=\(nodeCount))")
            return nil
        }
        let stride = nodeSize

        guard flatData.count >= nodeCount * stride else { return nil }

        var offset = 0
        guard let root = readNodeDFS(flatData, propData: propData, typeTable: typeTable, stride: stride, offset: &offset) else {
            return nil
        }
        return NativeUITree(version: 0, callbackCount: 0, root: root)
    }

    private static func readNodeDFS(_ flatData: Data, propData: Data?, typeTable: [String], stride: Int, offset: inout Int) -> NativeUINode? {
        guard offset + stride <= flatData.count else { return nil }

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

            // Extended layout fields (Yoga) — only present in 160-byte nodes
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

            // 160-byte node: extended Yoga fields at 78..129, style at 130, props at 154
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
                guard let child = readNodeDFS(flatData, propData: propData, typeTable: typeTable, stride: stride, offset: &offset) else { break }
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
        cachedTypeTable = nil
        os_unfair_lock_lock(&pendingLock)
        pendingUpdate = nil
        os_unfair_lock_unlock(&pendingLock)
    }

    static func stopWatching() {
        stopShadowThread()
        previousTree = nil
        cachedTypeTable = nil
        os_unfair_lock_lock(&pendingLock)
        pendingUpdate = nil
        os_unfair_lock_unlock(&pendingLock)
        DispatchQueue.main.async {
            NativeUIBridge.shared.isActive = false
            NativeUIBridge.shared.currentTree = nil
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
