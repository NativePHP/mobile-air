package com.nativephp.mobile.ui

import android.content.Intent
import android.content.pm.PackageManager
import android.content.res.Configuration
import android.os.Bundle
import android.os.Looper
import android.os.Handler
import android.util.Log
import android.webkit.CookieManager
import androidx.fragment.app.FragmentActivity
import androidx.activity.compose.setContent
import com.nativephp.mobile.bridge.PHPBridge
import com.nativephp.mobile.bridge.PHPQueueWorker
import com.nativephp.mobile.bridge.LaravelEnvironment
import com.nativephp.mobile.bridge.registerBridgeFunctions
import com.nativephp.mobile.bridge.plugins.registerPluginRenderers
import com.nativephp.mobile.ui.nativerender.registerNativeChromeRenderers
import com.nativephp.mobile.network.WebViewManager
import android.view.ViewGroup
import android.webkit.WebView
import androidx.activity.addCallback
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.nativephp.mobile.ui.nativerender.NativeUIBridge
import com.nativephp.mobile.ui.nativerender.NativeUIContent
import com.nativephp.mobile.ui.nativerender.PerformanceTracker
import com.nativephp.mobile.utils.NativeActionCoordinator
import com.nativephp.mobile.utils.WebViewProvider
import com.nativephp.mobile.security.LaravelCookieStore
import com.nativephp.mobile.lifecycle.NativePHPLifecycle
import java.io.File
import java.net.URL
import android.webkit.WebChromeClient
import androidx.compose.animation.*
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.layout.ime
import androidx.compose.material3.FabPosition
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import com.nativephp.plugins.native_ui.NativeUITheme
import com.nativephp.plugins.native_ui.toMaterialColorScheme
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.graphics.Insets
import kotlinx.coroutines.launch

class MainActivity : FragmentActivity(), WebViewProvider {
    private lateinit var webView: WebView
    private val phpBridge = PHPBridge(this)
    private lateinit var laravelEnv: LaravelEnvironment
    private lateinit var webViewManager: WebViewManager
    private lateinit var coord: NativeActionCoordinator
    private var pendingDeepLink: String? = null
    private var hotReloadWatcherThread: Thread? = null
    private var queueWorker: PHPQueueWorker? = null
    @Volatile private var nativeUIThread: Thread? = null
    private var shouldStopWatcher = false
    private var pendingInsets: Insets? = null
    private var showSplash by mutableStateOf(true)

    // Status bar style configuration - replaced during build
    private val statusBarStyle = "REPLACE_STATUS_BAR_STYLE"

    companion object {
        // Static instance holder for accessing MainActivity from other activities
        var instance: MainActivity? = null
            private set
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        instance = this

        // Android 15 edge-to-edge compatibility fix
        WindowCompat.setDecorFitsSystemWindows(window, false)

        // Configure status bar icon colors
        configureStatusBar()

        // Apply window insets - inject as CSS variables for web content
        ViewCompat.setOnApplyWindowInsetsListener(window.decorView) { view, insets ->
            val systemBars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            pendingInsets = systemBars

            // Inject CSS custom properties into WebView if ready
            if (::webViewManager.isInitialized) {
                injectSafeAreaInsets(systemBars.left, systemBars.top, systemBars.right, systemBars.bottom)
            }

            // Detect keyboard visibility and inject class into WebView
            val imeVisible = insets.isVisible(WindowInsetsCompat.Type.ime())
            if (::webViewManager.isInitialized) {
                injectKeyboardVisibility(imeVisible)
            }

            insets
        }

        // Initialize WebView before setContent so it's available for composition
        webView = WebView(this).apply {
            layoutParams = ViewGroup.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
            )
            settings.mediaPlaybackRequiresUserGesture = false
        }

        LaravelCookieStore.init(applicationContext)

        // Register bridge functions early, before PHP code can execute
        Log.d("MainActivity", "🔌 Registering bridge functions...")
        registerBridgeFunctions(this, applicationContext)
        registerNativeChromeRenderers()
        registerPluginRenderers()
        Log.d("MainActivity", "✅ Bridge functions registered")

        handleDeepLinkIntent(intent)

        // Set up Compose UI. The outer MaterialTheme threads the plugin's
        // theme tokens (`NativeUITheme.light` / `.dark` — driven by PHP
        // `Theme::merge`) into M3's color scheme so native chrome
        // (TopAppBar, Scaffold, dialogs, vanilla M3 controls) stays in
        // brand instead of falling back to the M3 baseline lavender.
        setContent {
            val isDark = isSystemInDarkTheme()
            MaterialTheme(colorScheme = nativeUiMaterialColorScheme(isDark)) {
                MainScreen()
            }
        }

        initializeEnvironmentAsync {
            // Setup WebView and managers FIRST
            webViewManager = WebViewManager(this, webView, phpBridge)
            webViewManager.setup()
            coord = NativeActionCoordinator.install(this)

            // Add JavaScript interface for drawer control
            webView.addJavascriptInterface(AndroidBridge(), "AndroidBridge")

            // Inject safe area insets BEFORE loading any URL to prevent content shift
            pendingInsets?.let {
                injectSafeAreaInsets(it.left, it.top, it.right, it.bottom)
            }

            // NOW load the URL after WebView is fully configured
            val target = pendingDeepLink ?: LaravelEnvironment.getStartURL(this)
            val fullUrl = "http://127.0.0.1$target"
            Log.d("DeepLink", "🚀 Loading final URL after WebView setup: $fullUrl")
            webView.loadUrl(fullUrl)

            pendingDeepLink = null

            // Hide splash screen after URL is loaded
            showSplash = false

            // Start hot reload watcher AFTER Laravel environment is initialized
            startHotReloadWatcher()
            injectJavaScript(webView)
        }

