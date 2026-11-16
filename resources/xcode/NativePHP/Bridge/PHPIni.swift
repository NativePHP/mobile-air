import Foundation

/// The php.ini every PHP context reads (through PHPRC). NativePHPApp writes it
/// before the bundle is extracted and PersistentPHPRuntime writes it again
/// before it boots; both go through here so the two can't drift apart.
enum PHPIni {

    /// Writes php.ini to Application Support and returns its path.
    static func write(caPath: String) -> String {
        let supportDir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        let iniPath = supportDir.appendingPathComponent("php.ini")

        try? FileManager.default.createDirectory(at: supportDir, withIntermediateDirectories: true)

        do {
            try contents(caPath: caPath).write(to: iniPath, atomically: true, encoding: .utf8)
        } catch {
            print("Couldn't create php.ini: \(error)")
        }

        return iniPath.path(percentEncoded: false)
    }

    // upload_max_filesize and post_max_size match the bridge's 16MB
    // response cap instead of PHP's 2MB/8MB defaults; BridgeDispatcher
    // applies them to request bodies the way PHP does. They go here, not
    // in the embed SAPI's ini_entries: php_embed_init() replaces those.
    //
    // memory_limit: 128M for every context. The persistent runtime, which
    // runs the native session, raises its own to 512M when it boots (see
    // raise_persistent_memory_limit in PHP.c).
    static func contents(caPath: String) -> String {
        """
        curl.cainfo="\(caPath)"
        openssl.cafile="\(caPath)"
        upload_max_filesize=16M
        post_max_size=16M
        memory_limit=128M
        \(opcacheSettings())
        """
    }

    // MARK: - OPcache

    /// Opcache's shared memory lives only as long as the process, so without
    /// a file cache every cold launch compiles all of Laravel again. The file
    /// cache keeps the compiled scripts on disk as a second level behind
    /// shared memory.
    ///
    /// Shared memory stays at PHP's default 128 MB: only the pages opcache
    /// writes to count toward the app's footprint, and 32, 64 and 128 MB
    /// measured the same. Laravel's interned strings outgrow the default 8 MB
    /// once the queue worker and async lanes have loaded their code. JIT
    /// stays off: iOS gives apps no writable and executable memory.
    ///
    /// In a release build the PHP files only change when a new bundle is
    /// extracted, and every extraction empties the file cache (see
    /// AppUpdateManager), so opcache doesn't need to stat each file it
    /// includes. Debug builds keep checking timestamps on every include,
    /// because hot reload rewrites files in place.
    static func opcacheSettings() -> String {
        var lines = [
            "opcache.enable=1",
            "opcache.memory_consumption=128",
            "opcache.interned_strings_buffer=16",
            "opcache.max_accelerated_files=4000",
            "opcache.jit=disable",
            "opcache.jit_buffer_size=0",
        ]

        if isRelease {
            lines.append("opcache.validate_timestamps=0")
        } else {
            lines.append("opcache.validate_timestamps=1")
            lines.append("opcache.revalidate_freq=0")
        }

        if fileCacheEnabled, let dir = prepareFileCacheDirectory() {
            lines.append("opcache.file_cache=\"\(dir.path(percentEncoded: false))\"")
            // With timestamp checks off, nothing else would notice a damaged
            // .bin, and the app would crash on it every launch until the next
            // update. The checksum costs a few milliseconds per launch.
            lines.append("opcache.file_cache_consistency_checks=1")
        }

        return lines.joined(separator: "\n")
    }

    /// Library/Caches/opcache. Caches isn't backed up, and iOS only purges it
    /// while the app isn't running; opcache just recompiles what is missing.
    static var fileCacheDirectory: URL {
        FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first!
            .appendingPathComponent("opcache", isDirectory: true)
    }

    /// Throws away every compiled script. Must run whenever the app's PHP
    /// files are replaced, before any PHP runs against the new files: with
    /// timestamp checks off, a stale entry would otherwise keep running the
    /// previous release's code.
    static func clearFileCache() {
        let dir = fileCacheDirectory

        guard FileManager.default.fileExists(atPath: dir.path(percentEncoded: false)) else { return }

        do {
            try FileManager.default.removeItem(at: dir)
            print("🧹 Cleared the opcache file cache")
        } catch {
            print("❌ Could not clear the opcache file cache: \(error)")
        }

        _ = prepareFileCacheDirectory()
    }

    /// Opcache turns the file cache off at startup when the directory is
    /// missing, so it has to exist before PHP boots.
    private static func prepareFileCacheDirectory() -> URL? {
        let dir = fileCacheDirectory

        do {
            try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
            return dir
        } catch {
            print("❌ Could not create the opcache file cache directory: \(error)")
            return nil
        }
    }

    /// `nativephp.opcache.file_cache` from the app's config, baked into
    /// bundle_meta.json at build time. On unless the app turns it off.
    static var fileCacheEnabled: Bool {
        (bundleMeta?["opcache_file_cache"] as? Bool) ?? true
    }

    /// A release build of a versioned bundle. native:run's DEBUG bundle is
    /// re-extracted on every launch and the Debug configuration runs the hot
    /// reload server, so either one keeps timestamp checks on.
    static var isRelease: Bool {
        #if DEBUG
        return false
        #else
        guard let version = bundleMeta?["version"] as? String else { return false }
        return version.caseInsensitiveCompare("DEBUG") != .orderedSame
        #endif
    }

    private static let bundleMeta: [String: Any]? = {
        guard let path = Bundle.main.path(forResource: "bundle_meta", ofType: "json"),
              let data = FileManager.default.contents(atPath: path)
        else { return nil }

        return try? JSONSerialization.jsonObject(with: data) as? [String: Any]
    }()
}
