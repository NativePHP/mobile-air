import Foundation
import Darwin

/// Reads current process memory via `mach_task_basic_info`. The
/// resident-set-size (`resident_size`) is what shows up in Xcode
/// Instruments' "Resident" memory column. `Perf.Memory` reports this
/// number plus a delta from a baseline captured the first time `Perf`
/// is enabled.
enum MemoryProbe {
    /// Captured at first call after `Perf.Enable`. Used as the
    /// baseline for `delta_bytes`. -1 = not yet set.
    static var baselineBytes: Int64 = -1

    /// Returns current resident bytes, or 0 on failure. Wraps the
    /// `task_info(mach_task_self(), MACH_TASK_BASIC_INFO, ...)`
    /// system call. Safe to call from any thread.
    static func currentResidentBytes() -> Int64 {
        var info = mach_task_basic_info()
        var count = mach_msg_type_number_t(MemoryLayout<mach_task_basic_info>.size / MemoryLayout<integer_t>.size)
        let result = withUnsafeMutablePointer(to: &info) {
            $0.withMemoryRebound(to: integer_t.self, capacity: Int(count)) {
                task_info(mach_task_self_, task_flavor_t(MACH_TASK_BASIC_INFO), $0, &count)
            }
        }
        guard result == KERN_SUCCESS else { return 0 }
        return Int64(info.resident_size)
    }

    /// Sets the baseline if not already set. Returns the baseline.
    static func ensureBaseline() -> Int64 {
        if baselineBytes < 0 {
            baselineBytes = currentResidentBytes()
        }
        return baselineBytes
    }

    static func resetBaseline() {
        baselineBytes = -1
    }
}