        onBackPressedDispatcher.addCallback(this) {
            // Native UI mode: route the back press into the PHP event queue
            // (EventType.systemBack = 8) so NativeComponent.runLoop can pop
            // the navigation stack via onBackPressed → back(). PHP handles
            // the deferredTransition (slide-from-left) and republishes.
            // When the stack empties, NativeUIBridge.isActive flips false on
            // the next iteration and a subsequent back press falls through
            // to the WebView / finish() path below.
            if (NativeUIBridge.isActive.value) {
                NativeElementBridge.sendSystemBackEvent()
                return@addCallback
            }

            if (webView.canGoBack()) {
                webView.goBack()
            } else {
                finish()
            }
        }
    }

    override fun onConfigurationChanged(newConfig: Configuration) {
        super.onConfigurationChanged(newConfig)
        Log.d("MainActivity", "🌀 Config changed: orientation = ${newConfig.orientation}")

        // Re-inject safe area insets on orientation change
        pendingInsets?.let {
            injectSafeAreaInsets(it.left, it.top, it.right, it.bottom)
        }

        // Reconfigure status bar on theme change
        if ((newConfig.uiMode and Configuration.UI_MODE_NIGHT_MASK) != 0) {
            configureStatusBar()
        }
    }

    @Suppress("DEPRECATION")
    private fun configureStatusBar() {
        val windowInsetsController = WindowInsetsControllerCompat(window, window.decorView)

        // Make status bar and navigation bar transparent for edge-to-edge
        window.statusBarColor = android.graphics.Color.TRANSPARENT
        window.navigationBarColor = android.graphics.Color.TRANSPARENT

        when (statusBarStyle) {
            "auto" -> {
                val isSystemDarkMode = (resources.configuration.uiMode and
                    Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
                windowInsetsController.isAppearanceLightStatusBars = !isSystemDarkMode
                windowInsetsController.isAppearanceLightNavigationBars = !isSystemDarkMode

                Log.d("StatusBar", "🎨 System bars style: auto (system ${if (isSystemDarkMode) "dark" else "light"} mode)")
                Log.d("StatusBar", "🎨 Using ${if (!isSystemDarkMode) "dark" else "light"} icons with transparent background")
            }
            "light" -> {
                windowInsetsController.isAppearanceLightStatusBars = false
                windowInsetsController.isAppearanceLightNavigationBars = false

                Log.d("StatusBar", "🎨 System bars style: light (white icons with transparent background)")
            }
            "dark" -> {
                windowInsetsController.isAppearanceLightStatusBars = true
                windowInsetsController.isAppearanceLightNavigationBars = true

                Log.d("StatusBar", "🎨 System bars style: dark (dark icons with transparent background)")
            }
            else -> {
                Log.w("StatusBar", "⚠️ Unknown status bar style: $statusBarStyle, defaulting to auto")
                val isSystemDarkMode = (resources.configuration.uiMode and
                    Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
                windowInsetsController.isAppearanceLightStatusBars = !isSystemDarkMode
                windowInsetsController.isAppearanceLightNavigationBars = !isSystemDarkMode
            }
        }
    }

    private fun initializeEnvironmentAsync(onReady: () -> Unit) {
        Thread {
            Log.d("LaravelInit", "Starting async Laravel extraction...")
            laravelEnv = LaravelEnvironment(this)
            laravelEnv.initialize()

            Log.d("LaravelInit", "Laravel environment ready")

            // Check runtime mode from bundle_meta.json
            val runtimeMode = LaravelEnvironment.getRuntimeMode(this)
            Log.d("LaravelInit", "Runtime mode: $runtimeMode")

            if (runtimeMode == "classic") {
                Log.d("LaravelInit", "Classic mode configured — skipping persistent runtime boot")
            } else {
                // Boot persistent PHP runtime BEFORE WebView loads
                val bootStart = System.currentTimeMillis()
                val booted = phpBridge.bootPersistentRuntime()
                val bootTime = System.currentTimeMillis() - bootStart

                if (booted) {
                    Log.d("LaravelInit", "Persistent runtime booted in ${bootTime}ms — requests will skip init/shutdown")

                    // Start background queue worker after persistent runtime is ready
                    queueWorker = PHPQueueWorker(phpBridge).also { it.start() }
                } else {
                    Log.w("LaravelInit", "Persistent runtime boot failed after ${bootTime}ms — falling back to classic mode")
                }
            }

            Handler(Looper.getMainLooper()).post {
                onReady()
            }
        }.start()
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handleDeepLinkIntent(intent)

        // If deep link didn't fire but we have a notification URL, navigate via Inertia
        if (intent.data == null) {
            val notificationUrl = intent.getStringExtra("notification_url")
            if (!notificationUrl.isNullOrEmpty()) {
                navigateWithInertia(notificationUrl)
            }
        }

        // Post lifecycle event for plugins
        intent.data?.let { uri ->
            NativePHPLifecycle.post(
                NativePHPLifecycle.Events.ON_NEW_INTENT,
                mapOf("url" to uri.toString())
            )
        }
    }

    override fun onResume() {
        super.onResume()
        NativePHPLifecycle.post(NativePHPLifecycle.Events.ON_RESUME)
    }

    override fun onPause() {
        super.onPause()
        NativePHPLifecycle.post(NativePHPLifecycle.Events.ON_PAUSE)
    }

    private fun handleDeepLinkIntent(intent: Intent?) {
        // Check for notification URL extra (from local notification taps or foreground push)
        val notificationUrl = intent?.getStringExtra("notification_url")
        if (!notificationUrl.isNullOrEmpty()) {
            Log.d("DeepLink", "🔔 Notification URL: $notificationUrl")
            pendingDeepLink = notificationUrl
            if (::laravelEnv.isInitialized && ::webViewManager.isInitialized) {
                val fullUrl = "http://127.0.0.1$notificationUrl"
                Log.d("DeepLink", "🚀 Loading notification URL immediately: $fullUrl")
                webView.loadUrl(fullUrl)
                pendingDeepLink = null
            }
            return
        }

        // Check for deep link URL from FCM data payload (background/killed push notifications)
        val fcmUrl = intent?.getStringExtra("url") ?: intent?.getStringExtra("link")
        if (!fcmUrl.isNullOrEmpty()) {
            Log.d("DeepLink", "🔔 FCM deep link URL: $fcmUrl")
            val uri = android.net.Uri.parse(fcmUrl)
            val scheme = uri.scheme
            val route = if (scheme != null && scheme != "http" && scheme != "https") {
                val host = uri.host ?: ""
                val path = uri.path ?: ""
                val query = uri.query?.let { "?$it" } ?: ""
                if (host.isNotEmpty()) "/$host$path$query" else "$path$query"
            } else {
                fcmUrl
            }
            pendingDeepLink = route
            if (::laravelEnv.isInitialized && ::webViewManager.isInitialized) {
                val fullUrl = "http://127.0.0.1$route"
                Log.d("DeepLink", "🚀 Loading FCM deep link immediately: $fullUrl")
                webView.loadUrl(fullUrl)
                pendingDeepLink = null
            }
            return
        }

        val uri = intent?.data ?: return
        Log.d("DeepLink", "🌐 Received deep link: $uri")

        // Check if this is an OAuth callback from nativephp:// scheme
        if (uri.scheme == "nativephp") {
            Log.d("OAuth", "🔐 OAuth callback detected from scheme: ${uri.scheme}")
            Log.d("OAuth", "🔐 OAuth callback host: ${uri.host}")
            Log.d("OAuth", "🔐 OAuth callback path: ${uri.path}")
            Log.d("OAuth", "🔐 OAuth callback query: ${uri.query}")
            
            // Check for common OAuth parameters
            val code = uri.getQueryParameter("code")
            val state = uri.getQueryParameter("state")
            val error = uri.getQueryParameter("error")
            
            if (code != null) {
                Log.d("OAuth", "✅ OAuth authorization code received: ${code.take(10)}...")
            }
            if (state != null) {
                Log.d("OAuth", "✅ OAuth state parameter: $state")
            }
            if (error != null) {
                Log.e("OAuth", "❌ OAuth error received: $error")
            }
        }

        val query = uri.query
        val laravelUrl = if (uri.scheme != "http" && uri.scheme != "https") {
            // Custom scheme (e.g., myapp://profile/settings): treat host as first path segment
            // This matches iOS behavior where the entire URI after scheme:// is the path
            val host = uri.host ?: ""
            val path = uri.path ?: ""
            buildString {
                if (host.isNotEmpty()) append("/$host")
                if (path.isNotEmpty()) append(path) else if (host.isEmpty()) append("/")
                if (!query.isNullOrBlank()) append("?$query")
            }
        } else {
            // HTTP(S) app links: just use the path (host is the verified domain)
            buildString {
                append(uri.path ?: "/")
                if (!query.isNullOrBlank()) append("?$query")
            }
        }

        Log.d("DeepLink", "📦 Saving deep link for later: $laravelUrl")
        pendingDeepLink = laravelUrl
        if (::laravelEnv.isInitialized && ::webViewManager.isInitialized) {
            // Only load immediately if both Laravel environment AND WebView are ready
            val fullUrl = "http://127.0.0.1$laravelUrl"
            Log.d("DeepLink", "🚀 Loading deep link immediately (app already running): $fullUrl")
            webView.loadUrl(fullUrl)
            pendingDeepLink = null
        } else {
            Log.d("DeepLink", "⏳ Deep link saved, waiting for app initialization to complete")
        }
    }


    private fun initializeEnvironment() {
        clearAllCookies()
        laravelEnv = LaravelEnvironment(this)
        laravelEnv.initialize()

    }

    fun clearAllCookies() {
        val cookieManager = CookieManager.getInstance()
        cookieManager.removeAllCookies(null)
        cookieManager.flush()
        Log.d("CookieInfo", "All cookies cleared")
    }


    override fun onDestroy() {
        super.onDestroy()
        instance = null

        PerformanceTracker.detachFrameMetrics(window)

        // Post lifecycle event for plugins
        NativePHPLifecycle.post(NativePHPLifecycle.Events.ON_DESTROY)

        // Clean up coordinator fragment to prevent memory leaks
        if (::coord.isInitialized) {
            supportFragmentManager.beginTransaction()
                .remove(coord)
                .commitNowAllowingStateLoss()
        }

        if (::webViewManager.isInitialized) {
            val chromeClient = webView.webChromeClient
            if (chromeClient is WebChromeClient) {
                chromeClient.onHideCustomView()
            }
        }

        // Stop hot reload watcher thread
        shouldStopWatcher = true
        hotReloadWatcherThread?.interrupt()

        // Stop native UI tree watcher
        NativeUIBridge.stopWatching()

        // Stop background queue worker before persistent runtime shutdown
        queueWorker?.stop()

        // Shutdown persistent runtime before cleanup
        if (phpBridge.isPersistentMode()) {
            phpBridge.shutdownPersistentRuntime()
        }

        laravelEnv.cleanup()
        phpBridge.shutdown()
    }

    override fun getWebView(): WebView {
        return webView
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)

        // Post lifecycle event for each permission result
        permissions.forEachIndexed { index, permission ->
            val granted = grantResults.getOrNull(index) == PackageManager.PERMISSION_GRANTED
            NativePHPLifecycle.post(
                NativePHPLifecycle.Events.ON_PERMISSION_RESULT,
                mapOf(
                    "permission" to permission,
                    "granted" to granted,
                    "requestCode" to requestCode
                )
            )
        }

        when (requestCode) {
            1001 -> {
                if ((grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED)) {
                    Log.d("Permission", "✅ Location permission granted")
                    // Optionally re-trigger the location fetch
                } else {
                    Log.e("Permission", "❌ Location permission denied")
                }
            }
            1002 -> {
                if ((grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED)) {
                    Log.d("Permission", "✅ Push notification permission granted")
                } else {
                    Log.e("Permission", "❌ Push notification permission denied")
                }
            }
        }
    }

    private fun startHotReloadWatcher() {
        // Configure WebView for development - disable caching for hot reload
        webView.settings.cacheMode = android.webkit.WebSettings.LOAD_NO_CACHE

        hotReloadWatcherThread = Thread {
            val appStorageDir = File(filesDir.parent, "app_storage")
            val reloadFile = File("${appStorageDir.absolutePath}/laravel/storage/framework/reload_signal.json")
            // PHP's storage_path() resolves to persisted_data/storage/ (set by LARAVEL_STORAGE_PATH)
            val restartFile = File("${appStorageDir.absolutePath}/persisted_data/storage/framework/.hot_restart")
            var lastModified: Long = 0
            // Track .hot_restart's last-seen mtime so the polling loop
            // doesn't re-trigger reboot every iteration while the file
            // exists. PHP consumes the file (deletes it) inside its
            // Route::native macro after extracting the nav stack; the
            // loop here just needs to fire once per write.
            var lastRestartModified: Long = 0
            var pollCount = 0
            // Generation counter — increments on every hot-reload
            // cycle. Helps identify exactly which reload is stuck in
            // logcat output ("HMR#N").
            var reloadGeneration = 0

            Log.d("HotReload", "Watcher started — watching: ${reloadFile.absolutePath}")

            while (!shouldStopWatcher && !Thread.currentThread().isInterrupted) {
                try {
                    pollCount++
                    if (pollCount % 20 == 0) {
                        Log.d("HotReload", "Poll #$pollCount — exists=${reloadFile.exists()} lastMod=$lastModified curMod=${if (reloadFile.exists()) reloadFile.lastModified() else "N/A"} nativeUI=${NativeUIBridge.isActive.value}")
                    }

                    // Reset the mtime tracker when the file is gone
                    // (PHP consumed it) so the next reload triggers.
                    if (!restartFile.exists() && lastRestartModified != 0L) {
                        lastRestartModified = 0L
                    }

                    // Check for native UI restart signal (PHP wrote .hot_restart before exiting).
                    // We peek the top URI but DON'T delete the file —
                    // PHP's Route::native handler is the sole
                    // consumer; it reads the full nav stack to
                    // restore back-button history, then removes the
                    // file. Deleting here would strip that payload.
                    // Mtime gate prevents the polling loop from re-
                    // triggering reboot every iteration while the file
                    // is in flight to PHP (we run faster than PHP can
                    // consume).
                    if (restartFile.exists() && restartFile.lastModified() > lastRestartModified) {
                        reloadGeneration++
                        val gen = reloadGeneration
                        val mtime = restartFile.lastModified()
                        lastRestartModified = mtime
                        Log.d("HotReload", "HMR#$gen .hot_restart detected — mtime=$mtime size=${restartFile.length()}")
                        try {
                            val content = restartFile.readText()
                            val json = org.json.JSONObject(content)
                            val restartUri = json.optString("uri", "/")
                            val stackSize = json.optJSONArray("stack")?.length() ?: 0

                            Log.d("HotReload", "HMR#$gen restart uri=$restartUri stackDepth=$stackSize")

                            // Wait for old PHP thread to finish (C mutex also guards this,
                            // but joining here avoids starting a thread that just blocks)
                            val oldThread = nativeUIThread
                            if (oldThread != null && oldThread.isAlive) {
                                val joinStart = System.currentTimeMillis()
                                Log.d("HotReload", "HMR#$gen waiting on old PHP thread (name=${oldThread.name})...")
                                oldThread.join(5000)
                                val joinElapsed = System.currentTimeMillis() - joinStart
                                if (oldThread.isAlive) {
                                    Log.w("HotReload", "HMR#$gen ⚠️ old PHP thread STILL ALIVE after ${joinElapsed}ms — proceeding anyway (C mutex will serialize)")
                                } else {
                                    Log.d("HotReload", "HMR#$gen old PHP thread exited in ${joinElapsed}ms")
                                }
                            } else {
                                Log.d("HotReload", "HMR#$gen no live old PHP thread (nativeUIThread=${oldThread?.name ?: "null"})")
                            }

                            // If persistent mode, reboot interpreter to pick up new class definitions
                            if (phpBridge.isPersistentMode()) {
                                val rebootStart = System.currentTimeMillis()
                                Log.d("HotReload", "HMR#$gen rebooting persistent runtime...")

                                // Stop queue worker before shutdown — its TSRM context
                                // will be destroyed by php_module_shutdown
                                queueWorker?.stop()

                                phpBridge.shutdownPersistentRuntime()
                                phpBridge.bootPersistentRuntime()

                                // Restart queue worker with fresh runtime
                                queueWorker = PHPQueueWorker(phpBridge).also { it.start() }
                                Log.d("HotReload", "HMR#$gen reboot complete in ${System.currentTimeMillis() - rebootStart}ms")
                            }

                            // Re-start the native UI watcher (PHP will re-init shared memory)
                            NativeUIBridge.startWatching()
                            NativeElementBridge.startWatching()

                            // Directly re-execute the PHP request on a new thread
                            // This bypasses the WebView entirely — fresh PHP process
                            val restartThread = Thread({
                                try {
                                    Log.d("HotReload", "HMR#$gen executing PHP for $restartUri")
                                    val execStart = System.currentTimeMillis()
                                    phpBridge.executeNativeRoute(restartUri)
                                    Log.d("HotReload", "HMR#$gen PHP execution returned after ${System.currentTimeMillis() - execStart}ms")
                                } catch (e: Exception) {
                                    Log.e("HotReload", "HMR#$gen execution failed: ${e.message}", e)
                                }
                            }, "npui-hot-restart-$gen")
                            nativeUIThread = restartThread
                            restartThread.start()

                            continue
                        } catch (e: Exception) {
                            Log.e("HotReload", "HMR#$gen failed: ${e.message}", e)
                            restartFile.delete()
                        }
                    }

                    if (reloadFile.exists() && reloadFile.lastModified() > lastModified) {
                        lastModified = reloadFile.lastModified()

                        // Skip if a hot reload is still in flight — the
                        // C-side PHP shutdown briefly flips `isActive`
                        // false; a save landing in that window used to
                        // misroute to the WebView else-branch (which
                        // reboots PHP without dispatching a route, then
                        // never recovers). Drop the duplicate; the user
                        // can save again after this reload finishes.
                        if (NativeUIBridge.isReloading.value) {
                            Log.d("HotReload", "▶ reload_signal fired (mod=$lastModified) — SKIPPED, reload already in flight")
                            continue
                        }

                        if (NativeUIBridge.isActive.value) {
                            // Native UI mode: send hot reload event through mmap
                            // PHP will shut down and write .hot_restart signal
                            val elementReady = NativeElementBridge.nativeElementIsReady()
                            val phpBooted = phpBridge.isPersistentMode()
                            Log.d("HotReload", "▶ reload_signal fired (mod=$lastModified) — sending HMR event (isActive=true elementReady=$elementReady persistent=$phpBooted lastRestartModified=$lastRestartModified)")
                            // Preserve the visible tree across PHP's
                            // C-side stopWatching call. Cleared in
                            // `onTreePostedToMain` when the first new
                            // tree from the rebooted runtime lands.
                            NativeElementBridge.preserveTreeOnStop = true
                            // Show the "Reloading…" pill (root Compose
                            // overlay watches this). Cleared in
                            // `NativeElementBridge.onTreePostedToMain`
                            // when the first new tree from the rebooted
                            // PHP runtime lands.
                            NativeUIBridge.isReloading.value = true
                            NativeUIBridge.sendHotReloadEvent()
                            // Brief wait for PHP to process event and write .hot_restart,
                            // then loop back to check immediately (instead of 500ms sleep)
                            Thread.sleep(100)
                            continue
                        } else {
                            // WebView mode: reload the page
                            // If persistent mode, reboot the interpreter to pick up new class definitions
                            if (phpBridge.isPersistentMode()) {
                                Log.d("HotReload", "Rebooting persistent runtime for hot reload...")
                                val rebootStart = System.currentTimeMillis()

                                // Stop queue worker before shutdown — its TSRM context
                                // will be destroyed by php_module_shutdown, causing SIGABRT
                                // if still active
                                queueWorker?.stop()

                                phpBridge.shutdownPersistentRuntime()
                                phpBridge.bootPersistentRuntime()

                                // Restart queue worker with fresh runtime
                                queueWorker = PHPQueueWorker(phpBridge).also { it.start() }

                                Log.d("HotReload", "Persistent runtime rebooted in ${System.currentTimeMillis() - rebootStart}ms")
                            }

                            runOnUiThread {
                                webView.stopLoading()
                                webView.clearCache(true)
                                webView.clearHistory()
                                webView.clearFormData()

                                val currentUrl = webView.url ?: "http://127.0.0.1/"
                                val separator = if (currentUrl.contains("?")) "&" else "?"
                                val cacheBustUrl = "${currentUrl}${separator}_cb=${System.currentTimeMillis()}"

                                Handler(Looper.getMainLooper()).postDelayed({
                                    webView.loadUrl(cacheBustUrl)
                                }, 100)
                            }
                        }
                    }

                    Thread.sleep(500)
                } catch (e: InterruptedException) {
                    break
                } catch (e: Exception) {
                    Log.e("HotReload", "Watcher error: ${e.message}", e)
                    Thread.sleep(1000)
                }
            }
        }
        hotReloadWatcherThread?.start()
    }

    private fun isDebugVersion(): Boolean {
        return try {
            val appStorageDir = File(filesDir.parent, "app_storage")
            val versionFile = File(appStorageDir, "laravel/.version")

            if (versionFile.exists()) {
                val version = versionFile.readText().trim().trim('"').trim('\'')
                version.equals("DEBUG", ignoreCase = true)
            } else {
                false
            }
        } catch (e: Exception) {
            false
        }
    }

    private fun injectJavaScript(view: WebView) {
        val jsCode = """
        (function() {
            // Add platform identifier class
            document.body.classList.add('nativephp-android');

            // 🌐 Native event bridge
            const listeners = {};

            const Native = {
                on: function(eventName, callback) {
                    if (!listeners[eventName]) {
                        listeners[eventName] = [];
                    }
                    listeners[eventName].push(callback);
                },
                off: function(eventName, callback) {
                    if (listeners[eventName]) {
                        listeners[eventName] = listeners[eventName].filter(cb => cb !== callback);
                    }
                },
                dispatch: function(eventName, payload) {
                    const cbs = listeners[eventName] || [];
                    cbs.forEach(cb => cb(payload, eventName));
                },
                openDrawer: function() {
                    if (window.AndroidBridge) {
                        window.AndroidBridge.openDrawer();
                    }
                }
            };

            window.Native = Native;

            document.addEventListener("native-event", function (e) {
                // Normalize event names by removing leading backslashes
                let eventName = e.detail.event.replace(/^(\\\\)+/, '');
                const payload = e.detail.payload;

                // Dispatch with normalized event name
                Native.dispatch(eventName, payload);

                // Also dispatch to Livewire if available
                if (window.Livewire && typeof window.Livewire.dispatch === 'function') {
                    window.Livewire.dispatch('native:' + eventName, payload);
                }
            });
        })();
        """
        view.evaluateJavascript(jsCode, null)
    }

    private fun injectSafeAreaInsets(left: Int, top: Int, right: Int, bottom: Int) {
        val density = resources.displayMetrics.density
        val displayMetrics = resources.displayMetrics

        // Get current screen dimensions (rotated)
        val currentWidthPx = (displayMetrics.widthPixels / density).toInt()
        val currentHeightPx = (displayMetrics.heightPixels / density).toInt()

        // Determine natural (portrait) dimensions
        // The smaller dimension is always the width in portrait orientation
        val portraitWidthPx = minOf(currentWidthPx, currentHeightPx)
        val portraitHeightPx = maxOf(currentWidthPx, currentHeightPx)

        val leftPx = (left / density).toInt()
        var topPx = (top / density).toInt()
        val rightPx = (right / density).toInt()
        val bottomPx = (bottom / density).toInt()

        // Check if native top bar is present - if so, set top inset to 0
        // The native top bar already handles status bar spacing
        val hasTopBar = NativeUIState.topBarData.value != null
        if (hasTopBar) {
            topPx = 0
            Log.d("SafeArea", "Native top bar detected - setting top inset to 0")
        }

        // Get actual device orientation from Android Configuration
        val isPortrait = resources.configuration.orientation == Configuration.ORIENTATION_PORTRAIT

        Log.d("SafeArea", "Device orientation: ${if (isPortrait) "Portrait" else "Landscape"}")
        Log.d("SafeArea", "Current screen dimensions: ${currentWidthPx}x${currentHeightPx}")
        Log.d("SafeArea", "Natural (portrait) dimensions: ${portraitWidthPx}x${portraitHeightPx}")
        Log.d("SafeArea", "Injecting insets: top=${topPx}px, right=${rightPx}px, bottom=${bottomPx}px, left=${leftPx}px")

        // Inject CSS as early as possible - create a self-executing function that runs immediately
        // and also sets up listeners for Livewire navigation to persist styles
        val jsCode = """
        (function() {
            function injectSafeAreaStyles() {
                // Remove existing safe-area style to avoid duplicates
                const existingStyle = document.getElementById('nativephp-safe-area-style');
                if (existingStyle) {
                    existingStyle.remove();
                }

                // Create style element with inset CSS variables and helper class
                const style = document.createElement('style');
                style.id = 'nativephp-safe-area-style';
                style.setAttribute('data-nativephp-persist', 'true');
                style.textContent = ':root { --inset-top: ${topPx}px; --inset-right: ${rightPx}px; --inset-bottom: ${bottomPx}px; --inset-left: ${leftPx}px; } .nativephp-safe-area { ${if (isPortrait) "padding-top: var(--inset-top); padding-bottom: var(--inset-bottom);" else "padding-right: var(--inset-right); padding-left: var(--inset-left);"} }';

                // Try to insert into head, or create head if it doesn't exist yet
                if (!document.head) {
                    const head = document.createElement('head');
                    if (document.documentElement) {
                        document.documentElement.insertBefore(head, document.documentElement.firstChild);
                    }
                }

                if (document.head) {
                    // Insert at the BEGINNING of head for highest priority
                    if (document.head.firstChild) {
                        document.head.insertBefore(style, document.head.firstChild);
                    } else {
                        document.head.appendChild(style);
                    }
                }

                // Also set CSS variables directly on documentElement for immediate availability
                // These persist across Livewire navigate because html element is not replaced
                if (document.documentElement) {
                    document.documentElement.style.setProperty('--inset-top', '${topPx}px');
                    document.documentElement.style.setProperty('--inset-right', '${rightPx}px');
                    document.documentElement.style.setProperty('--inset-bottom', '${bottomPx}px');
                    document.documentElement.style.setProperty('--inset-left', '${leftPx}px');

                    // Add orientation class to HTML element for Tailwind targeting
                    document.documentElement.classList.remove('portrait', 'landscape');
                    document.documentElement.classList.add('${if (isPortrait) "portrait" else "landscape"}');
                }

                console.log('SafeArea injected at ' + document.readyState + ': ${if (isPortrait) "portrait" else "landscape"}');
            }

            // Inject immediately
            injectSafeAreaStyles();

            // Re-inject when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', injectSafeAreaStyles);
            }

            // IMPORTANT: Re-inject after Livewire navigation to persist styles
            // Livewire can swap out the <head> content during navigate: true transitions
            document.addEventListener('livewire:navigated', function() {
                console.log('Livewire navigated - re-injecting safe area styles');
                injectSafeAreaStyles();
            });

            // Also listen for the older wire:navigate event (Livewire 2.x compatibility)
            document.addEventListener('wire:navigate', function() {
                console.log('Wire navigate - re-injecting safe area styles');
                injectSafeAreaStyles();
            });
        })();
        """
        webView.evaluateJavascript(jsCode, null)
    }

    // Public function called by WebViewManager on page load
    fun injectSafeAreaInsetsToWebView() {
        pendingInsets?.let {
            injectSafeAreaInsets(it.left, it.top, it.right, it.bottom)
        }
    }

    // Track keyboard visibility state to avoid redundant JS calls
    private var lastKeyboardVisible: Boolean? = null

    private fun injectKeyboardVisibility(isVisible: Boolean) {
        // Only inject if state actually changed
        if (lastKeyboardVisible == isVisible) return
        lastKeyboardVisible = isVisible

        // Update UI state so Compose components can react (e.g., hide bottom nav)
        NativeUIState.setKeyboardVisible(isVisible)

        val jsCode = if (isVisible) {
            "document.body.classList.add('keyboard-visible');"
        } else {
            "document.body.classList.remove('keyboard-visible');"
        }
        webView.evaluateJavascript(jsCode, null)
        Log.d("Keyboard", "⌨️ Keyboard visibility changed: $isVisible")
    }

    /**
     * Extract path and query from URL, handling both full URLs and relative paths
     * Supports Laravel route() helper output and relative paths
     */
    private fun extractPath(url: String): String {
        Log.d("Navigation", "📥 Received URL: $url")

        return try {
            if (url.startsWith("http://") || url.startsWith("https://")) {
                // Parse as full URL and extract path + query
                val parsedUrl = URL(url)
                // URL.getPath() returns empty string for root, not null - handle both cases
                val path = if (parsedUrl.path.isNullOrEmpty()) "/" else parsedUrl.path
                val query = parsedUrl.query
                val result = if (query != null) "$path?$query" else path
                Log.d("Navigation", "✅ Extracted path from full URL: $result")
                result
            } else if (url.startsWith("/")) {
                // Already a path
                Log.d("Navigation", "✅ Using path as-is: $url")
                url
            } else {
                // Relative path, prepend /
                val result = "/$url"
                Log.d("Navigation", "✅ Converted relative to absolute: $result")
                result
            }
        } catch (e: Exception) {
            Log.e("Navigation", "❌ Error parsing URL: $url", e)
            // Fallback: treat as relative path
            val fallback = if (url.startsWith("/")) url else "/$url"
            Log.d("Navigation", "🔄 Using fallback: $fallback")
            fallback
        }
    }

    /**
     * Navigate using Inertia router if available, otherwise fall back to direct navigation.
     * This allows native edge component clicks to integrate with Inertia.js for SPA-like
     * navigation while maintaining compatibility with non-Inertia apps.
     */
    private fun navigateWithInertia(url: String) {
        val path = extractPath(url)
        Log.d("Navigation", "🚀 Navigating with Inertia check: $path")

        // Escape the path for JavaScript string (use double quotes to avoid issues with /)
        val escapedPath = path.replace("\\", "\\\\").replace("\"", "\\\"")

        val jsCode = """
            (function() {
                var path = "$escapedPath";
                console.log('[NativePHP] Navigation requested:', path);

                // Check if Inertia router is available
                if (typeof window.router !== 'undefined' && typeof window.router.visit === 'function') {
                    console.log('[NativePHP] Using Inertia router.visit():', path);
                    window.router.visit(path);
                } else {
                    console.log('[NativePHP] Inertia not available, using location.href');
                    window.location.href = path;
                }
            })();
        """.trimIndent()

        webView.evaluateJavascript(jsCode, null)
    }

    /**
     * Main Compose UI screen with WebView, navigation, and overlays
     * Side drawer wraps everything to avoid touch blocking issues
     */
    @Composable
    private fun MainScreen() {
        var showDebugLog by remember { mutableStateOf(false) }
        val isDebug = remember { isDebugVersion() }

        Box(Modifier.fillMaxSize()) {
            // Side drawer wraps the main content (correct ModalNavigationDrawer usage)
            SideDrawerContent(
                content = {
                    // Get FAB position from state
                    val fabData by NativeUIState.fabData
                    val fabPosition = when (fabData?.position?.lowercase()) {
                        "center" -> FabPosition.Center
                        "start" -> FabPosition.Start
                        else -> FabPosition.End  // Default to end (bottom-right)
                    }

                    // Scaffold provides standard Material3 layout with FAB support
                    // Configure for edge-to-edge by using zero content window insets
                    Scaffold(
                        topBar = {
                            NativeTopBar(
                                onMenuClick = {
                                    Log.d("Navigation", "🍔 Menu button clicked - opening drawer")
                                },
                                onNavigate = { url ->
                                    Log.d("Navigation", "⚡ TopBar action navigation clicked")
                                    navigateWithInertia(url)
                                }
                            )
                        },
                        bottomBar = {
                            BottomNavigationContent()
                        },
                        floatingActionButton = {
                            NativeFab(
                                onNavigate = { url ->
                                    Log.d("Navigation", "🖱️ FAB navigation clicked")
                                    navigateWithInertia(url)
                                },
                                onEvent = { eventName ->
                                    Log.d("NativeEvent", "🖱️ FAB event dispatched: $eventName")
                                    // Dispatch native event via JavaScript
                                    val jsCode = """
                                        if (window.Native) {
                                            window.Native.dispatch('$eventName', {});
                                        }
                                    """.trimIndent()
                                    webView.evaluateJavascript(jsCode, null)
                                }
                            )
                        },
                        floatingActionButtonPosition = fabPosition,
                        contentWindowInsets = WindowInsets(0, 0, 0, 0)
                    ) { paddingValues ->
                        // Main content: WebView only
                        // Use paddingValues to respect TopBar and BottomNav heights
                        // IMPORTANT: Add IME (keyboard) inset padding so content isn't hidden behind keyboard

                        Box(modifier = Modifier.fillMaxSize()) {
                            AndroidView(
                                factory = { webView },
                                modifier = Modifier
                                    .fillMaxSize()
                                    .padding(paddingValues)
                                    .consumeWindowInsets(paddingValues)
                                    .windowInsetsPadding(WindowInsets.ime),
                                update = { view ->
                                    // Force layout recalculation when Compose size changes
                                    // This ensures viewport units (100vh, 100vw) work correctly
                                    view.requestLayout()
                                }
                            )

                            // Native UI overlay — covers WebView when PHP renders a native tree
                            // Must be inside SideDrawerContent so the drawer renders on top
                            val nativeUIActive by NativeUIBridge.isActive
                            if (nativeUIActive) {
                                Box(
                                    modifier = Modifier
                                        .fillMaxSize()
                                        .background(MaterialTheme.colorScheme.background)
                                ) {
                                    NativeUIContent()
                                }
                            }

                            // Hot-reload indicator. Mirrors iOS's
                            // `HotReloadIndicator` — small Material 3
                            // pill at top with a spinner + label.
                            // `isReloading` is true between the start
                            // of the hot-reload event and the first
                            // new tree publish from the rebooted PHP.
                            val isReloading by NativeUIBridge.isReloading
                            AnimatedVisibility(
                                visible = isReloading,
                                enter = slideInVertically { -it } + androidx.compose.animation.fadeIn(),
                                exit = slideOutVertically { -it } + androidx.compose.animation.fadeOut(),
                                modifier = Modifier
                                    .align(Alignment.TopCenter)
                                    // `statusBarsPadding` clears the
                                    // notch / status bar; the extra
                                    // 8.dp gives the pill some breathing
                                    // room below it.
                                    .statusBarsPadding()
                                    .padding(top = 8.dp),
                            ) {
                                HotReloadIndicator()
                            }
                        }
                    }
                }
            )

            // Debug log FAB — only in DEBUG mode
            if (isDebug) {
                Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .windowInsetsPadding(WindowInsets.systemBars)
                        .padding(end = 16.dp, bottom = 80.dp),
                    contentAlignment = Alignment.BottomEnd
                ) {
                    DebugLogFab { showDebugLog = true }
                }

                DebugLogSheet(
                    context = this@MainActivity,
                    visible = showDebugLog,
                    onDismiss = { showDebugLog = false }
                )
            }

            // Splash overlay with fade animation (full screen, no insets)
            AnimatedVisibility(
                visible = showSplash,
                exit = fadeOut(animationSpec = tween(300))
            ) {
                SplashScreen()
            }
        }
    }

    /**
     * Splash screen composable - shows custom image or fallback text
     */
    @Composable
    private fun SplashScreen() {
        val splashResourceId = remember {
            try {
                resources.getIdentifier("splash", "drawable", packageName)
            } catch (e: Exception) {
                0
            }
        }

        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(Color.Black),
            contentAlignment = Alignment.Center
        ) {
            if (splashResourceId != 0) {
                Image(
                    painter = painterResource(id = splashResourceId),
                    contentDescription = "App splash screen",
                    modifier = Modifier.fillMaxSize(),
                    contentScale = ContentScale.Crop
                )
            } else {
                SplashText()
            }
        }
    }

    @Composable
    private fun SplashText() {
        Box(
            modifier = Modifier.fillMaxSize(),
            contentAlignment = Alignment.BottomCenter
        ) {
            Text(
                text = "Loading…",
                fontSize = 16.sp,
                color = Color.White,
                modifier = Modifier.padding(bottom = 64.dp)
            )
        }
    }

    /**
     * Derive an M3 [ColorScheme] from the active plugin theme tokens.
     * Reads from [NativeUITheme.light] / [NativeUITheme.dark] reactively
     * so PHP-side `Theme::merge` updates flow through to chrome (top
     * bar, drawers, M3 controls) on the next recomposition.
     */
    @Composable
    private fun nativeUiMaterialColorScheme(isDark: Boolean): ColorScheme {
        val tokens = if (isDark) NativeUITheme.dark else NativeUITheme.light
        return tokens.toMaterialColorScheme(isDark)
    }

    /**
     * Bottom navigation composable
     * Hides with animation when keyboard is visible to prevent layout conflicts
     */
    @Composable
    private fun BottomNavigationContent() {
        val isKeyboardVisible by NativeUIState.isKeyboardVisible
        val bottomNavData by NativeUIState.bottomNavData

        val systemInDarkMode = isSystemInDarkTheme()
        val useDarkTheme = bottomNavData?.dark ?: systemInDarkMode
        val colorScheme = nativeUiMaterialColorScheme(useDarkTheme)

        // Animate bottom nav visibility - slide down when keyboard opens
        AnimatedVisibility(
            visible = !isKeyboardVisible,
            enter = slideInVertically(
                initialOffsetY = { it },
                animationSpec = tween(150)
            ),
            exit = slideOutVertically(
                targetOffsetY = { it },
                animationSpec = tween(150)
            )
        ) {
            MaterialTheme(colorScheme = colorScheme) {
                NativeBottomNavigation(
                    onNavigate = { url ->
                        Log.d("Navigation", "🖱️ Bottom nav item clicked")
                        navigateWithInertia(url)
                    }
                )
            }
        }
    }

    /**
     * Side drawer composable - wraps main content in ModalNavigationDrawer
     */
    @Composable
    private fun SideDrawerContent(content: @Composable () -> Unit) {
        val systemInDarkMode = isSystemInDarkTheme()
        val sideNavData by NativeUIState.sideNavData
        val useDarkTheme = sideNavData?.dark ?: systemInDarkMode
        val colorScheme = nativeUiMaterialColorScheme(useDarkTheme)

        MaterialTheme(colorScheme = colorScheme) {
            NativeSideDrawer(
                onNavigate = { url ->
                    Log.d("Navigation", "🖱️ Side nav item clicked")
                    navigateWithInertia(url)
                },
                onDrawerStateChange = { isOpen ->
                    Log.d("SideDrawer", "Drawer state changed: $isOpen")
                },
                content = content
            )
        }
    }

    inner class AndroidBridge {
        @android.webkit.JavascriptInterface
        fun openDrawer() {
            Log.d("AndroidBridge", "🖱️ openDrawer() called from JavaScript")
            runOnUiThread {
                // Check if we have side nav data first
                val hasData = NativeUIState.sideNavData.value != null &&
                             !NativeUIState.sideNavData.value?.children.isNullOrEmpty()

                if (!hasData) {
                    Log.w("AndroidBridge", "⚠️ Cannot open drawer - no side nav data available")
                    return@runOnUiThread
                }

                if (NativeUIState.drawerScope == null) {
                    Log.e("AndroidBridge", "❌ drawerScope is null!")
                    return@runOnUiThread
                }
                if (NativeUIState.drawerState == null) {
                    Log.e("AndroidBridge", "❌ drawerState is null!")
                    return@runOnUiThread
                }

                // Open drawer via Compose state
                NativeUIState.drawerScope?.launch {
                    NativeUIState.drawerState?.open()
                    Log.d("AndroidBridge", "✅ Drawer opened!")
                }
            }
        }
    }

}

