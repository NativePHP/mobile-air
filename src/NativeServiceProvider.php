<?php

namespace Native\Mobile;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Native\Mobile\Commands\BuildIosAppCommand;
use Native\Mobile\Commands\CheckBuildNumberCommand;
use Native\Mobile\Commands\CredentialsCommand;
use Native\Mobile\Commands\DebugCommand;
use Native\Mobile\Commands\DevicesCommand;
use Native\Mobile\Commands\InstallCommand;
use Native\Mobile\Commands\JumpCommand;
use Native\Mobile\Commands\LaunchEmulatorCommand;
use Native\Mobile\Commands\MakeNativeComponentCommand;
use Native\Mobile\Commands\MakeNativeTestCommand;
use Native\Mobile\Commands\OpenProjectCommand;
use Native\Mobile\Commands\PackageCommand;
use Native\Mobile\Commands\PluginBoostCommand;
use Native\Mobile\Commands\PluginCreateCommand;
use Native\Mobile\Commands\PluginInstallAgentCommand;
use Native\Mobile\Commands\PluginListCommand;
use Native\Mobile\Commands\PluginMakeHookCommand;
use Native\Mobile\Commands\PluginRegisterCommand;
use Native\Mobile\Commands\PluginUninstallCommand;
use Native\Mobile\Commands\PluginValidateCommand;
use Native\Mobile\Commands\ReleaseCommand;
use Native\Mobile\Commands\RemoveNativeComponentCommand;
use Native\Mobile\Commands\RunCommand;
use Native\Mobile\Commands\ScreenshotCommand;
use Native\Mobile\Commands\SimCommand;
use Native\Mobile\Commands\StatusCommand;
use Native\Mobile\Commands\TailCommand;
use Native\Mobile\Commands\ValidateCommand;
use Native\Mobile\Commands\VersionCommand;
use Native\Mobile\Commands\WatchCommand;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\Contracts\NativeRouteFallback;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\Events\System\AppearanceChanged;
use Native\Mobile\Http\Middleware\HonorsRequestedNativeScreen;
use Native\Mobile\Plugins\Compilers\AndroidPluginCompiler;
use Native\Mobile\Plugins\Compilers\IOSPluginCompiler;
use Native\Mobile\Plugins\PluginDiscovery;
use Native\Mobile\Plugins\PluginRegistry;
use Native\Mobile\Support\Ios\PhpUrlGenerator;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class NativeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('nativephp-mobile')
            ->hasConfigFile('nativephp')
            ->hasViews()
            ->hasRoute('api')
            ->hasCommands([
                PackageCommand::class,
                BuildIosAppCommand::class,
                CheckBuildNumberCommand::class,
                CredentialsCommand::class,
                DebugCommand::class,
                InstallCommand::class,
                RunCommand::class,
                OpenProjectCommand::class,
                LaunchEmulatorCommand::class,
                SimCommand::class,
                ReleaseCommand::class,
                JumpCommand::class,
                WatchCommand::class,
                TailCommand::class,
                DevicesCommand::class,
                ScreenshotCommand::class,
                StatusCommand::class,
                VersionCommand::class,
                PluginBoostCommand::class,
                PluginCreateCommand::class,
                PluginInstallAgentCommand::class,
                PluginListCommand::class,
                PluginMakeHookCommand::class,
                PluginRegisterCommand::class,
                PluginUninstallCommand::class,
                PluginValidateCommand::class,
                MakeNativeComponentCommand::class,
                MakeNativeTestCommand::class,
                RemoveNativeComponentCommand::class,
                ValidateCommand::class,
            ]);
    }

    public function packageRegistered()
    {
        // Load global helpers here too — not only via composer `autoload.files`.
        // The dev sync copies src/ into an app's vendor without re-dumping the
        // app autoloader, so the files-autoload entry wouldn't take effect until
        // `composer dump-autoload`. Requiring it on boot makes isDark()/etc.
        // available immediately after a sync. function_exists() guards inside
        // keep it idempotent when the autoloader has already loaded it.
        require_once __DIR__.'/helpers.php';

        $this->mergeConfigFrom($this->package->basePath('/../config/nativephp-internal.php'), 'nativephp-internal');

        $this->publishPluginsServiceProvider();
        $this->registerCoreFacades();
        $this->registerPluginServices();
        $this->prepForIos();
        $this->registerJumpBridgeFallback();

        // Laravel's ServeCommand only forwards a whitelisted set of env vars to
        // its PHP built-in server children. Without this, JUMP_BRIDGE_PORT/JUMP_WS_PORT
        // set by `native:jump` are stripped before reaching the Livewire request
        // handler, so JumpBridge falls back to port 3002 blindly.
        if (class_exists(ServeCommand::class)) {
            ServeCommand::$passthroughVariables = array_values(array_unique(array_merge(
                ServeCommand::$passthroughVariables,
                ['JUMP_BRIDGE_PORT', 'JUMP_WS_PORT']
            )));
        }
    }

    protected function publishPluginsServiceProvider(): void
    {
        $this->publishes([
            __DIR__.'/../resources/stubs/NativeServiceProvider.php.stub' => app_path('Providers/NativeServiceProvider.php'),
        ], 'nativephp-plugins-provider');
    }

    /**
     * Bind facades that were previously supplied by standalone plugins and are
     * now core built-ins (their native bridge functions ship in core's
     * BridgeFunctionRegistration). Mirrors the singleton binding each plugin's
     * ServiceProvider used to do — so `Device::…` resolves with no plugin
     * installed. Dialog/File/System follow the same pattern as they migrate.
     */
    /**
     * Keep query-side caches in sync with their push events. When the OS flips
     * the theme, AppearanceChanged fires (and auto-dispatches globally); this
     * listener updates System's cached appearance so `System::appearance()` /
     * `isDark()` stay fresh without re-probing the bridge.
     */
    protected function registerSystemEventListeners(): void
    {
        Event::listen(
            AppearanceChanged::class,
            fn (AppearanceChanged $e) => System::rememberAppearance($e->mode),
        );
    }

    protected function registerCoreFacades(): void
    {
        $this->app->singleton(Device::class, fn () => new Device);
        $this->app->singleton(System::class, fn () => new System);
        $this->app->singleton(Dialog::class, fn () => new Dialog);
        $this->app->singleton(File::class, fn () => new File);
    }

    protected function registerPluginServices(): void
    {
        $this->app->singleton(PluginDiscovery::class, function ($app) {
            return new PluginDiscovery(
                $app->make(Filesystem::class),
                base_path()
            );
        });

        $this->app->singleton(PluginRegistry::class, function ($app) {
            return new PluginRegistry(
                $app->make(PluginDiscovery::class)
            );
        });

        $this->app->singleton(AndroidPluginCompiler::class, function ($app) {
            return new AndroidPluginCompiler(
                $app->make(Filesystem::class),
                $app->make(PluginRegistry::class),
                base_path('nativephp')
            );
        });

        $this->app->singleton(IOSPluginCompiler::class, function ($app) {
            return new IOSPluginCompiler(
                $app->make(Filesystem::class),
                $app->make(PluginRegistry::class),
                base_path('nativephp')
            );
        });
    }

    public function boot()
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/resources/views', 'nativephp-mobile');
        $this->loadViewsFrom(__DIR__.'/../resources/jump/views', 'jump');

        // Register `resources/views/native` as a primary view-finder
        // location (mirrors Livewire's `resources/views/livewire`
        // convention). Lets devs write `view('home')` in their
        // `render()` instead of `view('native.home')`.
        //
        // Unconditional on purpose: Laravel-aware IDE plugins
        // (Laravel Idea, PhpStorm Laravel support, Intelephense)
        // scan service-provider code STATICALLY to find view paths
        // to index. They can't evaluate `is_dir(...)` at scan time —
        // a conditional registration is skipped by the indexer, and
        // CMD-click on view names stops resolving. Laravel's
        // view-finder tolerates a missing path at runtime (it just
        // won't find any views there), so the guard wasn't buying us
        // anything.
        app('view')->addLocation(resource_path('views/native'));

        // Native-first boot manifest refresh: re-dump the registered
        // Route::native patterns after every boot so the device-side
        // BootPlanner survives hot reload adding/removing native routes
        // between builds. Version-stamped; Kotlin prefers this file over
        // the bundle_meta.json bake when the versions match. Skipped off
        // device (no NATIVEPHP_RUNNING) and in tests.
        $this->app->booted(function () {
            if (! env('NATIVEPHP_RUNNING') || app()->runningUnitTests()) {
                return;
            }
            try {
                $routes = array_keys(NativeRouter::registeredRoutes());
                file_put_contents(
                    storage_path('framework/native_routes.json'),
                    json_encode([
                        'version' => config('nativephp.version'),
                        'routes' => $routes,
                    ])
                );
            } catch (\Throwable $e) {
                // Never let manifest bookkeeping affect a real request.
            }
        });
    }

    public function packageBooted()
    {
        $this->setupComposerPostUpdateScript();
        $this->registerSystemEventListeners();
        $this->registerNativeComponents();
        $this->registerChildComponents();
        $this->registerCoreElements();
        $this->registerUiPluginComponents();
        $this->registerFilesystems();
        $this->registerBladeDirectives();
        $this->configureViteHotFile();
        $this->applyFpsOverlayConfig();
        $this->registerScreenIntentMiddleware();

        if (config('nativephp-internal.running')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $blade = app('blade.compiler');

        // Build the bare-tag allowlist from registered element types so
        // `<column>` / `<row>` / `<button>` etc. compile the same way as
        // `<native:column>` / `<native:row>` / `<native:button>`. Types
        // are snake_case (`scroll_view`) and tags are kebab-case
        // (`scroll-view`) — convert here and the precompiler matches on
        // the kebab form like it already does for the prefixed syntax.
        $shortFormTags = array_map(
            fn (string $type) => str_replace('_', '-', $type),
            array_keys(ElementRegistry::all()),
        );

        $blade->precompiler(new NativeTagPrecompiler($shortFormTags));

        // During a native render, force-recompile any view whose cached
        // compiled file wasn't produced with the native precompiler active
        // (no marker — e.g. a web render or `view:cache` compiled it
        // first). Covers nested @includes; the root view gets the same
        // check in renderBladeBoundToSelf().
        //
        // Registered as a view creator rather than a replacement 'blade'
        // engine: the engine resolver is a single slot, and Livewire parks
        // its ExtendedCompilerEngine there to bind `$this` inside component
        // views — replacing it breaks every `$this->...` in Livewire blades.
        // Creators are multi-listener and fire before each view (including
        // nested @includes) evaluates, which is all this guard needs.
        $this->app['view']->creator('*', function ($view) {
            if (! NativeTagPrecompiler::active()) {
                return;
            }

            $path = $view->getPath();

            if (! str_ends_with($path, '.blade.php')) {
                return;
            }

            $compiler = $this->app['blade.compiler'];

            if (! NativeTagPrecompiler::compiledFileIsNative($compiler->getCompiledPath($path))) {
                $compiler->compile($path);
            }
        });

        Route::macro('native', function (string $uri, string $componentClass) {
            NativeRouter::register($uri, $componentClass);

            return Route::get($uri, function () use ($componentClass) {
                // Native route reached without a native runtime — a shared
                // app link opened in a plain browser, a crawler, a
                // misconfigured deploy. The runloop can never satisfy these
                // (no device is attached to the request), so if the app
                // bound a fallback, let it answer (landing page, app-store
                // redirect). Unbound, everything below is unchanged.
                if (! env('NATIVEPHP_RUNNING') && ! config('nativephp-internal.running')
                    && app()->bound(NativeRouteFallback::class)) {
                    return app(NativeRouteFallback::class)->handle($componentClass);
                }

                // HTTP feature tests ($this->get('/')) must never enter the
                // runloop: it blocks in wait_event against the REAL bridge —
                // with a live Jump session that's ~90s of reconnect spinning
                // per request. Answer 200 so route-level smoke tests pass,
                // and point at the component harness for actual coverage.
                if (app()->runningUnitTests()) {
                    return response(
                        "Native screen [{$componentClass}] — test it with Native::test() / Native::visit().",
                        200
                    );
                }

                $router = new NativeRouter;
                $path = '/'.ltrim(request()->path(), '/');
                $resolved = NativeRouter::resolve($path);
                $params = $resolved ? $resolved['params'] : [];

                // Hot-reload stack restoration. PHP wrote the full
                // navigation stack to `.hot_restart` when the user
                // saved a file; replay the entries below the current
                // top so back-button history survives the PHP reboot.
                // We delete the file here (PHP becomes the sole
                // consumer); iOS / Android just peek at it for the
                // top URI to dispatch.
                $restartPath = storage_path('framework/.hot_restart');
                if (is_file($restartPath)) {
                    $raw = @file_get_contents($restartPath);
                    $data = $raw ? @json_decode($raw, true) : null;
                    $age = time() - (int) ($data['ts'] ?? 0);

                    // The hot-reload re-exec lands on the WebView's URL (almost
                    // always "/"), but the user may have navigated deeper before
                    // saving. Redirect to the saved top screen so ITS route
                    // restores onto it — otherwise the entry screen ("/") gets
                    // pushed on top of the restored stack and the user is dumped
                    // back at root. Leave .hot_restart in place; the target
                    // route is the sole consumer. Normalize for a stable compare
                    // so we can't redirect-loop.
                    $savedTop = is_array($data) ? ($data['uri'] ?? null) : null;
                    $savedTopNorm = $savedTop !== null ? '/'.ltrim($savedTop, '/') : null;
                    if ($age <= 30 && $savedTopNorm && $savedTopNorm !== $path) {
                        return redirect($savedTopNorm);
                    }

                    @unlink($restartPath);
                    $stack = is_array($data['stack'] ?? null) ? $data['stack'] : [];
                    // Drop the entry matching the current request URI —
                    // `start()` will push that as the top itself.
                    if ($age <= 30 && ! empty($stack)) {
                        $last = end($stack);
                        if (is_array($last) && ($last['uri'] ?? null) === $path) {
                            array_pop($stack);
                        }
                        if (! empty($stack)) {
                            $router->preloadStack($stack);
                        }
                    }
                }

                $exitUri = $router->start($componentClass, $params, $path);

                if ($exitUri !== null) {
                    return redirect($exitUri);
                }

                // Hot reload exit — return 204 so the WebView doesn't load stale content
                if (file_exists(storage_path('framework/.hot_restart'))) {
                    return response()->noContent();
                }

                return '';
            });
        });

        // Route::nativeGroup(layout: TabsLayout::class, function () { ... })
        // Routes registered inside the closure inherit the group's layout
        // unless they call ->layout(...) themselves to override.
        Route::macro('nativeGroup', function (string $layout, \Closure $routes) {
            NativeRouter::beginGroup($layout);
            try {
                $routes();
            } finally {
                NativeRouter::endGroup();
            }
        });

        // Fluent ->layout() chaining on the Route returned by Route::native().
        // Example:
        //     Route::native('/item/{id}', ItemDetail::class)
        //         ->layout(StackLayout::class);
        \Illuminate\Routing\Route::macro('layout', function (string $layoutClass) {
            NativeRouter::setLayout($this->uri, $layoutClass);

            return $this;
        });
    }

    protected function registerBladeDirectives(): void
    {
        Blade::if('mobile', function () {
            return Facades\System::isMobile();
        });

        Blade::if('web', function () {
            return ! Facades\System::isMobile();
        });

        Blade::if('ios', function () {
            return Facades\System::isIos();
        });

        Blade::if('android', function () {
            return Facades\System::isAndroid();
        });

        Blade::directive('nativeError', function ($expression) {
            return "<?php
                \$__nativeErrorArgs = [{$expression}];
                \$__nativeErrorField = \$__nativeErrorArgs[0];
                \$__nativeErrorColor = \$__nativeErrorArgs[1] ?? '#FF0000';
                if (isset(\$errors) && is_array(\$errors) && !empty(\$errors[\$__nativeErrorField])) {
                    \\Native\\Mobile\\Edge\\NativeElementCollector::leaf('text', [
                        'text' => \$errors[\$__nativeErrorField],
                        'color' => \$__nativeErrorColor,
                        'fontSize' => 12,
                    ]);
                }
            ?>";
        });
    }

    protected function registerFilesystems(): void
    {
        // Only register these filesystems when running in a NativePHP shell app
        if (! config('nativephp-internal.running')) {
            return;
        }

        $tempDir = config('nativephp-internal.tempdir');

        // Only register if we have a valid temp directory path
        if (empty($tempDir)) {
            return;
        }

        // Dynamically add the temp disk to the filesystems config
        config([
            'filesystems.disks.mobile_public' => [
                'driver' => 'local',
                'root' => storage_path('app/public'),
                'url' => config('app.url').'/_assets/storage',
                'visibility' => 'public',
                'throw' => false,
                'report' => false,
            ],
            'filesystems.disks.temp' => [
                'driver' => 'local',
                'root' => $tempDir,
                'throw' => false,
            ],
        ]);
    }

    private function prepForIos()
    {

        if (! config('nativephp-internal.running')) {
            return;
        }

        if (PHP_OS_FAMILY !== 'Darwin') {
            return;
        }

        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();

            $app->instance('routes', $routes);

            return new PhpUrlGenerator(
                $routes,
                $app->rebinding(
                    'request',
                    function ($app, $request) {
                        $app['url']->setRequest($request);
                    }
                ),
                $app['config']['app.asset_url']
            );
        });
    }

    /**
     * Configure Vite to use platform-specific hot file paths.
     *
     * This allows iOS and Android to have separate hot files (ios-hot, android-hot)
     * so that both platforms can run simultaneously with their own Vite dev servers.
     */
    private function configureViteHotFile(): void
    {
        // Only configure when running inside NativePHP
        if (! config('nativephp-internal.running')) {
            return;
        }

        $hotFile = match (config('nativephp-internal.platform')) {
            'ios' => public_path('ios-hot'),
            'android' => public_path('android-hot'),
            default => public_path('hot'),
        };

        Vite::useHotFile($hotFile);
    }

    /**
     * Push the `nativephp.fps_overlay` config flag to the native side at
     * boot so the iOS/Android FPS overlay turns on/off based purely on
     * the dev's `.env` / config without touching native code.
     */
    private function applyFpsOverlayConfig(): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        // Never at test boot: on a dev machine nativephp_call is the Jump
        // TCP polyfill, and with a live Jump session this call blocks on a
        // real device round-trip — adding ~1s to EVERY test's app boot
        // (the FakeBridge can't intercept it; tests bind it after boot).
        if ($this->app->runningUnitTests()) {
            return;
        }

        $enabled = (bool) config('nativephp.fps_overlay', false);

        nativephp_call('Perf.SetFpsOverlayEnabled', json_encode(['enabled' => $enabled]));
    }

    /**
     * Let a screen change requested from `native:watch` be picked up by an
     * ordinary request, not just by the runloop's hot-reload handler — that's
     * the only way back to a native screen once the app has fallen through to
     * the WebView (a 404, say), where no runloop exists to read the intent.
     *
     * On device only: NATIVEPHP_PLATFORM is set by the iOS and Android hosts,
     * so a normal web app never pays for this.
     */
    private function registerScreenIntentMiddleware(): void
    {
        if (! in_array(env('NATIVEPHP_PLATFORM'), ['ios', 'android'], true)) {
            return;
        }

        // Deliberately not gated on runningInConsole(): that reads PHP_SAPI,
        // which the embedded runtime does not necessarily report as a web SAPI,
        // and getting it wrong would silently disable the middleware. Pushing
        // it in a console context is harmless — nothing dispatches a request.
        $this->app->make(HttpKernel::class)->pushMiddleware(HonorsRequestedNativeScreen::class);
    }

    private function setupComposerPostUpdateScript()
    {
        // Temporarily disabled for testing
        return;

        // Only run in console/CLI context to avoid web requests
        if (! $this->app->runningInConsole()) {
            return;
        }

        $composerPath = base_path('composer.json');

        if (! file_exists($composerPath)) {
            return;
        }

        try {
            $composerContent = json_decode(file_get_contents($composerPath), true);

            if (! is_array($composerContent)) {
                return;
            }

            // Use 'both' on macOS (supports iOS + Android), 'android' on other platforms
            $platform = PHP_OS_FAMILY === 'Darwin' ? 'both' : 'android';
            $nativeInstallCommand = "@php artisan native:install {$platform} --force";

            // Check if post-update-cmd already contains our command
            if (isset($composerContent['scripts']['post-update-cmd'])) {
                $postUpdateCmds = $composerContent['scripts']['post-update-cmd'];

                // Handle both string and array formats
                if (is_string($postUpdateCmds)) {
                    if ($postUpdateCmds === $nativeInstallCommand) {
                        return; // Already exists
                    }
                    // Check if it's an old version with different platform and replace it
                    if (preg_match('/@php artisan native:install (android|both|ios) --force/', $postUpdateCmds)) {
                        $composerContent['scripts']['post-update-cmd'] = $nativeInstallCommand;

                        return;
                    }
                    // Convert to array and add our command
                    $composerContent['scripts']['post-update-cmd'] = [$postUpdateCmds, $nativeInstallCommand];
                } elseif (is_array($postUpdateCmds)) {
                    if (in_array($nativeInstallCommand, $postUpdateCmds)) {
                        return; // Already exists
                    }

                    // Check for existing native:install commands with different platforms and replace them
                    $foundExisting = false;
                    foreach ($postUpdateCmds as $index => $cmd) {
                        if (preg_match('/@php artisan native:install (android|both|ios) --force/', $cmd)) {
                            $composerContent['scripts']['post-update-cmd'][$index] = $nativeInstallCommand;
                            $foundExisting = true;
                            break;
                        }
                    }

                    // If no existing command found, add to array
                    if (! $foundExisting) {
                        $composerContent['scripts']['post-update-cmd'][] = $nativeInstallCommand;
                    }
                }
            } else {
                // Create scripts section if it doesn't exist
                if (! isset($composerContent['scripts'])) {
                    $composerContent['scripts'] = [];
                }

                // Add our post-update-cmd
                $composerContent['scripts']['post-update-cmd'] = [$nativeInstallCommand];
            }

            // Write back to composer.json with pretty formatting
            $json = json_encode($composerContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            file_put_contents($composerPath, $json.PHP_EOL);

        } catch (\Exception $e) {
            // Silently fail to avoid breaking the application
            // Could optionally log this if needed
        }
    }

    /**
     * Register UI components from installed nativephp-ui-plugin packages.
     *
     * For each component declared in a UI plugin's manifest:
     * - Registers the Element class in ElementRegistry (for NativeElementCollector resolution)
     * - Registers the Blade component (for <native:vendor-component> tag support)
     */
    protected function registerUiPluginComponents(): void
    {
        try {
            $registry = $this->app->make(PluginRegistry::class);
        } catch (\Throwable) {
            return;
        }

        foreach ($registry->components() as $component) {
            $type = $component['type'];
            $elementClass = $component['element'];
            $bladeClass = $component['blade'];

            // Register in ElementRegistry so NativeElementCollector's default case resolves it
            // Skip types already registered as core elements
            if (class_exists($elementClass) && ! ElementRegistry::has($type)) {
                ElementRegistry::register($type, $elementClass);
            }

            // Convert type to kebab Blade tag name:
            // "button" → "native-button"
            // "stripe.payment_sheet" → "native-stripe-payment-sheet"
            $kebabName = str_replace(['.', '_'], '-', $type);
            $kebabName = ltrim(strtolower(preg_replace('/[A-Z]/', '-$0', $kebabName)), '-');

            if (class_exists($bladeClass)) {
                Blade::component("native-{$kebabName}", $bladeClass);
            }
        }
    }

    protected function registerCoreElements(): void
    {
        $elements = [
            // Layout
            'column' => Elements\Column::class,
            'row' => Elements\Row::class,
            'stack' => Elements\Stack::class,
            'scroll_view' => Elements\ScrollView::class,
            'spacer' => Elements\Spacer::class,

            // Content
            'text' => Elements\Text::class,
            'image' => Elements\Image::class,
            'icon' => Elements\Icon::class,

            // Input (core primitive only)
            'pressable' => Elements\Pressable::class,
            // `button`, `text_input`, `toggle`, `activity_indicator`, `bottom_sheet`
            // are registered by UI plugins (see nativephp/native-ui). Plugin
            // discovery can't override an existing registration, so these must
            // NOT be registered here.

            // Navigation chrome
            'top_bar' => Elements\TopBar::class,
            'top_bar_action' => Elements\TopBarAction::class,
            'top_bar_title' => Elements\TopBarTitle::class,
            'bottom_nav' => Elements\BottomNav::class,
            'bottom_nav_item' => Elements\BottomNavItem::class,
            'side_nav' => Elements\SideNav::class,
            'side_nav_item' => Elements\SideNavItem::class,
            'side_nav_group' => Elements\SideNavGroup::class,
            'side_nav_header' => Elements\SideNavHeader::class,
            'fab' => Elements\Fab::class,

            // Gesture / interaction
            'gesture_area' => Elements\GestureArea::class,
            'refreshable' => Elements\Refreshable::class,

            // Canvas/shapes
            'canvas' => Elements\Canvas::class,
            'rect' => Elements\Rect::class,
            'circle' => Elements\Circle::class,
            'line' => Elements\Line::class,
            'divider' => Elements\Divider::class,
        ];

        foreach ($elements as $type => $class) {
            ElementRegistry::register($type, $class);
        }
    }

    /**
     * Register pure PHP fallback for nativephp_call() when running on dev machine.
     *
     * On device, nativephp_call() is a C extension that calls into Swift/Kotlin.
     * On the dev machine (Jump hybrid mode), we define it as a PHP function that
     * sends bridge calls over TCP to the WebSocket server, which relays to the device.
     */
    protected function registerJumpBridgeFallback(): void
    {
        // Only register if the C extension function doesn't exist
        // (i.e., we're on the dev machine, not on device)
        if (function_exists('nativephp_call')) {
            return;
        }

        // Define the global nativephp_call function
        require_once __DIR__.'/jump_bridge_functions.php';
    }

    /**
     * Auto-discover app **child components** — NativeComponent subclasses
     * under app/NativeComponents (the `native:make` convention) — and
     * register them with the ComponentRegistry so `<native:user-card>`
     * mounts App\NativeComponents\UserCard as a nested component.
     *
     * Explicit ComponentRegistry::components() registrations made before
     * boot are never overridden, and registered element types always win
     * over component tags at resolution time (see NativeElementCollector).
     */
    protected function registerChildComponents(): void
    {
        $componentPath = app_path('NativeComponents');

        if (! is_dir($componentPath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($componentPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($componentPath.'/', '', $file->getPathname());
            $classPath = substr($relativePath, 0, -4);

            // Tag name from the class basename: UserCard → user-card.
            $kebabName = ltrim(strtolower(preg_replace('/[A-Z]/', '-$0', basename($classPath))), '-');

            if (ComponentRegistry::has($kebabName)) {
                continue;
            }

            $componentClass = 'App\\NativeComponents\\'.str_replace('/', '\\', $classPath);

            if (class_exists($componentClass) && is_subclass_of($componentClass, NativeComponent::class)) {
                ComponentRegistry::register($kebabName, $componentClass);
            }
        }
    }

    protected function registerNativeComponents(): void
    {
        $componentPath = __DIR__.'/Edge/Components';

        if (! is_dir($componentPath)) {
            return;
        }

        // Recursively find all PHP files in the Components directory
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($componentPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Get relative path from Components directory
            $relativePath = str_replace($componentPath.'/', '', $file->getPathname());

            // Remove .php extension
            $classPath = substr($relativePath, 0, -4);

            // Get just the class name for the component tag
            $className = basename($classPath);

            // Skip the abstract base class
            if ($className === 'NativeBladeComponent') {
                continue;
            }

            // Convert BottomNav -> bottom-nav
            $kebabName = ltrim(strtolower(preg_replace('/[A-Z]/', '-$0', $className)), '-');

            // Build the full namespaced class name (e.g., Native\Mobile\Edge\Components\Native\Column)
            $componentClass = 'Native\\Mobile\\Edge\\Components\\'.str_replace('/', '\\', $classPath);

            if (class_exists($componentClass)) {
                Blade::component("native-{$kebabName}", $componentClass);
            }
        }
    }
}
