package com.nativephp.mobile.bridge

import android.annotation.SuppressLint
import android.content.Context
import android.util.Log
import java.io.File
import java.io.FileOutputStream
import java.io.FileInputStream
import java.io.BufferedInputStream
import java.util.zip.ZipEntry
import java.util.zip.ZipInputStream
import org.json.JSONObject
import java.util.concurrent.locks.ReentrantLock
import kotlin.concurrent.withLock

class LaravelEnvironment(private val context: Context) {
    private val appStorageDir = context.getDir("storage", Context.MODE_PRIVATE)
    private val phpBridge = PHPBridge(context)

    // Cached bundle metadata to avoid reading ZIP multiple times
    private var bundleMetadataCache: BundleMetadata? = null

    private external fun nativeSetEnv(name: String, value: String, overwrite: Int): Int

    // Data class to hold bundle metadata read from ZIP
    private data class BundleMetadata(
        val version: String?,
        val versionCode: String?,
        val bifrostAppId: String?,
        val runtimeMode: String?
    )

    companion object {
        // Process-wide lock around Laravel bundle extraction. MainActivity and
        // PHPSchedulerWorker each construct their own LaravelEnvironment, so an
        // instance-level lock wouldn't serialize them. Without this, an updated
        // APK + queued WorkManager job can run an ephemeral PHP task against a
        // mid-delete / mid-extract vendor/ tree and fail with
        // `Class "Native\Mobile\Runtime" not found`.
        //
        // Internal (not private): PHPBridge.bootPersistentRuntime takes this
        // same lock so the persistent php_embed_init can never overlap the
        // classic embed init/shutdown cycles of runBaseArtisanCommands from a
        // concurrently-created activity — the two paths use different native
        // mutexes, and a classic php_embed_shutdown mid-boot guts the
        // persistent interpreter's module/class state (boots "in 13ms", then
        // every dispatch 500s with `Class "Native\Mobile\Runtime" not found`).
        internal val extractionLock = ReentrantLock()

        // Classic (embed-per-command) artisan cannot run a second time in a
        // process where the persistent PHP runtime has been shut down — the
        // TSRM re-init SEGVs in ts_resource_ex. That happens when an activity
        // is re-created in a process a plugin foreground service kept alive.
        // The base commands are idempotent per install, so run them at most
        // once per process; the re-created activity skips straight to the
        // persistent boot (the same shutdown→boot cycle hot reload already
        // exercises safely).
        @Volatile private var baseArtisanRanThisProcess = false

        private const val TAG = "LaravelEnvironment"

        // File and directory names
        private const val BUNDLE_ZIP = "laravel_bundle.zip"
        private const val BUNDLE_META = "bundle_meta.json"
        private const val OTA_MARKER = ".ota_applied"
        private const val VERSION_FILE = ".version"
        private const val ENV_FILE = ".env"
        private const val CACERT_FILE = "cacert.pem"
        private const val PHP_INI_FILE = "php.ini"
        private const val APP_KEY_FILE = "persisted_data/appkey.txt"

        // Directory paths
        private const val DIR_LARAVEL = "laravel"
        private const val DIR_UPDATES = "updates"
        private const val DIR_PERSISTED = "persisted_data"
        private const val DIR_STORAGE = "persisted_data/storage"
        private const val DIR_FRAMEWORK = "persisted_data/storage/framework"
        private const val DIR_VIEWS = "persisted_data/storage/framework/views"
        private const val DIR_SESSIONS = "persisted_data/storage/framework/sessions"
        private const val DIR_CACHE = "persisted_data/storage/framework/cache"
        private const val DIR_LOGS = "persisted_data/storage/logs"
        private const val DIR_APP = "persisted_data/storage/app"
        private const val DIR_PUBLIC = "persisted_data/storage/app/public"
        private const val DIR_DATABASE = "persisted_data/database/"
        private const val DIR_PHP_SESSIONS = "php_sessions"

        // Version constants
        private const val VERSION_DEBUG = "DEBUG"

        // Environment variable regex patterns
        private const val REGEX_APP_VERSION = "(?m)^NATIVEPHP_APP_VERSION=(.+)$"
        private const val REGEX_APP_VERSION_CODE = "(?m)^NATIVEPHP_APP_VERSION_CODE=(.+)$"
        private const val REGEX_BIFROST_ID = "BIFROST_APP_ID=(.+)"

        /**
         * Build the composite identity ("version+b+versionCode" or "DEBUG") used to
         * decide whether the embedded Laravel bundle needs to be re-extracted.
         */
        private fun buildVersionId(version: String?, versionCode: String?): String? {
            if (version == null) return null
            val cleanVersion = version.trim().trim('"').trim('\'')
            if (cleanVersion.equals(VERSION_DEBUG, ignoreCase = true)) {
                return VERSION_DEBUG
            }
            val cleanCode = versionCode?.trim()?.trim('"')?.trim('\'') ?: "0"
            return "${cleanVersion}b${cleanCode}"
        }

        init {
            System.loadLibrary("php_wrapper")
        }

        /**
         * Read runtime_mode from bundle_meta.json. Returns "persistent" (default) or "classic".
         */
        fun getRuntimeMode(context: Context): String {
            return try {
                val json = context.assets.open(BUNDLE_META).bufferedReader().use { it.readText() }
                val obj = JSONObject(json)
                if (obj.has("runtime_mode") && !obj.isNull("runtime_mode")) {
                    obj.getString("runtime_mode")
                } else {
                    "persistent"
                }
            } catch (e: Exception) {
                "persistent"
            }
        }

        /**
         * Read NATIVEPHP_START_URL from the extracted .env file
         */
        fun getStartURL(context: Context): String {
            val appStorageDir = context.getDir("storage", Context.MODE_PRIVATE)
            val laravelDir = File(appStorageDir, "laravel")
            val envFile = File(laravelDir, ".env")

            if (!envFile.exists()) {
                Log.d(TAG, "⚙️ No .env file found, using default start URL")
                return "/"
            }

            try {
                val envContent = envFile.readText()
                val pattern = Regex("""NATIVEPHP_START_URL\s*=\s*([^\r\n]+)""")
                val match = pattern.find(envContent)

                if (match != null) {
                    var value = match.groupValues[1]
                        .trim()
                        .trim('"', '\'')

                    if (value.isNotEmpty()) {
                        // Ensure path starts with /
                        if (!value.startsWith("/")) {
                            value = "/$value"
                        }
                        Log.d(TAG, "⚙️ Found start URL in .env: $value")
                        return value
                    }
                }
            } catch (e: Exception) {
                Log.e(TAG, "⚠️ Error reading .env file", e)
            }

            Log.d(TAG, "⚙️ No NATIVEPHP_START_URL found, using default: /")
            return "/"
        }
    }