/**
 * Material 3 "Reloading…" pill shown briefly during hot reload.
 * Tonal `surfaceContainerHigh` capsule with a small `CircularProgressIndicator`
 * and label. Auto-dismisses when `NativeUIBridge.isReloading` flips
 * false (driven by the first publish from the rebooted PHP runtime —
 * see `NativeElementBridge.onTreePostedToMain`). Mirrors iOS's
 * `HotReloadIndicator`.
 */
@Composable
private fun HotReloadIndicator() {
    androidx.compose.material3.Surface(
        // `primaryContainer` reads as a brand-colored chip — more
        // visible against arbitrary screen content than the tonal
        // surface variant. Matches the prominence of iOS's Liquid
        // Glass capsule, which has its own material vocabulary.
        color = MaterialTheme.colorScheme.primaryContainer,
        contentColor = MaterialTheme.colorScheme.onPrimaryContainer,
        shape = androidx.compose.foundation.shape.RoundedCornerShape(percent = 50),
        tonalElevation = 6.dp,
        shadowElevation = 8.dp,
    ) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.padding(horizontal = 16.dp, vertical = 10.dp),
        ) {
            androidx.compose.material3.CircularProgressIndicator(
                modifier = Modifier.size(16.dp),
                strokeWidth = 2.dp,
                color = MaterialTheme.colorScheme.onPrimaryContainer,
            )
            Spacer(modifier = Modifier.width(10.dp))
            Text(
                "Reloading…",
                style = MaterialTheme.typography.labelLarge,
            )
        }
    }
}