import Foundation
import UIKit
import ZIPFoundation


class AppUpdateManager {
    static let shared = AppUpdateManager()

    private let documentsPath: String
    private let appPath: String
    private let updatesPath: String
    private var appReady = false

    private init() {
        let documentsURL = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask).first!
        documentsPath = documentsURL.path
        appPath = documentsURL.appendingPathComponent("app").path
        updatesPath = documentsURL.appendingPathComponent("updates").path

        // Ensure directories exist
        try? FileManager.default.createDirectory(atPath: appPath, withIntermediateDirectories: true)
        try? FileManager.default.createDirectory(atPath: updatesPath, withIntermediateDirectories: true)
    }

    func getAppPath() -> String {
        return appPath
    }

    /// Returns true if extraction or update was applied (artisan commands needed).
    @discardableResult
    func ensureAppExists() -> Bool {
        print("📦 AppUpdateManager.ensureAppExists() starting")

        var didExtract = false

        // Check if app exists in documents directory and if bundled version should be extracted
        if !hasApp() || shouldUpdateFromBundle() {
            copyBundledApp()
            didExtract = true
        }

        // Check for and apply any pending updates
        if applyPendingUpdates() {
            didExtract = true
        }

        appReady = true
        print("✅ App is ready (didExtract=\(didExtract))")
        return didExtract
    }

    private func hasApp() -> Bool {
        // Use installed.version as the readiness marker: it is written only AFTER a
        // fully successful extraction. A partial/interrupted extraction may have
        // written .env (and some vendor files) but not all of them, so keying off
        // .env would treat a broken half-install as complete and crash on a missing
        // require. The marker is only present when extraction finished cleanly.
        let marker = appPath + "/installed.version"

        if FileManager.default.fileExists(atPath: marker) {
            print("📦 An app bundle has already been extracted");
            return true
        }

        print("📦 No app bundle extracted!");

        return false
    }

    private func copyBundledApp() {
        print("📦 Extracting bundled app to documents directory...")

        guard let bundleZipPath = Bundle.main.path(forResource: "app", ofType: "zip") else {
            print("❌ No bundled app.zip found")
            return
        }

        do {
            // Remove existing app if any
            if FileManager.default.fileExists(atPath: appPath) {
                try FileManager.default.removeItem(atPath: appPath)
            }

            // Extract ZIP to app directory
            let sourceURL = URL(fileURLWithPath: bundleZipPath)
            let destinationURL = URL(fileURLWithPath: appPath)

            // Create the app directory if it doesn't exist
            try FileManager.default.createDirectory(at: destinationURL, withIntermediateDirectories: true)

            try extractZipParallel(from: sourceURL, to: destinationURL)
            print("✅ Bundled app extracted successfully")

            // Create installed.version file after successful extraction
            createInstalledVersionFile()

            // Run migrations and clear caches for newly extracted app
            runMigrationsAndClearCaches()
        } catch {
            print("❌ Failed to extract bundled app: \(error)")

            // Never leave a half-extracted bundle behind. Without this, a partial app
            // (e.g. .env present but vendor files missing) survives on disk and the next
            // launch treats it as installed, crashing forever on a missing require.
            // Removing it forces a clean re-extraction on the next launch (self-healing).
            try? FileManager.default.removeItem(atPath: appPath)
        }
    }

    private func extractZipParallel(from sourceURL: URL, to destinationURL: URL) throws {
        guard let archive = Archive(url: sourceURL, accessMode: .read) else {
            throw NSError(domain: "AppUpdateManager", code: 1, userInfo: [NSLocalizedDescriptionKey: "Failed to open ZIP archive"])
        }

        // Phase 1: Read all entries from ZIP sequentially (ZIP format requires this)
        // but buffer file data in memory
        var directoryPaths: [URL] = []
        var fileDataMap: [(path: URL, data: Data)] = []

        for entry in archive {
            // ZIPFoundation 0.9.19's `entry.path` decodes as CP437 unless the EFS bit
            // is set on the entry — and macOS `zip` often omits that flag even for
            // UTF-8 names, producing mojibake (⚡️ → ΓÜí∩╕Å). Force UTF-8 decode via
            // path(using:); fall back to `entry.path` only if the bytes aren't valid
            // UTF-8 (which shouldn't happen for archives we build).
            let utf8Path = entry.path(using: .utf8)
            let rawPath = utf8Path.isEmpty ? entry.path : utf8Path

            // Normalize to NFC: macOS sources may emit NFD filenames; iOS APFS stores
            // bytes verbatim, but PHP autoloaders look up the NFC form.
            let normalizedPath = (rawPath as NSString).precomposedStringWithCanonicalMapping
            let destinationPath = destinationURL.appendingPathComponent(normalizedPath)

            switch entry.type {
            case .directory:
                directoryPaths.append(destinationPath)
            case .file:
                // Pre-allocate buffer with expected size (64KB default or actual size if known)
                let expectedSize = Int(entry.uncompressedSize)
                var fileData = Data(capacity: max(expectedSize, 65536))
                _ = try archive.extract(entry, bufferSize: 65536) { data in
                    fileData.append(data)
                }
                fileDataMap.append((path: destinationPath, data: fileData))
            case .symlink:
                // Handle symlinks if needed
                break
            }
        }

        // Phase 2: Create all directories (sequential, fast). Include every file's
        // parent directory up front so the concurrent writers in Phase 3 never race
        // to create the same intermediate directories. Concurrent
        // createDirectory(withIntermediateDirectories:) on overlapping paths is a
        // TOCTOU race on APFS that can spuriously fail and abort the whole extraction,
        // leaving a partial app on disk.
        var directoriesToCreate = Set<String>()
        for dirPath in directoryPaths {
            directoriesToCreate.insert(dirPath.path)
        }
        for (path, _) in fileDataMap {
            directoriesToCreate.insert(path.deletingLastPathComponent().path)
        }
        for dir in directoriesToCreate {
            try FileManager.default.createDirectory(atPath: dir, withIntermediateDirectories: true)
        }

        // Phase 3: Write all files in parallel (the slow part). All parent directories
        // already exist, so each writer only touches its own leaf file, with no shared
        // mutable filesystem state.
        let queue = DispatchQueue(label: "zip.write", attributes: .concurrent)
        let group = DispatchGroup()
        let errorLock = NSLock()
        var writeError: Error?

        for (path, data) in fileDataMap {
            group.enter()
            queue.async {
                defer { group.leave() }

                do {
                    try data.write(to: path, options: .atomic)
                } catch {
                    errorLock.lock()
                    if writeError == nil {
                        writeError = error
                    }
                    errorLock.unlock()
                }
            }
        }

        group.wait()

        if let error = writeError {
            throw error
        }

        // Phase 4: Verify every expected file actually landed on disk. Guards against
        // silent truncation or a jetsam-killed writer so that a missing file aborts
        // extraction (and triggers cleanup + retry) instead of being mistaken for a
        // complete install.
        for (path, _) in fileDataMap {
            if !FileManager.default.fileExists(atPath: path.path) {
                throw NSError(
                    domain: "AppUpdateManager",
                    code: 2,
                    userInfo: [NSLocalizedDescriptionKey: "Extraction incomplete: missing \(path.lastPathComponent)"]
                )
            }
        }
    }

    func installUpdate(from zipPath: String) -> Bool {
        print("📦 Installing app update from: \(zipPath)")

        let extractPath = updatesPath + "/extracted_" + UUID().uuidString
        let envStashPath = updatesPath + "/.env.stash"
        let currentEnvPath = appPath + "/.env"

        do {
            // Stash the running .env so device-local values survive the payload replace.
            // Restore it verbatim over the extracted .env; no key merge. Skip if none.
            let hadEnv = FileManager.default.fileExists(atPath: currentEnvPath)
            if hadEnv {
                if FileManager.default.fileExists(atPath: envStashPath) {
                    try FileManager.default.removeItem(atPath: envStashPath)
                }
                try FileManager.default.copyItem(atPath: currentEnvPath, toPath: envStashPath)
                print("📦 Stashed existing .env before pending update")
            }

            // Create extraction directory
            try FileManager.default.createDirectory(atPath: extractPath, withIntermediateDirectories: true)

            // Extract zip using ZIPFoundation
            let sourceURL = URL(fileURLWithPath: zipPath)
            let destinationURL = URL(fileURLWithPath: extractPath)

            do {
                try FileManager.default.unzipItem(at: sourceURL, to: destinationURL)
            } catch {
                print("❌ Failed to extract zip file: \(error)")
                try? FileManager.default.removeItem(atPath: envStashPath)
                return false
            }

            // Verify extracted app has required structure
            guard isValidApp(at: extractPath) else {
                print("❌ Invalid app structure in zip")
                try? FileManager.default.removeItem(atPath: extractPath)
                try? FileManager.default.removeItem(atPath: envStashPath)
                return false
            }

            // Backup current app
            let backupPath = updatesPath + "/backup_" + String(Int(Date().timeIntervalSince1970))
            try FileManager.default.moveItem(atPath: appPath, toPath: backupPath)

            // Move new app into place
            try FileManager.default.moveItem(atPath: extractPath, toPath: appPath)

            // Restore the stashed .env over whatever the zip extracted
            if hadEnv, FileManager.default.fileExists(atPath: envStashPath) {
                let newEnvPath = appPath + "/.env"
                if FileManager.default.fileExists(atPath: newEnvPath) {
                    try FileManager.default.removeItem(atPath: newEnvPath)
                }
                try FileManager.default.copyItem(atPath: envStashPath, toPath: newEnvPath)
                try? FileManager.default.removeItem(atPath: envStashPath)
                print("📦 Restored stashed .env over extracted payload")
            }

            // Create installed.version file for the new version
            createInstalledVersionFile()

            // Run migrations and clear caches for updated app
            runMigrationsAndClearCaches()

            // Cleanup
            try? FileManager.default.removeItem(atPath: extractPath)
            try? FileManager.default.removeItem(atPath: zipPath)
            try? FileManager.default.removeItem(atPath: envStashPath)

            // Keep only the latest backup
            cleanupOldBackups()

            print("✅ App update installed successfully")
            return true

        } catch {
            print("❌ Failed to install update: \(error)")

            // Cleanup on failure
            try? FileManager.default.removeItem(atPath: extractPath)
            try? FileManager.default.removeItem(atPath: envStashPath)
            return false
        }
    }

    private func isValidApp(at path: String) -> Bool {
        let envFile = path + "/.env"
        let vendorDir = path + "/vendor"
        let bootstrapFile = path + "/vendor/nativephp/mobile-lite/bootstrap/ios/native.php"

        return FileManager.default.fileExists(atPath: envFile) &&
               FileManager.default.fileExists(atPath: vendorDir) &&
               FileManager.default.fileExists(atPath: bootstrapFile)
    }

    @discardableResult
    private func applyPendingUpdates() -> Bool {
        let updateFiles = (try? FileManager.default.contentsOfDirectory(atPath: updatesPath)) ?? []
        let zipFiles = updateFiles.filter { $0.hasSuffix(".zip") }

        // Prefer a stable pending.zip (plugin download target) so leftover
        // timestamped zips don't win. Still only apply one zip per boot.
        let ordered = zipFiles.contains("pending.zip")
            ? ["pending.zip"] + zipFiles.filter { $0 != "pending.zip" }
            : zipFiles

        for zipFile in ordered {
            let zipPath = updatesPath + "/" + zipFile
            if installUpdate(from: zipPath) {
                // Only install one update at a time
                return true
            }
        }
        return false
    }

    private func cleanupOldBackups() {
        let updateFiles = (try? FileManager.default.contentsOfDirectory(atPath: updatesPath)) ?? []
        let backupDirs = updateFiles.filter { $0.hasPrefix("backup_") }

        // Keep only the most recent backup
        if backupDirs.count > 1 {
            let sortedBackups = backupDirs.sorted().dropLast()
            for backup in sortedBackups {
                try? FileManager.default.removeItem(atPath: updatesPath + "/" + backup)
            }
        }
    }

    /// Returns just the version name (e.g. "1.0.0"). Used for OTA URLs / display.
    /// For the staleness comparison against the bundle, use `getInstalledVersionId()`.
    func getAppVersion() -> String? {
        if !FileManager.default.fileExists(atPath: appPath) {
            print("❌ App directory doesn't exist - assuming DEBUG")
            return "DEBUG"
        }

        let envFile = appPath + "/.env"
        if FileManager.default.fileExists(atPath: envFile) {
            if let version = getVersionFromEnvFile(envFile) {
                print("🔢 Got current version from .env: \(version)")
                return version
            }
        }

        print("❌ .env file not found or no version - assume DEBUG")
        return "DEBUG"
    }

    /// Returns the composite "version+build" identity for the installed app
    /// (e.g. "1.0.0b42", or "DEBUG"). Falls back to building it from the
    /// extracted .env if installed.version is missing.
    private func getInstalledVersionId() -> String? {
        if let id = getInstalledVersion() {
            return id
        }
        return getInstalledVersionIdFromEnv()
    }

    private func getInstalledVersionIdFromEnv() -> String? {
        let envFile = appPath + "/.env"
        guard FileManager.default.fileExists(atPath: envFile) else {
            return nil
        }
        guard let version = getVersionFromEnvFile(envFile) else {
            return nil
        }
        return buildVersionId(version: version, versionCode: getVersionCodeFromEnvFile(envFile))
    }

    private func buildVersionId(version: String, versionCode: String?) -> String {
        if version == "DEBUG" {
            return "DEBUG"
        }
        return "\(version)b\(versionCode ?? "0")"
    }

    private func getVersionFromEnvFile(_ envFilePath: String) -> String? {
        return getEnvValue(envFilePath, key: "NATIVEPHP_APP_VERSION")
    }

    private func getVersionCodeFromEnvFile(_ envFilePath: String) -> String? {
        return getEnvValue(envFilePath, key: "NATIVEPHP_APP_VERSION_CODE")
    }

    private func getEnvValue(_ envFilePath: String, key: String) -> String? {
        do {
            let envContent = try String(contentsOfFile: envFilePath, encoding: .utf8)
            return extractEnvValue(envContent, key: key)
        } catch {
            print("❌ Error reading .env file: \(error)")
            return nil
        }
    }

    private func extractEnvValue(_ envContent: String, key: String) -> String? {
        do {
            let pattern = "^\(NSRegularExpression.escapedPattern(for: key))=(.+)$"
            let regex = try NSRegularExpression(pattern: pattern, options: [.anchorsMatchLines])
            let range = NSRange(location: 0, length: envContent.utf16.count)

            if let match = regex.firstMatch(in: envContent, range: range) {
                let valueRange = Range(match.range(at: 1), in: envContent)!
                let value = String(envContent[valueRange]).trimmingCharacters(in: .whitespacesAndNewlines)
                return value.trimmingCharacters(in: CharacterSet(charactersIn: "\"'"))
            }
        } catch {
            print("❌ Error matching \(key) in .env: \(error)")
        }
        return nil
    }

    private func shouldUpdateFromBundle() -> Bool {
        guard let bundledId = getBundledAppVersionFast() else {
            print("⚠️ Could not read version from bundled.version file, falling back to ZIP extraction")
            guard let bundledId = getBundledAppVersion() else {
                print("⚠️ Could not read version from bundled app.zip")
                return false
            }
            return shouldUpdateWithVersion(bundledId)
        }

        return shouldUpdateWithVersion(bundledId)
    }

    private func shouldUpdateWithVersion(_ bundledId: String) -> Bool {
        // Special case: If bundled version is DEBUG, always update from bundle (for development)
        if bundledId == "DEBUG" {
            print("🚧 DEBUG version detected, updating from bundle")
            return true
        }

        let currentId = getInstalledVersionId()

        // If composite identities differ, update from bundle
        if currentId != bundledId {
            print("📦 Bundle id (\(bundledId)) differs from current (\(currentId ?? "none")), updating from bundle")
            return true
        }

        print("✅ App already up to date with bundle id (\(bundledId))")
        return false
    }

    private func getBundledAppVersion() -> String? {
        guard let bundlePath = Bundle.main.path(forResource: "app", ofType: "zip") else {
            print("❌ No bundled app.zip found")
            return nil
        }

        return getVersionFromZip(at: bundlePath)
    }

    private func getVersionFromZip(at zipPath: String) -> String? {
        let sourceURL = URL(fileURLWithPath: zipPath)

        // Stream-read specific entries from ZIP without extracting everything
        guard let archive = Archive(url: sourceURL, accessMode: .read) else {
            print("❌ Failed to open ZIP archive")
            return nil
        }

        // Try to build composite from .env first (preferred - has both version + version_code)
        if let envEntry = archive[".env"] {
            var envContent = Data()
            do {
                _ = try archive.extract(envEntry) { data in
                    envContent.append(data)
                }
                if let envString = String(data: envContent, encoding: .utf8) {
                    if let version = extractEnvValue(envString, key: "NATIVEPHP_APP_VERSION") {
                        let versionCode = extractEnvValue(envString, key: "NATIVEPHP_APP_VERSION_CODE")
                        print("✅ Read version id from .env without full extraction")
                        return buildVersionId(version: version, versionCode: versionCode)
                    }
                }
            } catch {
                print("⚠️ Failed to read .env from ZIP: \(error)")
            }
        }

        // Fallback: try .version file (already a composite when written by PreparesBuild)
        if let versionEntry = archive[".version"] {
            var versionContent = Data()
            do {
                _ = try archive.extract(versionEntry) { data in
                    versionContent.append(data)
                }
                if let versionString = String(data: versionContent, encoding: .utf8) {
                    print("✅ Read version id from .version without full extraction")
                    return versionString.trimmingCharacters(in: .whitespacesAndNewlines)
                }
            } catch {
                print("⚠️ Failed to read .version from ZIP: \(error)")
            }
        }

        print("❌ No .env or .version found in ZIP")
        return nil
    }

    func receiveUpdate(data: Data, filename: String) -> Bool {
        let updatePath = updatesPath + "/" + filename

        do {
            try data.write(to: URL(fileURLWithPath: updatePath))
            print("📥 Update received: \(filename)")
            return true
        } catch {
            print("❌ Failed to save update: \(error)")
            return false
        }
    }

    // MARK: - Fast Version Checking
    //
    // OTA check/download lives in the mobile-ota plugin. Core only applies
    // pending zips from Documents/updates on boot via applyPendingUpdates().

    private func getBundledAppVersionFast() -> String? {
        guard let bundlePath = Bundle.main.path(forResource: "bundled", ofType: "version") else {
            print("❌ No bundled.version file found")
            return nil
        }

        do {
            let version = try String(contentsOfFile: bundlePath, encoding: .utf8)
            let trimmedVersion = version.trimmingCharacters(in: .whitespacesAndNewlines)
            print("🔢 Got bundled version from bundled.version: \(trimmedVersion)")
            return trimmedVersion
        } catch {
            print("❌ Error reading bundled.version file: \(error)")
            return nil
        }
    }

    private func getInstalledVersion() -> String? {
        let installedVersionPath = documentsPath + "/app/installed.version"

        guard FileManager.default.fileExists(atPath: installedVersionPath) else {
            print("❌ No installed.version file found")
            return nil
        }

        do {
            let version = try String(contentsOfFile: installedVersionPath, encoding: .utf8)
            let trimmedVersion = version.trimmingCharacters(in: .whitespacesAndNewlines)
            print("🔢 Got installed version from installed.version: \(trimmedVersion)")
            return trimmedVersion
        } catch {
            print("❌ Error reading installed.version file: \(error)")
            return nil
        }
    }

    private func createInstalledVersionFile() {
        let installedVersionPath = documentsPath + "/app/installed.version"

        // Build composite identity from the extracted .env so the on-disk
        // identity matches the bundle (same format: "version+b+versionCode" or "DEBUG").
        guard let id = getInstalledVersionIdFromEnv() else {
            print("⚠️ Could not determine app version id for installed.version file")
            return
        }

        do {
            try id.write(toFile: installedVersionPath, atomically: true, encoding: .utf8)
            print("📝 Created installed.version file: \(id)")
        } catch {
            print("❌ Failed to create installed.version file: \(error)")
        }
    }

    private func runMigrationsAndClearCaches() {
        print("🔄 Running migrations and clearing caches...")

        guard let app = NativePHPApp.shared else {
            print("❌ NativePHPApp.shared not available")
            return
        }

        // Run migrations
        _ = app.artisan(additionalArgs: ["migrate", "--force"])

        // Clear caches
        _ = app.artisan(additionalArgs: ["view:clear"])

        print("✅ Migrations and cache clearing completed")
    }

}
