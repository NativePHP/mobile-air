import Foundation
import SwiftUI

/// Native-first boot state shared between the app bootstrap and ContentView.
///
/// In NATIVE_DIRECT mode no WKWebView exists until a web response actually
/// needs painting (web start route, EXIT_WEB from the native runloop, or a
/// consumer that insists on one). `webViewAllowed` gates the WebView branch
/// of ContentView; `pendingWebPath` carries an EXIT_WEB destination into the
/// lazily-created WebView's first load.
@MainActor
final class BootState: ObservableObject {
    static let shared = BootState()

    /// False during a native-direct boot until a web screen is needed —
    /// while false, ContentView's non-native branch renders a plain
    /// background instead of mounting (and thereby creating) the WebView.
    @Published var webViewAllowed = true

    /// Path the lazily-created WebView should load instead of the start URL
    /// (set by the EXIT_WEB handler before allowing the WebView).
    var pendingWebPath: String?

    private init() {}

    func allowWebView(loading path: String?) {
        pendingWebPath = path
        webViewAllowed = true
    }
}

/// Decides how the first screen boots: direct dispatch into the native
/// runloop (no WKWebView, no WebContent/Networking processes) or the legacy
/// WebView path. Mirrors Android's BootPlanner — same manifest, same
/// matching rules, same safe fallback.
enum BootPlanner {
    enum Entry { case nativeDirect, webLegacy }

    static func plan(startPath: String) -> Entry {
        guard let meta = readBundleMeta() else {
            NSLog("[BootPlanner] No bundle_meta.json — legacy WebView boot")
            return .webLegacy
        }

        if (meta["entry_mode"] as? String) == "web" {
            NSLog("[BootPlanner] entry_mode=web — forcing legacy WebView boot")
            return .webLegacy
        }

        guard let patterns = freshestPatterns(meta: meta) else {
            NSLog("[BootPlanner] No native-route manifest — legacy WebView boot")
            return .webLegacy
        }

        let path = startPath.components(separatedBy: "?").first ?? "/"
        let matched = patterns.contains { matches(pattern: $0, path: path) }
        NSLog("[BootPlanner] Boot plan for \(path): \(matched ? "NATIVE_DIRECT" : "WEB_LEGACY") (\(patterns.count) native patterns)")
        return matched ? .nativeDirect : .webLegacy
    }

    private static func readBundleMeta() -> [String: Any]? {
        guard let url = Bundle.main.url(forResource: "bundle_meta", withExtension: "json"),
              let data = try? Data(contentsOf: url),
              let obj = try? JSONSerialization.jsonObject(with: data) as? [String: Any]
        else { return nil }
        return obj
    }

    /// Prefer the runtime dump (written by NativeServiceProvider on every PHP
    /// boot) when its bundle version matches the baked manifest — it reflects
    /// routes added/removed by hot reload between builds.
    private static func freshestPatterns(meta: [String: Any]) -> [String]? {
        let baked = meta["native_routes"] as? [String]
        let bakedVersion = meta["version"] as? String ?? ""

        if let storageDir = FileManager.default
            .urls(for: .applicationSupportDirectory, in: .userDomainMask).first {
            let runtimeFile = storageDir
                .appendingPathComponent("storage/framework/native_routes.json")
            if let data = FileManager.default.contents(atPath: runtimeFile.path),
               let obj = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
               (obj["version"] as? String) == bakedVersion,
               let routes = obj["routes"] as? [String] {
                return routes
            }
        }
        return baked
    }

    /// Segment matcher for Laravel-style URI patterns: `/users/{id}` matches
    /// `/users/42`; `{param?}` segments are optional trailing matches.
    static func matches(pattern: String, path: String) -> Bool {
        let p = pattern.split(separator: "/").map(String.init)
        let s = path.split(separator: "/").map(String.init)

        var i = 0
        while i < p.count {
            let seg = p[i]
            let isParam = seg.hasPrefix("{") && seg.hasSuffix("}")
            let isOptional = isParam && seg.hasSuffix("?}")
            if i < s.count {
                if !isParam && seg != s[i] { return false }
            } else {
                return isOptional || p[i...].allSatisfy { $0.hasPrefix("{") && $0.hasSuffix("?}") }
            }
            i += 1
        }
        return s.count == p.count
    }
}
