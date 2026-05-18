import Foundation

/// iOS-side `Perf.*` bridge functions. Mirrors the Android registration
/// in `androidstudio/.../bridge/functions/PerfFunctions.kt`. The JSON
/// shape returned by `Perf.Export` matches Android's
/// `PerformanceTracker.exportJson()` so the PHP-side analysis in
/// `BenchmarkComponent` runs the same code path on both platforms.
///
/// Where iOS can't measure something Android can — per-frame
/// input/animation/layout/draw breakdown via Android `FrameMetrics` —
/// the field is present but zero. This keeps the shape stable for
/// charting code.
enum PerfFunctions {

    // MARK: - Lifecycle

    class Enable: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let log = parameters["log"] as? Bool ?? false
            FrameTracker.shared.enabled = true
            InteractionTracker.shared.setEnabled(true, logRealtime: log)
            InteractionTracker.shared.reset()
            return ["success": true, "enabled": true]
        }
    }

    class Disable: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            InteractionTracker.shared.setEnabled(false)
            // FrameTracker stays on for the always-visible overlay;
            // disabling Perf only stops interaction recording.
            return ["success": true, "enabled": false]
        }
    }

    class Reset: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            InteractionTracker.shared.reset()
            return ["success": true]
        }
    }

    class Summary: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let json = buildExportJson()
            print("PERF SUMMARY: \(json)")
            return ["success": true]
        }
    }

    class Export: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return ["success": true, "data": buildExportJson()]
        }
    }

    // MARK: - Capture window

    class StartCaptureWindow: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            InteractionTracker.shared.startCaptureWindow()
            return ["success": true]
        }
    }

    class StopCaptureWindow: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            InteractionTracker.shared.stopCaptureWindow()
            return ["success": true, "data": buildCaptureWindowJson()]
        }
    }

    // MARK: - Simulation

    class SimulatePress: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let callbackId = (parameters["callback_id"] as? NSNumber)?.intValue else {
                return ["success": false]
            }
            let nodeId = (parameters["node_id"] as? NSNumber)?.intValue ?? 0
            NativeElementBridge.sendPressEvent(callbackId, nodeId: nodeId)
            return ["success": true]
        }
    }

    class SimulateTextChange: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let callbackId = (parameters["callback_id"] as? NSNumber)?.intValue else {
                return ["success": false]
            }
            let nodeId = (parameters["node_id"] as? NSNumber)?.intValue ?? 0
            let text = parameters["text"] as? String ?? ""
            NativeElementBridge.sendTextChangeEvent(callbackId, nodeId: nodeId, text: text)
            return ["success": true]
        }
    }

    class SimulateToggle: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let callbackId = (parameters["callback_id"] as? NSNumber)?.intValue else {
                return ["success": false]
            }
            let nodeId = (parameters["node_id"] as? NSNumber)?.intValue ?? 0
            let value = parameters["value"] as? Bool ?? false
            NativeElementBridge.sendToggleChangeEvent(callbackId, nodeId: nodeId, value: value)
            return ["success": true]
        }
    }

    /// iOS has no equivalent of Android's tree-diff toggle — SwiftUI's
    /// own diffing isn't a feature flag we can flip. Accept the call
    /// for API parity but it's a no-op.
    class SetDiffEnabled: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let enabled = parameters["enabled"] as? Bool ?? true
            return ["success": true, "diff_enabled": enabled, "platform_note": "iOS uses SwiftUI's built-in diff"]
        }
    }

    // MARK: - Memory

    /// Returns current resident memory plus delta from baseline.
    /// Baseline is captured the first time this is called after
    /// `Perf.Enable`. Pass `reset_baseline: true` to re-capture.
    class Memory: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            if parameters["reset_baseline"] as? Bool == true {
                MemoryProbe.resetBaseline()
            }
            let baseline = MemoryProbe.ensureBaseline()
            let current = MemoryProbe.currentResidentBytes()
            return [
                "success": true,
                "resident_bytes": current,
                "baseline_bytes": baseline,
                "delta_bytes": current - baseline
            ]
        }
    }
}

// MARK: - JSON helpers

