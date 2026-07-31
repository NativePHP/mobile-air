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
