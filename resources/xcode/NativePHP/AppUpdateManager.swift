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
        let envFile = appPath + "/.env"

        if FileManager.default.fileExists(atPath: envFile) {
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
            let destinationPath = destinationURL.appendingPathComponent(entry.path)

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

        // Phase 2: Create all directories (sequential, fast)
        for dirPath in directoryPaths {
            try FileManager.default.createDirectory(at: dirPath, withIntermediateDirectories: true)
        }

        // Phase 3: Write all files in parallel (the slow part)
        let queue = DispatchQueue(label: "zip.write", attributes: .concurrent)
        let group = DispatchGroup()
        let errorLock = NSLock()
        var writeError: Error?

        for (path, data) in fileDataMap {
            group.enter()
            queue.async {
                defer { group.leave() }

                do {
                    // Ensure parent directory exists
                    try FileManager.default.createDirectory(at: path.deletingLastPathComponent(), withIntermediateDirectories: true)
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
    }

    func installUpdate(from zipPath: String) -> Bool {
        print("📦 Installing app update from: \(zipPath)")

        let extractPath = updatesPath + "/extracted_" + UUID().uuidString

        do {
            // Create extraction directory
            try FileManager.default.createDirectory(atPath: extractPath, withIntermediateDirectories: true)

            // Extract zip using ZIPFoundation
            let sourceURL = URL(fileURLWithPath: zipPath)
            let destinationURL = URL(fileURLWithPath: extractPath)

            do {
                try FileManager.default.unzipItem(at: sourceURL, to: destinationURL)
            } catch {
                print("❌ Failed to extract zip file: \(error)")
                return false
            }

            // Verify extracted app has required structure
            guard isValidApp(at: extractPath) else {
                print("❌ Invalid app structure in zip")
                try? FileManager.default.removeItem(atPath: extractPath)
                return false
            }

            // Backup current app
            let backupPath = updatesPath + "/backup_" + String(Int(Date().timeIntervalSince1970))
            try FileManager.default.moveItem(atPath: appPath, toPath: backupPath)

            // Move new app into place
            try FileManager.default.moveItem(atPath: extractPath, toPath: appPath)

            // Create installed.version file for the new version
            createInstalledVersionFile()

            // Run migrations and clear caches for updated app
            runMigrationsAndClearCaches()

            // Cleanup
            try? FileManager.default.removeItem(atPath: extractPath)
            try? FileManager.default.removeItem(atPath: zipPath)

            // Keep only the latest backup
            cleanupOldBackups()

            print("✅ App update installed successfully")
            return true

        } catch {
            print("❌ Failed to install update: \(error)")

            // Cleanup on failure
            try? FileManager.default.removeItem(atPath: extractPath)
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

        for zipFile in zipFiles {
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

    func getAppVersion() -> String? {
        // Try to get version from installed.version file first (fastest)
        if let installedVersion = getInstalledVersion() {
            return installedVersion
        }

        // If app directory doesn't exist, assume DEBUG version
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

    private func getVersionFromEnvFile(_ envFilePath: String) -> String? {
        do {
            let envContent = try String(contentsOfFile: envFilePath, encoding: .utf8)
            // Look for NATIVEPHP_APP_VERSION=value
            let regex = try NSRegularExpression(pattern: "NATIVEPHP_APP_VERSION=(.+)")
            let range = NSRange(location: 0, length: envContent.utf16.count)

            if let match = regex.firstMatch(in: envContent, range: range) {
                let versionRange = Range(match.range(at: 1), in: envContent)!
                let version = String(envContent[versionRange]).trimmingCharacters(in: .whitespacesAndNewlines)
                // Remove surrounding quotes if present
                let cleanVersion = version.trimmingCharacters(in: CharacterSet(charactersIn: "\"'"))
                return cleanVersion
            }
        } catch {
            print("❌ Error reading .env file: \(error)")
        }

        return nil
    }

    private func shouldUpdateFromBundle() -> Bool {
        guard let bundledVersion = getBundledAppVersionFast() else {
            print("⚠️ Could not read version from bundled.version file, falling back to ZIP extraction")
            guard let bundledVersion = getBundledAppVersion() else {
                print("⚠️ Could not read version from bundled app.zip")
                return false
            }
            return shouldUpdateWithVersion(bundledVersion)
        }

        return shouldUpdateWithVersion(bundledVersion)
    }

    private func shouldUpdateWithVersion(_ bundledVersion: String) -> Bool {
        // Special case: If bundled version is DEBUG, always update from bundle (for development)
        if bundledVersion == "DEBUG" {
            print("🚧 DEBUG version detected, updating from bundle")
            return true
        }

        let currentVersion = getInstalledVersion() ?? getAppVersion()

        // If versions differ, update from bundle
        if currentVersion != bundledVersion {
            print("📦 Bundle version (\(bundledVersion)) differs from current (\(currentVersion ?? "none")), updating from bundle")
            return true
        }

        print("✅ App already up to date with bundle version (\(bundledVersion))")
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

        // Try to read .env file first
        if let envEntry = archive[".env"] {
            var envContent = Data()
            do {
                _ = try archive.extract(envEntry) { data in
                    envContent.append(data)
                }
                if let envString = String(data: envContent, encoding: .utf8) {
                    if let version = extractVersionFromEnv(envString) {
                        print("✅ Read version from .env without full extraction")
                        return version
                    }
                }
            } catch {
                print("⚠️ Failed to read .env from ZIP: \(error)")
            }
        }

        // Fallback: try .version file
        if let versionEntry = archive[".version"] {
            var versionContent = Data()
            do {
                _ = try archive.extract(versionEntry) { data in
                    versionContent.append(data)
                }
                if let versionString = String(data: versionContent, encoding: .utf8) {
                    print("✅ Read version from .version without full extraction")
                    return versionString.trimmingCharacters(in: .whitespacesAndNewlines)
                }
            } catch {
                print("⚠️ Failed to read .version from ZIP: \(error)")
            }
        }

        print("❌ No .env or .version found in ZIP")
        return nil
    }

    private func extractVersionFromEnv(_ envContent: String) -> String? {
        let lines = envContent.components(separatedBy: .newlines)
        for line in lines {
            if line.hasPrefix("NATIVEPHP_APP_VERSION=") {
                let version = String(line.dropFirst("NATIVEPHP_APP_VERSION=".count))
                let trimmedVersion = version.trimmingCharacters(in: .whitespacesAndNewlines)
                // Remove surrounding quotes if present
                let cleanVersion = trimmedVersion.trimmingCharacters(in: CharacterSet(charactersIn: "\"'"))
                return cleanVersion
            }
        }
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

    // MARK: - OTA Updates (Bifrost API)

    func checkForUpdates() {
        // Skip OTA checks for DEBUG version
        let currentVersion = getAppVersion() ?? "unknown"
        if currentVersion == "DEBUG" {
            print("🚧 DEBUG version detected, skipping OTA checks")
            return
        }

        // Get BIFROST_APP_ID from Laravel environment
        guard let bifrostAppId = getBifrostAppId() else {
            print("🔍 No BIFROST_APP_ID configured, skipping OTA checks")
            return
        }

        // Build Bifrost API URL
        let urlString = "https://bifrost.nativephp.com/api/app/\(bifrostAppId)/ota?installed=\(currentVersion)"
        guard let updateURL = URL(string: urlString) else {
            print("❌ Invalid update URL: \(urlString)")
            return
        }

        print("🔍 Checking for updates at: \(urlString)")

        var request = URLRequest(url: updateURL)
        request.timeoutInterval = 10.0 // 10 second timeout for check

        let task = URLSession.shared.dataTask(with: request) { [weak self] data, response, error in
            if let error = error {
                print("❌ Update check failed: \(error.localizedDescription)")
                return
            }

            guard let httpResponse = response as? HTTPURLResponse,
                  httpResponse.statusCode == 200,
                  let data = data else {
                print("❌ Invalid update response")
                return
            }

            // Parse Bifrost response
            do {
                if let updateInfo = try JSONSerialization.jsonObject(with: data) as? [String: Any] {
                    let upToDate = updateInfo["upToDate"] as? Bool ?? true

                    if !upToDate,
                       let downloadURL = updateInfo["download_url"] as? String,
                       let newVersion = updateInfo["current_version"] as? String {

                        // Check if this is a compatible version update (patch/minor only)
                        if self?.isCompatibleUpdate(from: currentVersion, to: newVersion) == true {
                            print("🆕 Compatible update available: \(currentVersion) → \(newVersion)")
                            self?.downloadUpdate(from: downloadURL, version: newVersion)
                        } else {
                            print("⚠️ Major version update detected (\(currentVersion) → \(newVersion)) - requires app store update")
                        }
                    } else {
                        print("📱 App is up to date")
                    }
                }
            } catch {
                print("❌ Failed to parse update info: \(error)")
            }
        }

        task.resume()
    }

    private func isCompatibleUpdate(from currentVersion: String, to newVersion: String) -> Bool {
        // Parse semver versions
        let currentSemver = parseSemver(currentVersion)
        let newSemver = parseSemver(newVersion)

        guard let current = currentSemver, let new = newSemver else {
            print("⚠️ Unable to parse semver versions: \(currentVersion) → \(newVersion)")
            // If we can't parse versions, allow the update (fallback behavior)
            return true
        }

        // Only allow patch and minor version updates (same major version)
        if new.major != current.major {
            print("❌ Major version change detected: \(current.major) → \(new.major)")
            return false
        }

        // Allow minor and patch updates
        if new.minor > current.minor || (new.minor == current.minor && new.patch > current.patch) {
            print("✅ Compatible update: \(currentVersion) → \(newVersion)")
            return true
        }

        // Don't allow downgrades
        print("⚠️ Version downgrade or same version: \(currentVersion) → \(newVersion)")
        return false
    }

    private func parseSemver(_ version: String) -> (major: Int, minor: Int, patch: Int)? {
        // Remove 'v' prefix if present
        let cleanVersion = version.hasPrefix("v") ? String(version.dropFirst()) : version

        // Split by dots and parse
        let parts = cleanVersion.components(separatedBy: ".")

        // Handle different semver formats
        if parts.count >= 3 {
            // Full semver: 1.2.3
            guard let major = Int(parts[0]),
                  let minor = Int(parts[1]),
                  let patch = Int(parts[2]) else {
                return nil
            }
            return (major, minor, patch)
        } else if parts.count == 2 {
            // Missing patch: 1.2 -> 1.2.0
            guard let major = Int(parts[0]),
                  let minor = Int(parts[1]) else {
                return nil
            }
            return (major, minor, 0)
        } else if parts.count == 1 {
            // Only major: 1 -> 1.0.0
            guard let major = Int(parts[0]) else {
                return nil
            }
            return (major, 0, 0)
        }

        return nil
    }

    private func getBifrostAppId() -> String? {
        // Read BIFROST_APP_ID from Info.plist
        return Bundle.main.object(forInfoDictionaryKey: "BIFROST_APP_ID") as? String
    }

    private func downloadUpdate(from urlString: String, version: String) {
        guard let url = URL(string: urlString) else {
            print("❌ Invalid download URL: \(urlString)")
            return
        }

        print("⬇️ Downloading update \(version) from: \(urlString)")

        var request = URLRequest(url: url)
        request.timeoutInterval = 30.0 // 30 second timeout for download

        let task = URLSession.shared.downloadTask(with: request) { [weak self] tempURL, response, error in
            if let error = error {
                print("❌ Download failed: \(error.localizedDescription)")
                return
            }

            guard let tempURL = tempURL else {
                print("❌ No download file received")
                return
            }

            // Move downloaded file to updates directory
            let filename = "update_\(version)_\(Int(Date().timeIntervalSince1970)).zip"
            let finalPath = (self?.updatesPath ?? "") + "/" + filename

            do {
                if FileManager.default.fileExists(atPath: finalPath) {
                    try FileManager.default.removeItem(atPath: finalPath)
                }
                try FileManager.default.moveItem(at: tempURL, to: URL(fileURLWithPath: finalPath))

                print("✅ Update downloaded: \(filename)")

                // Automatically install the update
                DispatchQueue.main.async {
                    if self?.installUpdate(from: finalPath) == true {
                        self?.notifyUpdateInstalled(version: version)
                    }
                }

            } catch {
                print("❌ Failed to save downloaded update: \(error)")
            }
        }

        task.resume()
    }

    private func notifyUpdateInstalled(version: String) {
        // Notify the Laravel app that an update was installed
        LaravelBridge.shared.send?(
            "Native\\Mobile\\Events\\App\\UpdateInstalled",
            ["version": version, "timestamp": Int(Date().timeIntervalSince1970)]
        )

        // Optionally show a toast or reload the WebView
        DispatchQueue.main.asyncAfter(deadline: .now() + 1.0) {
            NotificationCenter.default.post(name: .reloadWebViewNotification, object: nil)
        }
    }

    // MARK: - Fast Version Checking

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

        // Get the version from the extracted app's .env file
        if let version = getAppVersion() {
            do {
                try version.write(toFile: installedVersionPath, atomically: true, encoding: .utf8)
                print("📝 Created installed.version file: \(version)")
            } catch {
                print("❌ Failed to create installed.version file: \(error)")
            }
        } else {
            print("⚠️ Could not determine app version for installed.version file")
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
