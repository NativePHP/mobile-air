import Foundation

// MARK: - File Function Namespace
//
// Core built-in (migrated from the `nativephp/mobile-file` plugin). Registered
// directly in `BridgeFunctionRegistration.swift` alongside Edge/Perf/UI/Device/
// System/Dialog. Self-contained (FileManager only). Android twin:
// `bridge/functions/FileFunctions.kt`.

/// Functions related to file operations
/// Namespace: "File.*"
enum FileFunctions {

    // MARK: - Path confinement
    //
    // File.Move/File.Copy are exported in the JS bridge, so a web view page can
    // call them through `/_native/api/call` with `from`/`to` of its choosing.
    // Left open that is two primitives against the app sandbox: copy a secret
    // (`.env`, the SQLite database) into `public/` and read it back over the
    // app origin at `/_assets/…`, and truncate or clobber any file the app
    // relies on.
    //
    // So the paths are confined, not just the method gated: a source must live
    // in a scratch area (temp or caches) or in the app's own storage, and a
    // destination must land inside the app's storage. Secrets like `.env` and
    // the database sit outside those roots, so they can be neither read out nor
    // overwritten. The legitimate use — moving a captured camera/gallery file
    // out of temp into `storage/app` — stays inside the allowed roots.
    //
    // Every path is canonicalised first (symlinks and `..` resolved) and only
    // then prefix-checked, so neither `..` nor a symlink can walk out of a root.

    /// A path with every symlink and `..`/`.` resolved. For a target that does
    /// not exist yet (a move/copy destination), the existing parent is resolved
    /// and the final component re-appended, so the check sees where the write
    /// would actually land.
    private static func canonical(_ path: String) -> String {
        let fileManager = FileManager.default
        let url = URL(fileURLWithPath: path)

        if fileManager.fileExists(atPath: url.path) {
            return url.resolvingSymlinksInPath().standardizedFileURL.path
        }

        let parent = url.deletingLastPathComponent().resolvingSymlinksInPath().standardizedFileURL
        return parent.appendingPathComponent(url.lastPathComponent).standardizedFileURL.path
    }

    /// True when `path` is `root` itself or sits beneath it. Both must already
    /// be canonical, so a prefix match is a real containment.
    private static func isInside(_ path: String, _ root: String) -> Bool {
        !root.isEmpty && (path == root || path.hasPrefix(root + "/"))
    }

    /// The app's own storage area (`Documents/app/storage/app`) — where a
    /// dispatched file may legitimately be written.
    private static var storageAppRoot: String {
        canonical(AppUpdateManager.shared.getAppPath() + "/storage/app")
    }

    /// Scratch areas a source file may come from, plus the storage area.
    private static var sourceRoots: [String] {
        var roots = [canonical(NSTemporaryDirectory()), storageAppRoot]
        if let caches = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first {
            roots.append(canonical(caches.path))
        }
        return roots.filter { !$0.isEmpty }
    }

    /// Refuse a move/copy whose source or destination falls outside the allowed
    /// roots. Returns an error payload to hand straight back, or nil to proceed.
    private static func confinementError(from: String, to: String) -> [String: Any]? {
        let fromReal = canonical(from)
        let toReal = canonical(to)

        guard sourceRoots.contains(where: { isInside(fromReal, $0) }) else {
            print("File op refused: source outside the allowed roots")
            return ["success": false, "error": "Source path is not allowed"]
        }

        guard isInside(toReal, storageAppRoot) else {
            print("File op refused: destination outside app storage")
            return ["success": false, "error": "Destination path is not allowed"]
        }

        return nil
    }

    // MARK: - File.Move

    /// Move a file from one location to another
    class Move: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let from = parameters["from"] as? String, !from.isEmpty else {
                return ["success": false, "error": "'from' parameter is required"]
            }

            guard let to = parameters["to"] as? String, !to.isEmpty else {
                return ["success": false, "error": "'to' parameter is required"]
            }

            if let refusal = FileFunctions.confinementError(from: from, to: to) {
                return refusal
            }

            print("Move requested - from: '\(from)', to: '\(to)'")

            let fileManager = FileManager.default
            let sourceURL = URL(fileURLWithPath: from)
            let destURL = URL(fileURLWithPath: to)

            do {
                guard fileManager.fileExists(atPath: sourceURL.path) else {
                    return ["success": false, "error": "Source file does not exist"]
                }

                var isDirectory: ObjCBool = false
                fileManager.fileExists(atPath: sourceURL.path, isDirectory: &isDirectory)
                if isDirectory.boolValue {
                    return ["success": false, "error": "Source is not a file"]
                }

                let destParent = destURL.deletingLastPathComponent()
                if !fileManager.fileExists(atPath: destParent.path) {
                    try fileManager.createDirectory(at: destParent, withIntermediateDirectories: true)
                }

                if fileManager.fileExists(atPath: destURL.path) {
                    try fileManager.removeItem(at: destURL)
                }

                try fileManager.moveItem(at: sourceURL, to: destURL)

                print("File moved successfully")
                return ["success": true]

            } catch {
                print("Error moving file: \(error.localizedDescription)")
                return ["success": false, "error": error.localizedDescription]
            }
        }
    }

    // MARK: - File.Copy

    /// Copy a file from one location to another
    class Copy: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let from = parameters["from"] as? String, !from.isEmpty else {
                return ["success": false, "error": "'from' parameter is required"]
            }

            guard let to = parameters["to"] as? String, !to.isEmpty else {
                return ["success": false, "error": "'to' parameter is required"]
            }

            if let refusal = FileFunctions.confinementError(from: from, to: to) {
                return refusal
            }

            print("Copy requested - from: '\(from)', to: '\(to)'")

            let fileManager = FileManager.default
            let sourceURL = URL(fileURLWithPath: from)
            let destURL = URL(fileURLWithPath: to)

            do {
                guard fileManager.fileExists(atPath: sourceURL.path) else {
                    return ["success": false, "error": "Source file does not exist"]
                }

                var isDirectory: ObjCBool = false
                fileManager.fileExists(atPath: sourceURL.path, isDirectory: &isDirectory)
                if isDirectory.boolValue {
                    return ["success": false, "error": "Source is not a file"]
                }

                let destParent = destURL.deletingLastPathComponent()
                if !fileManager.fileExists(atPath: destParent.path) {
                    try fileManager.createDirectory(at: destParent, withIntermediateDirectories: true)
                }

                if fileManager.fileExists(atPath: destURL.path) {
                    try fileManager.removeItem(at: destURL)
                }

                try fileManager.copyItem(at: sourceURL, to: destURL)

                print("File copied successfully")
                return ["success": true]

            } catch {
                print("Error copying file: \(error.localizedDescription)")
                return ["success": false, "error": error.localizedDescription]
            }
        }
    }
}
