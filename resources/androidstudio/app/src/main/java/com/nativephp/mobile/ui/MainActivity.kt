package com.nativephp.mobile.ui

import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.res.Configuration
import android.hardware.Sensor
import android.hardware.SensorManager
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
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.graphics.Insets
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class MainActivity : FragmentActivity(), WebViewProvider {
    // Native-first boot: no WebView exists until a web response actually
    // needs painting. Compose state so MainScreen recomposes and attaches
    // the WebView the moment a renderer is lazily created.
    private var webRenderer by mutableStateOf<com.nativephp.mobile.network.WebRenderer?>(null)
    // True while an EXIT_WEB swap is in flight: the frozen native tree stays
    // visible (isActive stays true) until the web page's first commit, so
    // Chromium init never shows as a blank flash.
    @Volatile var pendingWebSwap = false
    // One-shot latch for the renderer-agnostic first-content signal that
    // drives splash dismissal + reportFullyDrawn.
    @Volatile private var firstContentReported = false
    // Lazy so PHPBridge's static `System.loadLibrary("php_wrapper")` (loading the large
    // embedded-PHP .so) runs on FIRST use — which is now the post-first-frame boot block —
    // instead of during activity construction on the TTID critical path. First access is on
    // the main thread (WebViewManager ctor) then the boot thread; the default synchronized
    // lazy makes that safe.
    private val phpBridge by lazy { PHPBridge(this) }
    private lateinit var laravelEnv: LaravelEnvironment
    private lateinit var coord: NativeActionCoordinator
    // Set once the boot pipeline's onReady has run — replaces the old
    // "::webViewManager.isInitialized" readiness checks now that a
    // WebViewManager only exists when a WebRenderer does.
    @Volatile private var bootReady = false
    private var pendingDeepLink: String? = null
    private var hotReloadWatcherThread: Thread? = null
    private var queueWorker: PHPQueueWorker? = null
    @Volatile private var nativeUIThread: Thread? = null
    private var shouldStopWatcher = false
    private var pendingInsets: Insets? = null
    // Last appearance pushed to PHP, so onConfigurationChanged (which also fires
    // on rotation) only emits AppearanceChanged when the theme actually flips.
    private var lastAppearance: String? = null
    private var showSplash by mutableStateOf(true)
    // Gates composition of the heavy MainScreen tree (Scaffold + WebView)
    // until the runtime is booted and the WebView is ready. Until then the first
    // frame is just the splash overlay, keeping that composition off the critical
    // path to first paint.
    private var showContent by mutableStateOf(false)

    // Device-shake detection — registered in onResume, unregistered in onPause.
    private var sensorManager: SensorManager? = null
    private var shakeDetector: ShakeDetector? = null

    // Status bar style configuration - replaced during build
    private val statusBarStyle = "REPLACE_STATUS_BAR_STYLE"

    companion object {
        // Static instance holder for accessing MainActivity from other activities
        var instance: MainActivity? = null
            private set

        // Delay before the background queue worker boots. The worker spins up a
        // second full Laravel runtime; deferring it keeps that off the cold-start
        // critical path so it doesn't steal CPU from the first paint.
        private const val WORKER_START_DELAY_MS = 2500L
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        instance = this

        // Seed the appearance tracker so a later config change (e.g. rotation)
        // only emits AppearanceChanged when the theme genuinely differs.
        lastAppearance = if ((resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) ==
                Configuration.UI_MODE_NIGHT_YES) "dark" else "light"

        // Watch OS thermal status (Android 10+). Seed-callback on register is
        // skipped inside the monitor so launch doesn't look like a change.
        ThermalStateMonitor.start(this)

        // Android 15 edge-to-edge compatibility fix
        WindowCompat.setDecorFitsSystemWindows(window, false)

        // Configure status bar icon colors
        configureStatusBar()

        // Apply window insets - inject as CSS variables for web content
        ViewCompat.setOnApplyWindowInsetsListener(window.decorView) { view, insets ->
            val systemBars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            pendingInsets = systemBars

            // Inject CSS custom properties into WebView if ready (no-op
            // without a renderer; native screens take safe-area from the
            // element flat buffer instead)
            if (webRenderer != null) {
                injectSafeAreaInsets(systemBars.left, systemBars.top, systemBars.right, systemBars.bottom)
            }

            // Keyboard visibility: injected as a WebView CSS class once boot
            // is ready (native screens read the IME inset natively).
            val imeVisible = insets.isVisible(WindowInsetsCompat.Type.ime())
            if (bootReady) {
                injectKeyboardVisibility(imeVisible)
            }

            insets
        }

        handleDeepLinkIntent(intent)

        // Set up Compose UI and PAINT THE SPLASH FIRST, from a minimal onCreate, so the
        // first frame is on screen ASAP (TTID). Everything heavy — WebView (Chromium)
        // init and the PHP boot — is deferred to AFTER the first frame (below), so neither
        // the main-thread Chromium cost nor the boot thread's disk I/O blocks the path to
        // first paint. (Measured: starting the boot before first paint cost ~160ms of
        // uninterruptible I/O sleep on the main thread + Chromium init on the critical path.)
        setContent {
            val isDark = isSystemInDarkTheme()
            MaterialTheme(
                colorScheme = nativeUiMaterialColorScheme(isDark),
                typography = NativeUIThemeProvider.resolveTypography(),
            ) {
                Box(modifier = Modifier.fillMaxSize()) {
                    // The heavy MainScreen tree (Scaffold + drawer + WebView) is gated on
                    // showContent; until boot is ready the first frame is just the splash.
                    if (showContent) {
                        MainScreen()
                    }
                    // Splash overlay with fade animation (full screen, no insets).
                    // Lives here — NOT inside the showContent gate — so it paints on the
                    // first frame and covers the boot; MainScreen composes beneath it.
                    AnimatedVisibility(
                        visible = showSplash,
                        exit = fadeOut(animationSpec = tween(300))
                    ) {
                        SplashScreen()
                    }
                    // Dev-mode perf overlay (top-right pill: fps / p99 / jank).
                    // Driven by Choreographer via FrameTracker. Recomposes at
                    // 4Hz only — no per-frame render cost. Toggle off in
                    // production via FrameTracker.enabled = false.
                    com.nativephp.mobile.ui.nativerender.PerfOverlay()
                }
            }
        }

        // Defer the expensive work until AFTER the first frame is laid out. The boot
        // thread's I/O previously sat on the critical path to first paint; running it
        // here keeps the splash frame fast. Native-first: no WebView is created here —
        // an all-native app never pays Chromium init at all.
        window.decorView.post {
            initializeEnvironmentAsync {
                coord = NativeActionCoordinator.install(this)

                // Compose the real UI now. MainScreen is cheap without a WebView;
                // the native tree (or a lazily-created WebView) attaches when its
                // content arrives.
                showContent = true
                bootReady = true

                val target = pendingDeepLink ?: LaravelEnvironment.getStartURL(this)
                pendingDeepLink = null

                when (BootPlanner.plan(this, target)) {
                    BootPlanner.Entry.NATIVE_DIRECT -> {
                        // Direct JNI dispatch into the native runloop — the WebView
                        // is not involved. Splash dismissal + reportFullyDrawn fire
                        // from the first-content signal (first framed element tree).
                        startNativeSession(target)
                        startFirstContentWatchdog()
                    }
                    BootPlanner.Entry.WEB_LEGACY -> {
                        // Legacy path, unchanged: create the renderer eagerly and
                        // drive the first request through webView.loadUrl. First
                        // content fires on the page's first commit.
                        val renderer = ensureWebRenderer()
                        val fullUrl = "http://127.0.0.1$target"
                        Log.d("DeepLink", "🚀 Loading final URL after WebView setup: $fullUrl")
                        renderer.webView.loadUrl(fullUrl)
                    }
                }

                // Defer the background queue worker — it boots a SECOND full Laravel
                // runtime that would contend for CPU during first paint. Start it a
                // couple of seconds after the UI is up. Guarded against a fast destroy
                // and against double-starting if the activity was re-created.
                Handler(Looper.getMainLooper()).postDelayed({
                    if (!isFinishing && !isDestroyed &&
                        phpBridge.isPersistentMode() && queueWorker == null) {
                        Log.d("MainActivity", "▶️ Starting deferred background queue worker")
                        queueWorker = PHPQueueWorker(phpBridge).also { it.start() }
                    }
                }, WORKER_START_DELAY_MS)

                // Start hot reload watcher AFTER Laravel environment is initialized
                startHotReloadWatcher()
            }
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

            val web = webRenderer?.webView
            if (web?.canGoBack() == true) {
                web.goBack()
            } else {
                finish()
            }
        }
    }

    /**
     * Lazily create the WebRenderer (WebView + WebViewManager). Main thread
     * only. Idempotent — returns the existing renderer if one exists.
     */
    fun ensureWebRenderer(): com.nativephp.mobile.network.WebRenderer {
        check(Looper.myLooper() == Looper.getMainLooper()) {
            "WebRenderer must be created on the main thread"
        }
        return webRenderer ?: com.nativephp.mobile.network.WebRenderer(this, phpBridge)
            .also { webRenderer = it }
    }

    /** WebRenderer init callback: activity-owned wiring for a fresh WebView. */
    fun onWebRendererCreated(renderer: com.nativephp.mobile.network.WebRenderer) {
        webRenderer = renderer
        renderer.webView.addJavascriptInterface(AndroidBridge(), "AndroidBridge")
        if (hotReloadWatcherThread != null) {
            // Watcher predates the renderer (native-first boot): apply the
            // dev no-cache mode it would have set at startup.
            renderer.webView.settings.cacheMode = android.webkit.WebSettings.LOAD_NO_CACHE
        }
        pendingInsets?.let {
            injectSafeAreaInsets(it.left, it.top, it.right, it.bottom)
        }
        injectJavaScript(renderer.webView)
    }

    /**
     * Boot a native session by dispatching the route directly over JNI —
     * the WebView-free primary path. The dispatch blocks the npui-boot
     * thread for the life of the native session; its eventual HTTP response
     * is the session's exit envelope:
     *   3xx + Location  → EXIT_WEB: create the renderer, load the web page
     *   204             → hot-restart: the watcher re-executes; nothing to do
     *   anything else   → session ended: nothing left to show → finish()
     */
    private fun startNativeSession(uri: String) {
        val sessionThread = Thread({
            try {
                Log.d("NativeBoot", "🚀 Direct native dispatch: $uri")
                val request = com.nativephp.mobile.network.PHPRequest(
                    url = uri,
                    method = "GET",
                    body = "",
                    headers = mapOf("Accept" to "text/html"),
                    getParameters = emptyMap()
                )
                val raw = phpBridge.handleLaravelRequest(request)
                handleNativeSessionExit(raw)
            } catch (e: Exception) {
                Log.e("NativeBoot", "Native session failed: ${e.message}", e)
                runOnUiThread {
                    if (!isFinishing && !isDestroyed) {
                        // Fall back to the legacy path rather than wedge on splash.
                        ensureWebRenderer().webView.loadUrl("http://127.0.0.1$uri")
                    }
                }
            }
        }, "npui-boot")
        nativeUIThread = sessionThread
        sessionThread.start()
    }

    private fun handleNativeSessionExit(rawResponse: String) {
        // Parse just the status line + Location header from the raw response.
        val head = rawResponse.substringBefore("\r\n\r\n")
        val statusLine = head.lineSequence().firstOrNull() ?: ""
        val status = statusLine.split(" ").getOrNull(1)?.toIntOrNull() ?: 200
        val location = head.lineSequence()
            .firstOrNull { it.startsWith("Location:", ignoreCase = true) }
            ?.substringAfter(":")?.trim()

        Log.d("NativeBoot", "Native session exited: status=$status location=$location")

        when {
            status in 300..399 && !location.isNullOrEmpty() -> {
                val path = if (location.startsWith("http")) {
                    android.net.Uri.parse(location).encodedPath ?: "/"
                } else {
                    location
                }
                runOnUiThread { exitToWeb(path) }
            }
            status == 204 -> {
                // Hot restart in flight — the reload watcher re-executes the
                // route on a fresh thread. Nothing to do here.
                Log.d("NativeBoot", "Native session yielded for hot restart")
            }
            else -> runOnUiThread {
                if (!isFinishing && !isDestroyed && !NativeUIBridge.isActive.value) {
                    Log.d("NativeBoot", "Native stack empty — finishing activity")
                    finish()
                }
            }
        }
    }

    /**
     * EXIT_WEB: swap from the native tree to a (possibly brand-new) WebView.
     * Commit-gated — the frozen native tree stays up until the web page's
     * first visible commit so Chromium init never flashes blank.
     */
    private fun exitToWeb(path: String) {
        if (isFinishing || isDestroyed) return
        Log.d("NativeBoot", "⇄ EXIT_WEB → $path")
        pendingWebSwap = true
        val renderer = ensureWebRenderer()
        renderer.webView.loadUrl("http://127.0.0.1$path")
    }

    /**
     * Jump webview-forward session entry: same commit-gated swap as
     * EXIT_WEB — the native tree (Jump home) stays visible through Chromium
     * init until the forwarded page's first commit. Called by the discovery
     * plugin's JumpBridgeRelay after JumpWebViewSession.start(); requests
     * from the loaded page are forwarded to the remote dev server by
     * WebViewManager while the session is active.
     */
    fun jumpWebViewSwap(path: String = "/") {
        exitToWeb(path)
    }

    /**
     * Renderer-agnostic first-content signal: fired by the first framed
     * native element tree (MainScreen LaunchedEffect) or the first web page
     * commit (WebViewManager.onPageCommitVisible). One-shot.
     */
    fun onFirstContent(source: String) {
        if (firstContentReported) return
        firstContentReported = true
        Log.d("MainActivity", "🎨 First content ($source)")

                // The two lines below keep their original 12-space indent: splash-screen
                // plugins (s2br/nativephp-mobile-splashscreen) patch them via exact-string
                // match including that indentation. Do not re-indent.
            // Hide splash screen after URL is loaded
            showSplash = false

        // Report the app as fully drawn so cold-start TTFD is measured against
        // real content (Macrobenchmark / Play Console vitals) instead of an
        // implicit first frame.
        try {
            reportFullyDrawn()
        } catch (t: Throwable) {
            Log.w("MainActivity", "reportFullyDrawn failed: ${t.message}")
        }
    }

    /**
     * Native-first boots have no WebView error page to fall back on: if PHP
     * never publishes a tree (broken boot), nothing would ever dismiss the
     * splash. After 10s, fall back to the legacy WebView path so the user at
     * least sees Laravel's error output.
     */
    private fun startFirstContentWatchdog() {
        Handler(Looper.getMainLooper()).postDelayed({
            if (!firstContentReported && !isFinishing && !isDestroyed) {
                Log.w("MainActivity", "⏰ No content 10s after native boot — falling back to WebView")
                val target = LaravelEnvironment.getStartURL(this)
                ensureWebRenderer().webView.loadUrl("http://127.0.0.1$target")
                onFirstContent("watchdog")
            }
        }, 10_000L)
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

        // Push a native AppearanceChanged event to PHP when the theme flips.
        // onConfigurationChanged also fires on rotation, so guard on an actual
        // change. Drives reactive System::appearance() / #[On(AppearanceChanged)].
        val mode = if ((newConfig.uiMode and Configuration.UI_MODE_NIGHT_MASK) ==
                Configuration.UI_MODE_NIGHT_YES) "dark" else "light"
        if (mode != lastAppearance) {
            lastAppearance = mode
            NativeElementBridge.sendNativeEvent(
                "Native\\Mobile\\Events\\System\\AppearanceChanged",
                org.json.JSONObject().put("mode", mode).toString()
            )
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
            // Hydrate the cookie store here rather than in onCreate: it's plain
            // SharedPreferences disk I/O, and nothing reads cookies until the first
            // PHP request — which can't happen before this thread's boot completes.
            // Keeps a blocking prefs read off the main thread / TTID path.
            LaravelCookieStore.init(applicationContext)

            // Register bridge functions + renderers BEFORE booting PHP — a service
            // provider may call a bridge function during boot, so registration must
            // precede it. These are cheap registry inserts; running them here (off
            // the main thread and ahead of the PHP boot) keeps them off the
            // cold-start critical path while preserving ordering.
            Log.d("MainActivity", "🔌 Registering bridge functions...")
            registerBridgeFunctions(this, applicationContext)
            registerNativeChromeRenderers()
            registerPluginRenderers()
            Log.d("MainActivity", "✅ Bridge functions registered")

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

                    // NOTE: the background queue worker is NOT started here. It
                    // boots a second full Laravel runtime that would contend for
                    // CPU during first paint, so it's deferred to the onReady
                    // callback (see WORKER_START_DELAY_MS).
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
        registerShakeDetector()
        // Appearance gets a config-change nudge when the theme flipped while
        // we were away; thermal has no equivalent, so re-read and emit only
        // if the OS value drifted.
        ThermalStateMonitor.syncIfChanged(this)
    }

    override fun onPause() {
        super.onPause()
        NativePHPLifecycle.post(NativePHPLifecycle.Events.ON_PAUSE)
        sensorManager?.unregisterListener(shakeDetector)
    }

    /**
     * Lazily wires the accelerometer shake detector. On shake it forwards a
     * native event to PHP — `Native\Mobile\Events\Motion\ShakeDetected`,
     * consumed via `#[On(ShakeDetected::class)]`.
     */
    private fun registerShakeDetector() {
        if (sensorManager == null) {
            sensorManager = getSystemService(Context.SENSOR_SERVICE) as? SensorManager
            shakeDetector = ShakeDetector {
                NativeElementBridge.sendNativeEvent(
                    "Native\\Mobile\\Events\\Motion\\ShakeDetected",
                    "{}"
                )
            }
        }
        sensorManager?.getDefaultSensor(Sensor.TYPE_ACCELEROMETER)?.let { accelerometer ->
            sensorManager?.registerListener(shakeDetector, accelerometer, SensorManager.SENSOR_DELAY_UI)
        }
    }

    /**
     * Navigate an already-running app to a deep-link route.
     *
     * In native-UI apps the single PHP event loop is blocked running the current
     * screen, so webView.loadUrl() for a Route::native screen never routes — the
     * request queues behind the running loop and the link is silently dropped.
     * Instead wake the loop with a `__deeplink` native event carrying the route;
     * NativeComponent::dispatchNativeEvent turns it into a NavigationIntent::NAVIGATE
     * and NativeRouter pushes the screen (same path as an in-app @tap navigate).
     * WebView/Inertia apps keep the direct loadUrl().
     */
    private fun navigateWarm(route: String) {
        if (NativeUIBridge.isActive.value) {
            val escaped = route.replace("\\", "\\\\").replace("\"", "\\\"")
            Log.d("DeepLink", "🚀 native-ui: dispatching __deeplink event: $route")
            NativeElementBridge.sendNativeEvent("__deeplink", "{\"uri\":\"$escaped\"}")
        } else {
            val fullUrl = "http://127.0.0.1$route"
            Log.d("DeepLink", "🚀 Loading deep link immediately (app already running): $fullUrl")
            ensureWebRenderer().webView.loadUrl(fullUrl)
        }
        pendingDeepLink = null
    }

    private fun handleDeepLinkIntent(intent: Intent?) {
        // Check for notification URL extra (from local notification taps or foreground push)
        val notificationUrl = intent?.getStringExtra("notification_url")
        if (!notificationUrl.isNullOrEmpty()) {
            Log.d("DeepLink", "🔔 Notification URL: $notificationUrl")
            pendingDeepLink = notificationUrl
            if (::laravelEnv.isInitialized && bootReady) {
                navigateWarm(notificationUrl)
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
            if (::laravelEnv.isInitialized && bootReady) {
                navigateWarm(route)
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
        if (::laravelEnv.isInitialized && bootReady) {
            // Only navigate immediately once the boot pipeline is ready
            navigateWarm(laravelUrl)
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
        com.nativephp.mobile.security.LaravelCookieStore.clear()
        com.nativephp.mobile.security.WebCookieMirror.clearAll()
        Log.d("CookieInfo", "All cookies cleared")
    }


    override fun onDestroy() {
        super.onDestroy()
        instance = null

        // PARK the persistent runtime instead of tearing it down — and do
        // it FIRST, while the element region is still intact, or the
        // SHUTDOWN wake event has nowhere to land and the runloop parks
        // forever. The process can outlive this activity (plugin
        // foreground services pin it), and native PHP does not survive
        // teardown + re-init in the same process: classic embed re-init
        // SEGVs in ts_resource_ex, and nativePersistentBoot after
        // nativePersistentShutdown hangs. Parking just ends the element
        // runloop; a re-created activity reuses the booted runtime
        // (bootPersistentRuntime's guard), and when nothing pins the
        // process the OS reclaims it all anyway — the old explicit
        // teardown (shutdownPersistentRuntime + cleanup + shutdown)
        // bought nothing but the wedge.
        if (phpBridge.isPersistentMode()) {
            phpBridge.parkPersistentRuntime()
        }

        PerformanceTracker.detachFrameMetrics(window)

        // Post lifecycle event for plugins
        NativePHPLifecycle.post(NativePHPLifecycle.Events.ON_DESTROY)

        // Clean up coordinator fragment to prevent memory leaks
        if (::coord.isInitialized) {
            supportFragmentManager.beginTransaction()
                .remove(coord)
                .commitNowAllowingStateLoss()
        }

        // Dismiss any fullscreen custom view (video) before teardown.
        webRenderer?.webView?.webChromeClient?.onHideCustomView()

        // Stop hot reload watcher thread
        shouldStopWatcher = true
        hotReloadWatcherThread?.interrupt()

        // Stop native UI tree watcher
        NativeUIBridge.stopWatching()

        // Stop background queue worker
        queueWorker?.stop()

        ThermalStateMonitor.stop(this)
    }

    override fun getWebView(): WebView {
        // Lazy-create for consumers that insist on a WebView (plugin code).
        // Slow the first time on a native-first boot, but correct.
        return ensureWebRenderer().webView
    }

    override fun getWebViewOrNull(): WebView? = webRenderer?.webView

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
        // Configure WebView for development - disable caching for hot reload.
        // On a native-first boot no renderer exists yet; onWebRendererCreated
        // applies the same mode when one appears.
        webRenderer?.webView?.settings?.cacheMode = android.webkit.WebSettings.LOAD_NO_CACHE

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
                                // will be destroyed by php_module_shutdown.
                                // Embedded php-mode webviews own contexts with the
                                // same hazard; shutdownPersistentRuntime() suspends
                                // those itself.
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
                                val web = webRenderer?.webView ?: run {
                                    Log.d("HotReload", "No WebView and native UI inactive — skipping web reload")
                                    return@runOnUiThread
                                }
                                web.stopLoading()
                                web.clearCache(true)
                                web.clearHistory()
                                web.clearFormData()

                                val currentUrl = web.url ?: "http://127.0.0.1/"
                                val separator = if (currentUrl.contains("?")) "&" else "?"
                                val cacheBustUrl = "${currentUrl}${separator}_cb=${System.currentTimeMillis()}"

                                Handler(Looper.getMainLooper()).postDelayed({
                                    web.loadUrl(cacheBustUrl)
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
        val topPx = (top / density).toInt()
        val rightPx = (right / density).toInt()
        val bottomPx = (bottom / density).toInt()

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
        webRenderer?.webView?.evaluateJavascript(jsCode, null)
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

        val jsCode = if (isVisible) {
            "document.body.classList.add('keyboard-visible');"
        } else {
            "document.body.classList.remove('keyboard-visible');"
        }
        webRenderer?.webView?.evaluateJavascript(jsCode, null)
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

        ensureWebRenderer().webView.evaluateJavascript(jsCode, null)
    }

    /**
     * Main Compose UI screen with WebView, native tree, and overlays.
     * WebView-mode chrome (the Edge-bridge top bar / bottom nav / side
     * drawer / FAB) is gone — native chrome now comes exclusively from
     * the element-collector path (NativeRootStack / NativeRootTabs).
     */
    @Composable
    private fun MainScreen() {
        Box(Modifier.fillMaxSize()) {
            // Scaffold retained for its padding contract with the
            // WebView below; edge-to-edge via zero window insets.
            Scaffold(
                contentWindowInsets = WindowInsets(0, 0, 0, 0)
            ) { paddingValues ->
                        // Main content: WebView only.
                        // No IME padding here — resizing the WebView mid-animation
                        // reflows 100vh/fixed layouts. adjustResize + Chromium handle
                        // the visual viewport and focused-field scrolling.

                        Box(modifier = Modifier.fillMaxSize()) {
                            // Real either/or: the native tree and the WebView are
                            // alternative renderers, not overlay-on-top. The WebView
                            // only exists (and only rasters) when a WebRenderer has
                            // been created AND no native tree is active. Detaching
                            // the AndroidView preserves the WebView's DOM/history —
                            // the instance lives in WebRenderer, not the composition.
                            val nativeUIActive by NativeUIBridge.isActive
                            val renderer = webRenderer

                            if (renderer != null && !nativeUIActive) {
                                AndroidView(
                                    factory = { renderer.webView },
                                    modifier = Modifier
                                        .fillMaxSize()
                                        .padding(paddingValues)
                                        .consumeWindowInsets(paddingValues),
                                    update = { view ->
                                        // Force layout recalculation when Compose size changes
                                        // This ensures viewport units (100vh, 100vw) work correctly
                                        view.requestLayout()
                                    }
                                )
                            }

                            if (nativeUIActive) {
                                Box(
                                    modifier = Modifier
                                        .fillMaxSize()
                                        .background(MaterialTheme.colorScheme.background)
                                ) {
                                    NativeUIContent()
                                }
                                // First-content signal, native renderer: the tree is
                                // composed; two frame callbacks = composed + drawn.
                                LaunchedEffect(Unit) {
                                    androidx.compose.runtime.withFrameNanos { }
                                    androidx.compose.runtime.withFrameNanos { }
                                    onFirstContent("native-tree")
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

        // Decode the full-screen splash bitmap OFF the main thread. painterResource
        // decodes synchronously inside the first composition — directly on the TTID
        // critical path (tens of ms for a full-screen PNG). The first frame paints
        // solid black (identical to the theme's windowBackground, so there's no
        // visible seam) and the image fades in as soon as the decode lands.
        var splashBitmap by remember { mutableStateOf<androidx.compose.ui.graphics.ImageBitmap?>(null) }
        LaunchedEffect(splashResourceId) {
            if (splashResourceId != 0) {
                splashBitmap = withContext(Dispatchers.IO) {
                    try {
                        android.graphics.BitmapFactory
                            .decodeResource(resources, splashResourceId)
                            ?.asImageBitmap()
                    } catch (t: Throwable) {
                        Log.w("Splash", "Failed to decode splash: ${t.message}")
                        null
                    }
                }
            }
        }

        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(Color.Black),
            contentAlignment = Alignment.Center
        ) {
            val bitmap = splashBitmap
            if (bitmap != null) {
                // MutableTransitionState(false) → targetState = true makes the
                // fade-in play on the Image's FIRST composition (plain
                // AnimatedVisibility(visible = true) would skip it).
                val fadeInState = remember {
                    androidx.compose.animation.core.MutableTransitionState(false)
                }.apply { targetState = true }
                AnimatedVisibility(
                    visibleState = fadeInState,
                    enter = fadeIn(animationSpec = tween(150))
                ) {
                    Image(
                        bitmap = bitmap,
                        contentDescription = "App splash screen",
                        modifier = Modifier.fillMaxSize(),
                        contentScale = ContentScale.Crop
                    )
                }
            } else if (splashResourceId == 0) {
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
     * The app's M3 [ColorScheme]. Resolved through [NativeUIThemeProvider] so
     * core doesn't depend on any UI plugin: a plugin (native-ui) registers a
     * provider that maps its PHP-driven theme tokens reactively; with no UI
     * plugin installed this falls back to Material defaults and still builds.
     */
    @Composable
    private fun nativeUiMaterialColorScheme(isDark: Boolean): ColorScheme {
        return NativeUIThemeProvider.resolve(isDark)
    }

    inner class AndroidBridge {
        @android.webkit.JavascriptInterface
        fun openDrawer() {
            // The Edge-bridge side drawer is gone (chrome now comes from the
            // element-collector path). The JS interface survives so pages
            // calling window.AndroidBridge.openDrawer() don't throw.
            Log.w("AndroidBridge", "openDrawer() is no longer supported — the WebView-mode side drawer was removed")
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