    fun initialize() {
        try {
            // Process reuse: a live/parked persistent runtime means this
            // process was started by the current APK install (a new install
            // always kills the process), so the extracted tree is already
            // this build's. Re-extracting would rm -rf vendor/ + views
            // UNDER the running PHP runtime, poisoning its realpath/stat
            // caches — the next request dies with "PHP Startup: stat
            // failed" on files that exist on disk. Skip entirely.
            if (phpBridge.isPersistentMode()) {
                Log.d(TAG, "⚡ Persistent runtime alive — skipping bundle extraction (process reuse)")
                return
            }

            setupDirectories()

            // OTA check/download lives in the mobile-ota plugin. Core only
            // applies pending zips from {appStorageDir}/updates on boot.

            // Hold the lock across extraction AND the post-extraction steps
            // (.env writes + classic artisan). A second activity's init thread
            // otherwise unblocks after the extraction alone, skips artisan via
            // baseArtisanRanThisProcess, and boots the persistent runtime
            // while THIS thread is still cycling classic embeds — see the
            // extractionLock comment for the failure that causes.
            extractionLock.withLock {
                val didBundle = extractLaravelBundleUnlocked()
                val didPending = applyPendingUpdatesUnlocked()
                val didExtract = didBundle || didPending

                setupEnvironment(didExtract)

                // Only run artisan commands when files were actually extracted/changed
                if (didExtract) {
                    Log.d(TAG, "📦 Running post-extraction artisan commands...")
                    runBaseArtisanCommands()
                } else {
                    Log.d(TAG, "⚡ Skipping artisan commands — no extraction needed")
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error initializing Laravel environment", e)
            throw RuntimeException("Failed to initialize Laravel environment", e)
        }
    }

    /**
     * Extract Laravel bundle if needed. Returns true if extraction was performed.
     * Serialized process-wide via extractionLock so MainActivity's init thread and
     * a WorkManager worker can't clobber each other mid-extract. Safe to call from
     * either path; the isUpToDate check inside short-circuits repeat callers.
     */
    private fun extractLaravelBundle(): Boolean = extractionLock.withLock {
        val didBundle = extractLaravelBundleUnlocked()
        val didPending = applyPendingUpdatesUnlocked()
        didBundle || didPending
    }

    private fun extractLaravelBundleUnlocked(): Boolean {
        val laravelDir = File(appStorageDir, DIR_LARAVEL)
        val otaMarkerFile = File(laravelDir, OTA_MARKER)

        // .ota_applied records the bundled identity the pending zip was applied over.
        // Same identity → keep the OTA tree (do not re-extract the older bundled zip).
        // Different identity or DEBUG → store build changed; roll back to the bundle.
        if (otaMarkerFile.exists()) {
            val recordedBundledId = otaMarkerFile.readText().trim()
            val markerMeta = readBundleMetadata()
            val embeddedId = buildVersionId(markerMeta.version, markerMeta.versionCode)
            val isDebug = embeddedId?.equals(VERSION_DEBUG, ignoreCase = true) == true

            if (isDebug || (embeddedId != null && embeddedId != recordedBundledId)) {
                Log.d(TAG, "🔄 Bundled identity changed ($recordedBundledId → ${embeddedId ?: "none"}); rolling back OTA to bundle")
                otaMarkerFile.delete()
            } else {
                Log.d(TAG, "✅ OTA update is active (applied over $recordedBundledId), skipping bundle extraction")
                return false
            }
        }

        // Build composite "version+b+versionCode" identity from bundle metadata.
        // This is what we compare against the extracted .env so a build-number-only
        // bump still triggers re-extraction.
        val bundleMeta = readBundleMetadata()
        val embeddedId = buildVersionId(bundleMeta.version, bundleMeta.versionCode)

        if (embeddedId == null) {
            Log.e(TAG, "❌ Couldn't read version from laravel_bundle.zip")
            return false
        }

        Log.d(TAG, "🔍 DEBUG: embeddedId from bundle = '$embeddedId'")

        // Identity of what's currently extracted. The .version marker written
        // after extraction is authoritative — it records the embedded composite
        // verbatim. Recomputing from the extracted .env is only a legacy
        // fallback, and it MUST NOT be preferred: .env carries no
        // NATIVEPHP_APP_VERSION_CODE line, so the recompute yields "…b0"
        // against bundle_meta.json's "…b1" and the app re-extracts the whole
        // bundle on EVERY cold boot — several seconds of splash each launch.
        val currentId = if (laravelDir.exists()) {
            val versionFile = File(laravelDir, VERSION_FILE)
            val envFile = File(laravelDir, ENV_FILE)
            if (versionFile.exists()) {
                versionFile.readText().trim().ifEmpty { null }
            } else if (envFile.exists()) {
                buildVersionId(getVersionFromEnvFile(envFile), getVersionCodeFromEnvFile(envFile))
            } else {
                null
            }
        } else {
            null
        }

        Log.d(TAG, "🔍 DEBUG: currentId = '${currentId ?: "none"}'")

        // If DEBUG mode, ALWAYS extract. Otherwise, only extract if composites don't match.
        val isDebug = embeddedId.equals(VERSION_DEBUG, ignoreCase = true)
        val isUpToDate = currentId == embeddedId
        val shouldExtract = isDebug || !isUpToDate

        Log.d(TAG, "🔍 DEBUG: isUpToDate = $isUpToDate, isDebug = $isDebug, shouldExtract = $shouldExtract")

        if (!shouldExtract) {
            Log.d(TAG, "✅ Laravel already up to date (id $embeddedId)")
            return false
        }

        Log.d(TAG, "📦 Extracting Laravel bundle — current: ${currentId ?: "none"}, embedded: $embeddedId")

        // Delete entire laravel directory - persisted_data is separate and safe
        if (laravelDir.exists()) {
            // Check for symlinks before deletion
            val laravelStorage = File(laravelDir, "storage")

            Log.d(TAG, "🗑️ CALLING BASH RM NOW...")
            // WORKAROUND: Kotlin's deleteRecursively() has a bug that deletes persisted_data!
            // Use system rm command instead
            try {
                val process = Runtime.getRuntime().exec(arrayOf("rm", "-rf", laravelDir.absolutePath))
                process.waitFor()
                Log.d(TAG, "✅ BASH RM COMPLETED (exit code: ${process.exitValue()})")
            } catch (e: Exception) {
                Log.e(TAG, "❌ BASH RM FAILED: ${e.message}")
                // Fallback - try to delete what we can
                laravelDir.listFiles()?.forEach { it.delete() }
            }
        }

        laravelDir.mkdirs()

        try {
            val zipStream = context.assets.open(BUNDLE_ZIP)
            unzip(zipStream, laravelDir)

            // Remove OTA marker if it exists (we're back to bundled version)
            if (otaMarkerFile.exists()) {
                otaMarkerFile.delete()
            }

            // Record WHAT WAS JUST EXTRACTED: the embedded composite, verbatim.
            // Recomputing from the extracted .env loses the version code (no
            // NATIVEPHP_APP_VERSION_CODE line) and wrote "…b0" here while the
            // staleness check compared against "…b1" — a permanent
            // re-extraction loop.
            File(laravelDir, VERSION_FILE).writeText(embeddedId)
            Log.d(TAG, "✅ Updated .version file to: $embeddedId")

            Log.d(TAG, "✅ Extraction complete to ${laravelDir.absolutePath}")

            // Create storage structure for hot reload compatibility
            // Even though we use persisted_data/storage, hot reload needs laravel/storage/framework to exist
            val laravelStorageFramework = File(laravelDir, "storage/framework")
            laravelStorageFramework.mkdirs()
            Log.d(TAG, "✅ Created laravel/storage/framework for hot reload")

            // Create bootstrap/cache directory (required for Laravel's cache operations)
            val bootstrapCache = File(laravelDir, "bootstrap/cache")
            bootstrapCache.mkdirs()
            Log.d(TAG, "✅ Created laravel/bootstrap/cache for Laravel cache operations")
        } catch (e: Exception) {
            Log.e(TAG, "❌ Failed to extract Laravel zip", e)
        }

        return true
    }

    /**
     * Read bundle metadata from bundle_meta.json (fast path) or ZIP scan (fallback).
     * Results are cached to avoid redundant reads.
     */
    private fun readBundleMetadata(): BundleMetadata {
        // Return cached value if available
        bundleMetadataCache?.let { return it }

        // Fast path: read pre-built metadata file (written at build time)
        try {
            val json = context.assets.open(BUNDLE_META).bufferedReader().use { it.readText() }
            val obj = JSONObject(json)
            val version = if (obj.has("version")) obj.getString("version") else null
            val versionCode = when {
                !obj.has("version_code") || obj.isNull("version_code") -> null
                else -> obj.get("version_code").toString()
            }
            val bifrostAppId = if (obj.has("bifrost_app_id") && !obj.isNull("bifrost_app_id")) obj.getString("bifrost_app_id") else null
            val runtimeMode = if (obj.has("runtime_mode") && !obj.isNull("runtime_mode")) obj.getString("runtime_mode") else null
            Log.d(TAG, "⚡ Read bundle_meta.json: version=$version, version_code=$versionCode, bifrost=$bifrostAppId, runtime_mode=$runtimeMode")
            val metadata = BundleMetadata(version, versionCode, bifrostAppId, runtimeMode)
            bundleMetadataCache = metadata
            return metadata
        } catch (e: Exception) {
            Log.d(TAG, "bundle_meta.json not found, falling back to ZIP scan")
        }

        // Slow fallback: scan ZIP for .env and .version
        var version: String? = null
        var versionCode: String? = null
        var bifrostAppId: String? = null
        var versionIdFromVersionFile: String? = null

        try {
            val zis = ZipInputStream(context.assets.open(BUNDLE_ZIP) as java.io.InputStream)
            var entry: ZipEntry?

            while (zis.nextEntry.also { entry = it } != null) {
                when (entry?.name) {
                    ENV_FILE -> {
                        val envContent = zis.bufferedReader().readText()
                        version = Regex(REGEX_APP_VERSION).find(envContent)?.groupValues?.get(1)?.trim()
                        versionCode = Regex(REGEX_APP_VERSION_CODE).find(envContent)?.groupValues?.get(1)?.trim()
                        bifrostAppId = Regex(REGEX_BIFROST_ID).find(envContent)?.groupValues?.get(1)?.trim()
                    }
                    VERSION_FILE -> {
                        // .version contains the composite id (e.g. "1.0.0b42") used as a fallback
                        // when .env can't be parsed.
                        versionIdFromVersionFile = zis.bufferedReader().readText().trim()
                    }
                }
            }
            zis.close()
        } catch (e: Exception) {
            Log.e(TAG, "Failed to read bundle metadata", e)
        }

        // If .env didn't yield a version but .version did, decompose the composite back
        // into version + versionCode so the metadata shape stays consistent.
        if (version == null && versionIdFromVersionFile != null) {
            val (parsedVersion, parsedCode) = parseVersionId(versionIdFromVersionFile)
            version = parsedVersion
            versionCode = parsedCode
        }

        val metadata = BundleMetadata(version, versionCode, bifrostAppId, null)
        bundleMetadataCache = metadata
        return metadata
    }

    /**
     * Decompose a composite version id ("1.0.0b42" or "DEBUG") back into its parts.
     * Used when .version is the only metadata source available.
     */
    private fun parseVersionId(id: String): Pair<String?, String?> {
        if (id.equals(VERSION_DEBUG, ignoreCase = true)) {
            return Pair(VERSION_DEBUG, null)
        }
        val sepIndex = id.lastIndexOf('b')
        if (sepIndex <= 0 || sepIndex == id.length - 1) {
            return Pair(id, null)
        }
        return Pair(id.substring(0, sepIndex), id.substring(sepIndex + 1))
    }

    private fun getVersionFromEnvFile(envFile: File): String? {
        return try {
            val envContent = envFile.readText()
            Regex(REGEX_APP_VERSION).find(envContent)?.groupValues?.get(1)?.trim()
        } catch (e: Exception) {
            Log.e(TAG, "Failed to read version from .env file", e)
            null
        }
    }

    private fun getVersionCodeFromEnvFile(envFile: File): String? {
        return try {
            val envContent = envFile.readText()
            Regex(REGEX_APP_VERSION_CODE).find(envContent)?.groupValues?.get(1)?.trim()
        } catch (e: Exception) {
            Log.e(TAG, "Failed to read version code from .env file", e)
            null
        }
    }

    /**
     * Next-boot OTA apply: any *.zip in {appStorageDir}/updates/ is extracted
     * into laravel (same unzip as the bundled payload). One zip per boot.
     * The running .env is stashed and restored verbatim; no key merge.
     */
    private fun applyPendingUpdatesUnlocked(): Boolean {
        val updatesDir = File(appStorageDir, DIR_UPDATES)
        if (!updatesDir.exists()) return false

        val zipFiles = updatesDir.listFiles { file ->
            file.isFile && file.name.endsWith(".zip", ignoreCase = true)
        } ?: return false

        if (zipFiles.isEmpty()) return false

        val zipFile = zipFiles.firstOrNull { it.name == "pending.zip" } ?: zipFiles[0]
        return installPendingUpdate(zipFile)
    }

    private fun installPendingUpdate(zipFile: File): Boolean {
        val laravelDir = File(appStorageDir, DIR_LARAVEL)
        val envFile = File(laravelDir, ENV_FILE)
        val stashFile = File(appStorageDir, "$DIR_UPDATES/.env.stash")

        return try {
            var hadEnv = false
            if (envFile.exists()) {
                stashFile.parentFile?.mkdirs()
                envFile.copyTo(stashFile, overwrite = true)
                hadEnv = true
                Log.d(TAG, "📦 Stashed existing .env before pending update")
            }

            if (laravelDir.exists()) {
                Log.d(TAG, "🗑️ Removing Laravel directory for pending OTA (persisted_data is safe)")
                try {
                    val process = Runtime.getRuntime().exec(arrayOf("rm", "-rf", laravelDir.absolutePath))
                    process.waitFor()
                } catch (e: Exception) {
                    Log.e(TAG, "❌ Failed to remove Laravel directory: ${e.message}")
                    laravelDir.listFiles()?.forEach { it.delete() }
                }
            }
            laravelDir.mkdirs()

            FileInputStream(zipFile).use { unzip(it, laravelDir) }

            if (hadEnv && stashFile.exists()) {
                stashFile.copyTo(File(laravelDir, ENV_FILE), overwrite = true)
                stashFile.delete()
                Log.d(TAG, "📦 Restored stashed .env over extracted payload")
            }

            // Record the bundled identity this OTA was applied over so the next
            // boot skips re-extracting the older bundle, while a newer store
            // build (different embedded id) still rolls back to the bundle.
            val bundledId = buildVersionId(
                readBundleMetadata().version,
                readBundleMetadata().versionCode
            ) ?: "ota"
            File(laravelDir, OTA_MARKER).writeText(bundledId)

            File(laravelDir, "storage/framework").mkdirs()
            File(laravelDir, "bootstrap/cache").mkdirs()

            zipFile.delete()

            Log.d(TAG, "✅ Pending OTA zip applied; .ota_applied=$bundledId")
            true
        } catch (e: Exception) {
            Log.e(TAG, "❌ Failed to apply pending OTA zip", e)
            if (stashFile.exists()) {
                stashFile.delete()
            }
            false
        }
    }

    private fun unzip(inputStream: java.io.InputStream, destinationDir: File) {
        val buffer = ByteArray(65536)  // 64KB buffer
        val zis = ZipInputStream(BufferedInputStream(inputStream))

        var ze: ZipEntry? = zis.nextEntry
        while (ze != null) {
            // Skip storage directory - we use persisted_data/storage instead
            if (ze.name.startsWith("storage/") || ze.name == "storage") {
                Log.d(TAG, "⏭️ Skipping storage directory from bundle: ${ze.name}")
                zis.closeEntry()
                ze = zis.nextEntry
                continue
            }

            val file = File(destinationDir, ze.name)

            if (ze.isDirectory) {
                file.mkdirs()
            } else {
                // Stream directly to disk instead of buffering in memory
                file.parentFile?.mkdirs()
                FileOutputStream(file).use { fos ->
                    var count: Int
                    while (zis.read(buffer).also { count = it } != -1) {
                        fos.write(buffer, 0, count)
                    }
                }
            }
            zis.closeEntry()
            ze = zis.nextEntry
        }
        zis.close()
    }

    private fun copyAssetToInternalStorage(assetName: String, targetFileName: String, forceUpdate: Boolean = false): File {
        val outFile = File(context.filesDir, targetFileName)

        if (!outFile.exists()) {
            // File doesn't exist, copy it
            Log.d(TAG, "📋 Copying asset $assetName to ${outFile.absolutePath} (new file)")
            copyAssetFile(assetName, outFile)
        } else if (forceUpdate) {
            // Forced refresh (DEBUG build, or the Laravel bundle was just
            // re-extracted for an app update), copy without checksum verification
            Log.d(TAG, "📋 Force updating asset $assetName")
            copyAssetFile(assetName, outFile)
        } else {
            // File exists and no forced refresh — trust it. This asset only changes
            // with an app update, which re-extracts the bundle and forces a refresh
            // above. Avoids MD5-hashing two ~200KB streams on every cold boot.
            Log.d(TAG, "📋 Asset $assetName present — skipping (no forced refresh)")
        }

        return outFile
    }

    @Synchronized
    private fun copyAssetFile(assetName: String, outFile: File) {
        try {
            context.assets.open(assetName).use { input ->
                FileOutputStream(outFile).use { output ->
                    input.copyTo(output)
                }
            }
            Log.d(TAG, "✅ Successfully copied $assetName")
        } catch (e: Exception) {
            Log.e(TAG, "❌ Failed to copy asset $assetName", e)
            throw e
        }
    }

    private fun runBaseArtisanCommands() {
        if (baseArtisanRanThisProcess) {
            Log.d(TAG, "⚡ Base artisan already ran in this process — skipping (classic embed can't re-init after persistent shutdown)")
            return
        }
        baseArtisanRanThisProcess = true

        val dbFile = File(appStorageDir, "persisted_data/database/database.sqlite")
        if (!dbFile.exists()) {
            Log.d(TAG, "📄 Creating empty SQLite file: ${dbFile.absolutePath}")
            dbFile.createNewFile()
        } else {
            Log.d(TAG, "✅ SQLite file already exists: ${dbFile.absolutePath}")
        }

        File(appStorageDir, "persisted_data/storage/app/public")
        phpBridge.runArtisanCommand("optimize:clear")
        phpBridge.runArtisanCommand("storage:unlink")
        phpBridge.runArtisanCommand("storage:link")
        phpBridge.runArtisanCommand("migrate --force")

        // Cache the Laravel bootstrap so every subsequent cold boot skips config
        // parsing, event discovery, and Blade compilation. Built HERE — once per app
        // update, with the device's real paths — rather than at build time on the host
        // (where the cached paths would be wrong, which is why we optimize:clear above
        // first). This is the biggest Laravel-side cold-start lever; view:cache in
        // particular precompiles every Blade view so the first page render doesn't have
        // to. `route:cache` is intentionally omitted — NativePHP registers internal
        // closure routes (e.g. /_native/api/events) that can't be serialized.
        phpBridge.runArtisanCommand("config:cache")
        phpBridge.runArtisanCommand("event:cache")
        phpBridge.runArtisanCommand("view:cache")
    }

    private fun setupDirectories() {
        try {
            // Create directories with permissions as needed
            createDirectory(DIR_FRAMEWORK, withPermissions = true)
            createDirectory(DIR_VIEWS)
            createDirectory(DIR_SESSIONS, withPermissions = true)
            createDirectory(DIR_CACHE)
            createDirectory(DIR_LOGS)
            createDirectory(DIR_APP)
            createDirectory(DIR_PUBLIC)
            createDirectory(DIR_DATABASE)

            // Set permissions on parent storage directory (owner-only)
            File(appStorageDir, DIR_STORAGE).setWritable(true, true)

        } catch (e: Exception) {
            Log.e(TAG, "Failed to create directories", e)
            throw e
        }
    }

    private fun setupEnvironment(forceCertRefresh: Boolean = false) {
        try {
            val appKeyFile = File(appStorageDir, APP_KEY_FILE)
            val appKey: String = if (appKeyFile.exists()) {
                val contents = appKeyFile.readText().trim()
                if (contents.startsWith("base64:")) {
                    Log.d(TAG, "✅ Found valid APP_KEY in file")
                    contents
                } else {
                    Log.w(TAG, "⚠️ Found invalid APP_KEY in file, regenerating...")
                    appKeyFile.delete()
                    generateAndSaveAppKey(appKeyFile)
                }
            } else {
                generateAndSaveAppKey(appKeyFile)
            }

            // Set all environment variables in batches for better performance
            setEnvironmentVariables(
                "APP_KEY" to appKey,
                // Core Laravel paths
                "DOCUMENT_ROOT" to "${appStorageDir.absolutePath}/laravel",
                "LARAVEL_BASE_PATH" to "${appStorageDir.absolutePath}/laravel",
                "COMPOSER_VENDOR_DIR" to "${appStorageDir.absolutePath}/laravel/vendor",
                "COMPOSER_AUTOLOADER_PATH" to "${appStorageDir.absolutePath}/laravel/vendor/autoload.php",
                // Laravel storage paths
                "LARAVEL_STORAGE_PATH" to "${appStorageDir.absolutePath}/persisted_data/storage",
                "LARAVEL_BOOTSTRAP_PATH" to "${appStorageDir.absolutePath}/laravel/bootstrap",
                "VIEW_COMPILED_PATH" to "${appStorageDir.absolutePath}/persisted_data/storage/framework/views",
                "CACHE_PATH" to "${appStorageDir.absolutePath}/persisted_data/storage/framework/cache"
            )

            setEnvironmentVariables(
                // Laravel environment settings
                "APP_URL" to "http://127.0.0.1",
                "ASSET_URL" to "http://127.0.0.1/_assets",
                "DB_CONNECTION" to "sqlite",
                "DB_DATABASE" to "${appStorageDir.absolutePath}/persisted_data/database/database.sqlite",
                "CACHE_DRIVER" to "file",
                "CACHE_STORE" to "file",
                "QUEUE_CONNECTION" to "database",
                "NATIVEPHP_PLATFORM" to "android",
                "NATIVEPHP_TEMPDIR" to context.cacheDir.absolutePath
            )

            setEnvironmentVariables(
                // Cookie settings
                "COOKIE_PATH" to "/",
                "COOKIE_DOMAIN" to "127.0.0.1",
                "COOKIE_SECURE" to "false",
                "COOKIE_HTTP_ONLY" to "true",
                // Session settings
                "SESSION_DRIVER" to "file",
                "SESSION_DOMAIN" to "127.0.0.1",
                "SESSION_SECURE_COOKIE" to "false",
                "SESSION_HTTP_ONLY" to "true",
                "SESSION_SAME_SITE" to "lax"
            )

            setEnvironmentVariables(
                // PHP paths and settings
                "PHP_INI_SCAN_DIR" to appStorageDir.absolutePath,
                "CA_CERT_DIR" to context.filesDir.absolutePath,
                "PHPRC" to context.filesDir.absolutePath,
                // PHP/Server environment
                "REMOTE_ADDR" to "127.0.0.1",
                "SERVER_NAME" to "127.0.0.1",
                "SERVER_PORT" to "80",
                "SERVER_PROTOCOL" to "HTTP/1.1",
                "REQUEST_SCHEME" to "http"
            )

            Log.d(TAG, "✅ Environment variables configured")

            val phpSessionDir = File(appStorageDir, DIR_PHP_SESSIONS).apply {
                mkdirs()
                setReadable(true, true)
                setWritable(true, true)
                setExecutable(true, true)
            }
            setEnvironmentVariable("SESSION_SAVE_PATH", phpSessionDir.absolutePath)
            Log.d(TAG, "PHP session path set to: ${phpSessionDir.absolutePath}")

            try {
                // Check if we're in DEBUG mode to force certificate refresh
                val isDebugMode = try {
                    val versionFile = File(appStorageDir, "$DIR_LARAVEL/$VERSION_FILE")
                    versionFile.exists() && versionFile.readText().trim() == VERSION_DEBUG
                } catch (e: Exception) {
                    false
                }

                Log.d(TAG, "🔍 Certificate copy - DEBUG mode: $isDebugMode")
                // Force a refresh in DEBUG, or when the Laravel bundle was just
                // re-extracted (app update). Otherwise trust the existing copy —
                // see copyAssetToInternalStorage — so we don't MD5 two ~200KB
                // streams on every cold boot.
                copyAssetToInternalStorage(CACERT_FILE, CACERT_FILE, forceUpdate = isDebugMode || forceCertRefresh)

                val phpIni = """
curl.cainfo="${context.filesDir.absolutePath}/$CACERT_FILE"
openssl.cafile="${context.filesDir.absolutePath}/$CACERT_FILE"
"""
                File(context.filesDir, PHP_INI_FILE).writeText(phpIni)
                Log.d(TAG, "✅ PHP ini configured with certificate path")
            } catch (e: Exception) {
                Log.e(TAG, "❌ Failed to copy or set CURL_CA_BUNDLE", e)
            }

        } catch (e: Exception) {
            Log.e(TAG, "Failed to setup environment", e)
            throw e
        }
    }

    // APP_KEY is just 32 random bytes, base64-encoded — generate it locally
    // instead of booting PHP just to run key:generate (matches iOS).
    private fun generateAndSaveAppKey(file: File): String {
        val keyBytes = ByteArray(32)
        java.security.SecureRandom().nextBytes(keyBytes)
        val generatedKey = "base64:" + android.util.Base64.encodeToString(keyBytes, android.util.Base64.NO_WRAP)

        file.parentFile?.mkdirs()
        file.writeText(generatedKey)

        Log.d(TAG, "🔐 Generated and stored new APP_KEY locally (no PHP boot)")
        return generatedKey
    }

    private fun setEnvironmentVariable(name: String, value: String) {
        try {
            val result = nativeSetEnv(name, value, 1)
            if (result != 0) {
                throw RuntimeException("Failed to set environment variable: $name")
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to set environment variable: $name", e)
            throw e
        }
    }

    /**
     * Set multiple environment variables at once
     * More efficient than individual calls due to reduced JNI overhead
     */
    private fun setEnvironmentVariables(vararg pairs: Pair<String, String>) {
        for ((name, value) in pairs) {
            setEnvironmentVariable(name, value)
        }
    }

    private fun createDirectory(path: String, withPermissions: Boolean = false) {
        val dir = File(appStorageDir, path)

        // Skip if already exists
        if (dir.exists()) return

        dir.mkdirs()

        // Set owner-only permissions if requested
        if (withPermissions) {
            dir.setReadable(true, true)
            dir.setWritable(true, true)
            dir.setExecutable(true, true)
        }
    }

    /**
     * Lightweight initialization for background execution (WorkManager).
     * Sets environment variables and ensures directories exist.
     * Skips bundle extraction and artisan commands — those are done at install time.
     */
    fun initializeForBackground() {
        try {
            // Same process-reuse guard as initialize(): never re-extract
            // under a live persistent runtime (poisons its stat caches).
            if (phpBridge.isPersistentMode()) {
                Log.d(TAG, "⚡ Persistent runtime alive — skipping background extraction (process reuse)")
                return
            }

            setupDirectories()
            // Run extraction too. If MainActivity already extracted, the isUpToDate
            // check returns false (no work). If MainActivity is mid-extract, the
            // lock inside extractLaravelBundle() blocks us here until it finishes.
            // If we arrived first (WorkManager cold start after an app update), we
            // do the extraction ourselves before the ephemeral runtime touches vendor/.
            val didExtract = extractLaravelBundle()
            setupEnvironment(didExtract)
            if (didExtract) {
                Log.d(TAG, "📦 Running post-extraction artisan commands (background path)...")
                runBaseArtisanCommands()
            }
            Log.d(TAG, "Background environment initialized")
        } catch (e: Exception) {
            Log.e(TAG, "Error initializing background environment", e)
            throw RuntimeException("Failed to initialize background environment", e)
        }
    }

    fun cleanup() {
        try {
            phpBridge.shutdown()
        } catch (e: Exception) {
            Log.e(TAG, "Error during cleanup", e)
        }
    }
}