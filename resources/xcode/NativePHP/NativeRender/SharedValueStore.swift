import SwiftUI

/// Per-process registry of numeric values that live on the UI thread
/// and can be driven by gestures, animations, or other UI-thread
/// sources. PHP never participates per-frame — it only seeds initial
/// values and consumes discrete callbacks (`@drag-end` etc).
///
/// Each value is keyed by an integer ID assigned by the PHP
/// `SharedValue` class. The wire format encodes references as
/// `__sv:{id}` strings (optionally with a formula chain). Readers
/// parse the string, look up the base value, and apply the formula
/// to derive the per-frame value.
final class SharedValueStore: ObservableObject {
    static let shared = SharedValueStore()

    /// Backing storage. `@Published` so SwiftUI views observing the
    /// store re-render on update. Per-frame writes during a drag
    /// will re-render every subscribed view at the OS framerate.
    @Published private(set) var values: [Int: CGFloat] = [:]

    private init() {}

    func value(for id: Int) -> CGFloat {
        values[id] ?? 0
    }

    func set(_ value: CGFloat, for id: Int) {
        values[id] = value
    }

    /// Seed an initial value without firing `objectWillChange` if the
    /// value already matches (avoids spurious re-renders when the
    /// renderer mounts after a tree publish).
    func seed(_ value: CGFloat, for id: Int) {
        if values[id] != value {
            values[id] = value
        }
    }

    /// Parse a wire-encoded shared-value reference and evaluate it
    /// against the current store. Returns `nil` if the string is not
    /// a `__sv:` reference.
    ///
    /// `initial` is the base value used when the store has no entry
    /// for the id yet — callers pass the literal snapshot PHP wrote
    /// alongside the binding, so a wire-fresh id (e.g. right after a
    /// re-render minted a new SharedValue) renders at its initial
    /// value instead of collapsing to 0 until a gesture seeds it.
    ///
    /// Examples:
    ///   `__sv:42`                              → values[42]
    ///   `__sv:42|interp:0,200:1,0`             → linear-map values[42] from [0,200] to [1,0]
    ///   `__sv:42|interp:0,200:1,0|clamp:0,1`   → chained
    func evaluate(_ ref: String, initial: CGFloat = 0) -> CGFloat? {
        guard ref.hasPrefix("__sv:") else { return nil }
        let parts = ref.split(separator: "|").map { String($0) }
        guard let first = parts.first else { return nil }

        let idStr = String(first.dropFirst(5))  // strip "__sv:"
        guard let id = Int(idStr) else { return nil }
        var v = values[id] ?? initial

        for step in parts.dropFirst() {
            let segments = step.split(separator: ":").map { String($0) }
            guard let op = segments.first else { continue }
            let args = Array(segments.dropFirst())

            switch op {
            case "interp":
                guard args.count == 2 else { continue }
                let input = args[0].split(separator: ",").compactMap { Double($0) }
                let output = args[1].split(separator: ",").compactMap { Double($0) }
                guard input.count == 2, output.count == 2 else { continue }
                v = CGFloat(interp(Double(v), input: input, output: output))
            case "clamp":
                guard args.count == 1 else { continue }
                let bounds = args[0].split(separator: ",").compactMap { Double($0) }
                guard bounds.count == 2 else { continue }
                v = max(CGFloat(bounds[0]), min(CGFloat(bounds[1]), v))
            case "mul":
                guard args.count == 1, let by = Double(args[0]) else { continue }
                v = v * CGFloat(by)
            case "add":
                guard args.count == 1, let by = Double(args[0]) else { continue }
                v = v + CGFloat(by)
            default:
                break
            }
        }

        return v
    }

    private func interp(_ v: Double, input: [Double], output: [Double]) -> Double {
        let (inLow, inHigh) = (input[0], input[1])
        let (outLow, outHigh) = (output[0], output[1])
        if v <= inLow { return outLow }
        if v >= inHigh { return outHigh }
        let t = (v - inLow) / (inHigh - inLow)

        return outLow + t * (outHigh - outLow)
    }
}