private func buildExportJson() -> String {
    let snapshot = InteractionTracker.shared.snapshot()
    let frameIntervals = FrameTracker.shared.intervalsSnapshot()

    var root: [String: Any] = [
        "framework": "NativePHP EDGE (iOS)",
        "timestamp": Int(Date().timeIntervalSince1970 * 1000),
        "interaction_count": snapshot.interactions.count,
        "frame_count": frameIntervals.count
    ]

    if !snapshot.interactions.isEmpty {
        let totals = snapshot.interactions.map { $0.totalMs }.sorted()
        let deliveries = snapshot.interactions.map { $0.eventDeliveryMs }.sorted()
        let posts = snapshot.interactions.map { $0.composePostMs }.sorted()
        let paints = snapshot.interactions.map { $0.framePaintMs }.sorted()

        root["interaction_latency_ms"] = statsJson(totals)
        root["event_delivery_ms"] = statsJson(deliveries)
        root["compose_post_ms"] = statsJson(posts)
        root["frame_paint_ms"] = statsJson(paints)

        // Group by interaction type.
        var byType: [String: Any] = [:]
        let grouped = Dictionary(grouping: snapshot.interactions, by: { $0.type })
        for (type, items) in grouped {
            let typeTotals = items.map { $0.totalMs }.sorted()
            var obj = statsJson(typeTotals)
            obj["event_delivery_avg"] = avg(items.map { $0.eventDeliveryMs })
            obj["compose_post_avg"] = avg(items.map { $0.composePostMs })
            obj["frame_paint_avg"] = avg(items.map { $0.framePaintMs })
            byType[type] = obj
        }
        root["interaction_by_type"] = byType

        let paintJankCount = snapshot.interactions.filter { $0.framePaintMs > 16.67 }.count
        let tripJankCount = snapshot.interactions.filter { $0.totalMs > 16.67 }.count
        let totalsAvg = avg(totals)
        root["interaction_jank"] = [
            "paint_jank_count": paintJankCount,
            "paint_jank_rate": Double(paintJankCount) / Double(snapshot.interactions.count),
            "trip_jank_count": tripJankCount,
            "trip_jank_rate": Double(tripJankCount) / Double(snapshot.interactions.count),
            "effective_fps": totalsAvg > 0 ? 1000.0 / totalsAvg : 0
        ]
    }

    if !frameIntervals.isEmpty {
        let sorted = frameIntervals.sorted()
        let jankCount = sorted.filter { $0 > 16.0 }.count
        var frameStats = statsJson(sorted)
        frameStats["jank_count"] = jankCount
        frameStats["jank_rate"] = Double(jankCount) / Double(sorted.count)
        let frameAvg = avg(sorted)
        frameStats["fps"] = frameAvg > 0 ? 1000.0 / frameAvg : 0
        // iOS can't break out sub-frame phases without instruments — leave
        // the fields at zero for shape parity with Android.
        frameStats["avg_input_ms"] = 0
        frameStats["avg_animation_ms"] = 0
        frameStats["avg_layout_ms"] = 0
        frameStats["avg_draw_ms"] = 0
        frameStats["avg_sync_ms"] = 0
        root["frame_times_ms"] = frameStats
    }

    // Raw arrays for detailed charting.
    root["raw_interactions"] = snapshot.interactions.map { m in
        [
            "callback_id": m.callbackId,
            "type": m.type,
            "timestamp": m.timestampMs,
            "event_delivery_ms": m.eventDeliveryMs,
            "compose_post_ms": m.composePostMs,
            "frame_paint_ms": m.framePaintMs,
            "total_ms": m.totalMs
        ] as [String: Any]
    }
    root["raw_frames"] = frameIntervals.map { ms in
        [
            "total_ms": ms,
            "input_ms": 0,
            "layout_ms": 0,
            "draw_ms": 0,
            "sync_ms": 0
        ] as [String: Any]
    }

    return jsonString(from: root)
}

private func buildCaptureWindowJson() -> String {
    let snapshot = InteractionTracker.shared.snapshot()
    let frames = snapshot.captureWindowFrames
    var root: [String: Any] = [
        "framework": "NativePHP EDGE (iOS)",
        "frame_count": frames.count
    ]
    if !frames.isEmpty {
        let sorted = frames.sorted()
        var frameStats = statsJson(sorted)
        let jankCount = sorted.filter { $0 > 16.0 }.count
        frameStats["jank_count"] = jankCount
        frameStats["jank_rate"] = Double(jankCount) / Double(sorted.count)
        let frameAvg = avg(sorted)
        frameStats["fps"] = frameAvg > 0 ? 1000.0 / frameAvg : 0
        frameStats["avg_input_ms"] = 0
        frameStats["avg_animation_ms"] = 0
        frameStats["avg_layout_ms"] = 0
        frameStats["avg_draw_ms"] = 0
        frameStats["avg_sync_ms"] = 0
        root["frame_times_ms"] = frameStats
    }
    return jsonString(from: root)
}

/// Returns `{ "average": ..., "p50": ..., "p95": ..., "p99": ..., "min": ..., "max": ..., "count": ... }`.
/// Input must already be sorted ascending.
private func statsJson(_ sorted: [Double]) -> [String: Any] {
    if sorted.isEmpty {
        return ["count": 0]
    }
    let n = sorted.count
    let p = { (q: Double) -> Double in sorted[min(n - 1, max(0, Int(Double(n) * q) - 1))] }
    // Use explicit indices, not `sorted.first`/`sorted.last` — those
    // return `Double?` which gets boxed as `Optional<Double>` inside an
    // `[String: Any]` literal (the `??` doesn't unwrap because the value
    // type is already `Any`). `Optional` doesn't bridge to NSNumber, so
    // `JSONSerialization` then throws `Invalid type in JSON write
    // (__SwiftValue)` and crashes Perf.Export. We checked `isEmpty`
    // above, so indexing is safe.
    let minVal: Double = sorted[0]
    let maxVal: Double = sorted[n - 1]
    return [
        "count": n,
        "average": sorted.reduce(0, +) / Double(n),
        "p50": p(0.50),
        "p95": p(0.95),
        "p99": p(0.99),
        "min": minVal,
        "max": maxVal
    ]
}

private func avg(_ xs: [Double]) -> Double {
    xs.isEmpty ? 0 : xs.reduce(0, +) / Double(xs.count)
}

private func jsonString(from obj: [String: Any]) -> String {
    guard let data = try? JSONSerialization.data(withJSONObject: obj, options: [.prettyPrinted, .sortedKeys]),
          let str = String(data: data, encoding: .utf8) else {
        return "{}"
    }
    return str
}
