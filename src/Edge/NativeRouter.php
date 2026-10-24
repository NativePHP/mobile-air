<?php

namespace Native\Mobile\Edge;

use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Events\Screen\ScreenMounted;
use Native\Mobile\Events\Screen\ScreenResumed;
use Native\Mobile\Events\Screen\ScreenUnmounted;
use Native\Mobile\Http\Bridge\RequestFactory;

class NativeRouter
{
    /**
     * Where `native:watch` leaves a screen-change intent for the app to pick
     * up, relative to base_path().
     *
     * base_path() rather than storage_path() on purpose: on device
     * storage_path() points into a private container the host can't reach,
     * whereas base_path() is exactly where the watcher already syncs files —
     * so the intent rides in on the same primitives as an edited Blade file.
     *
     * At the root rather than under storage/framework because that directory
     * is excluded from the bundle and so may not exist on device, and
     * `xcrun devicectl device copy to` has no way to create it.
     */
    public const SCREEN_INTENT_PATH = '.native_screen';

    /**
     * How long a screen-change intent stays actionable. Bounds how far into
     * the future an intent pushed while the app was closed can hijack a
     * later hot reload.
     */
    protected const SCREEN_INTENT_TTL = 60;

    /** Memoized once per process; see debugLoggingEnabled(). */
    private static ?bool $debugLogging = null;

    /**
     * Whether the edge-nav log is being written.
     *
     * This log is a development aid, but the runloop calls debugLog() at least
     * twice per tick — and a screen with `native:poll="1ms"` ticks up to 1000
     * times a second. Left on, that is an unbounded append-only file growing
     * by ~110 bytes a frame (megabytes per minute on a polling screen) plus an
     * open/write/close syscall in the middle of every frame. So it follows
     * APP_DEBUG: on while you're developing, off in a release build.
     *
     * Hot-path callers should check this before building their message, so the
     * sprintf() is skipped too — not just the write.
     */
    public static function debugLoggingEnabled(): bool
    {
        return static::$debugLogging ??= function_exists('config')
            ? (bool) config('app.debug', false)
            : false;
    }

    /**
     * File-based debug logging — error_log() doesn't reach Android logcat,
     * so we write to a file on device instead.
     */
    public static function debugLog(string $msg): void
    {
        if (! static::debugLoggingEnabled()) {
            return;
        }

        $logPath = function_exists('storage_path')
            ? storage_path('logs/edge-nav.log')
            : sys_get_temp_dir().'/edge-nav.log';
        @file_put_contents($logPath, date('[H:i:s.').substr(microtime(), 2, 3).'] '.$msg."\n", FILE_APPEND);
    }

    /**
     * Where iOS and Android leave what PHP printed when a native session
     * died part-way (a fatal error, most often the memory limit), relative
     * to storage_path(). Must match `NativeSession.crashOutputPath` (iOS)
     * and `NativeSession.CRASH_OUTPUT_PATH` (Android).
     */
    public const CRASH_OUTPUT_PATH = 'framework/.native_session_crash';

    /**
     * Log why the last native session died, if the app restarted it after
     * PHP stopped part-way. PHP couldn't report it then: Laravel reports fatal
     * errors from a shutdown function, and the persistent runtime's request
     * never shuts down.
     */
    public static function logRestartAfterCrash(): void
    {
        $path = storage_path(self::CRASH_OUTPUT_PATH);
        if (! is_file($path)) {
            return;
        }

        $output = trim((string) @file_get_contents($path));
        @unlink($path);

        static::debugLog("RESTARTED: the last native session died part-way: {$output}");

        try {
            Log::error('The native session died part-way and was restarted', ['output' => $output]);
        } catch (\Throwable) {
            // A broken log channel mustn't stop the screen from starting.
        }

        // The restarted session runs in a fresh interpreter, so the devtools
        // check at the top of start() can't find this fatal in
        // error_get_last(). Spool it here instead, for native:tail --fatals.
        if (function_exists('nativephp_devtools_write_event')
            && preg_match('/PHP Fatal error: (.*) in (.*?) on line (\d+)$/s', $output, $fatal)) {
            nativephp_devtools_write_event('fatal', 'dispatch', [
                'class' => 'PHP Fatal Error',
                'message' => $fatal[1],
                'file' => $fatal[2],
                'line' => (int) $fatal[3],
                'trace' => [],
            ], storage_path());
        }
    }

    /**
     * Reset shared memory buffers between component swaps.
     * Falls back gracefully if the C function isn't available yet.
     */
    protected static function resetBuffers(): void
    {
        nativephp_element_reset();
    }

    /**
     * Signal a view transition to the native renderer.
     *
     * The handler lives in the native-ui plugin (NativeUI.Transition.Set);
     * core mobile-air doesn't ship UI logic.
     */
    protected static function signalTransition(Transition|string $type): void
    {
        $value = $type instanceof Transition ? $type->value : $type;

        if (function_exists('nativephp_call')) {
            nativephp_call('NativeUI.Transition.Set', json_encode(['type' => $value]));
        }
    }

    /**
     * Render a lightweight placeholder frame to shared memory.
     * Available for long-loading screens that want to show a skeleton
     * before the real content renders.
     */
    protected static function renderPlaceholder(): void
    {
        $callbacks = new CallbackRegistry;
        $placeholder = Elements\Column::make()->fill()->safeArea();
        $tree = $placeholder->toArray($callbacks);

        nativephp_element_publish($tree);
    }

    /**
     * URI → component class registry.
     * Populated by Route::native() calls.
     *
     * Each entry retains the actual Illuminate route so native navigation and
     * HTTP routing share matching, constraints, decoding, and route binders.
     *
     * @var array<string, array{class: string, layout: ?string, route: IlluminateRoute}>
     */
    protected static array $routes = [];

    /**
     * Layout class active during a Route::nativeGroup(layout: ..., ...) closure.
     * Applied to any Route::native() registered while the group is open.
     */
    protected static ?string $currentGroupLayout = null;

    /**
     * Navigation stack. Each entry holds a live component instance
     * so state is preserved on back().
     *
     * @var array<int, array{component: NativeComponent, uri: string, params: array}>
     */
    protected array $stack = [];

    /**
     * Deferred transition — set during navigation, flushed just before
     * the first publish() so the old tree stays visible until the new
     * one is ready.
     */
    protected ?Transition $deferredTransition = null;

    /**
     * Async completions kept from the reset in flushDeferredTransition(), for
     * the runloop of the screen that's showing to handle next.
     *
     * @var list<array<string, mixed>>
     */
    protected array $carriedEvents = [];

    /**
     * Flush the deferred transition — resets buffers and signals the
     * transition type to Kotlin. Called just before the first publish()
     * of a new component so the old tree stays visible until the new
     * one is ready.
     *
     * The reset drops every queued event, which is right for taps on the
     * screen going away. But a task the new screen dispatched from mount()
     * can finish before that first frame, and its completion would be
     * dropped with them. Its timeout is disarmed once it finishes, so the
     * screen would wait for it forever. Those completions are kept.
     */
    public function flushDeferredTransition(): void
    {
        if ($this->deferredTransition === null) {
            return;
        }

        $t = $this->deferredTransition;
        $this->deferredTransition = null;

        $this->carriedEvents = [...$this->carriedEvents, ...static::takeAsyncCompletions()];

        static::signalTransition($t);
        static::resetBuffers();
    }

    /**
     * The next async completion kept from a transition reset, if any.
     *
     * @return array<string, mixed>|null
     */
    public function takeCarriedEvent(): ?array
    {
        return array_shift($this->carriedEvents);
    }

    /**
     * Drain the event queue, keeping only async task completions. Each carries
     * its task id, and NativeComponent checks which screen dispatched it, so
     * handing it to the next runloop can't wake the wrong screen.
     *
     * @return list<array<string, mixed>>
     */
    protected static function takeAsyncCompletions(): array
    {
        $completions = [];

        while (($event = nativephp_element_wait_event(0)) !== null) {
            $name = (string) ($event['event'] ?? '');
            $name = str_starts_with($name, 'native:') ? substr($name, 7) : $name;

            if (($event['type'] ?? null) === NativeComponent::EVENT_NATIVE
                && ($name === AsyncTaskFinished::class || $name === AsyncTaskFailed::class)) {
                $completions[] = $event;
            }
        }

        return $completions;
    }

    // ── Static registry ─────────────────────────────

    public static function register(string|IlluminateRoute $uri, string $class, ?string $layout = null): void
    {
        if ($uri instanceof IlluminateRoute) {
            // Held as the live instance, not a copy: `->middleware()` chains
            // onto the route after Route::native() returns, and ScreenGuard
            // has to see the final list.
            $route = $uri;
            $pattern = '/'.ltrim($route->uri(), '/');
        } else {
            $pattern = '/'.ltrim($uri, '/');
            $route = new IlluminateRoute(['GET'], ltrim($pattern, '/'), fn () => null);

            if (function_exists('app') && app()->bound('router')) {
                $route->setRouter(app('router'));
                $route->setContainer(app());
            }
        }

        static::$routes[$pattern] = [
            'class' => $class,
            'layout' => $layout ?? static::$currentGroupLayout,
            'route' => $route,
        ];
    }

    /**
     * Every registered native route, keyed by URI pattern — each value a
     * ['class' => ..., 'layout' => ...] pair. Powers app-wide test sweeps
     * (smoke tests, accessibility audits) that should cover new screens
     * automatically as they are registered.
     */
    public static function registeredRoutes(): array
    {
        return static::$routes;
    }

    /**
     * Update the layout for an already-registered route. Used by the
     * Route::native(...)->layout(...) fluent chain.
     */
    public static function setLayout(string $uri, string $layout): void
    {
        $pattern = '/'.ltrim($uri, '/');
        if (isset(static::$routes[$pattern])) {
            static::$routes[$pattern]['layout'] = $layout;
        }
    }

    public static function beginGroup(string $layout): void
    {
        static::$currentGroupLayout = $layout;
    }

    public static function endGroup(): void
    {
        static::$currentGroupLayout = null;
    }

    /**
     * The path a navigation URI addresses, with no query string or fragment,
     * in the shape a cold launch gets from request()->path().
     *
     * Fragments never come from the shells' deep links, but app code can pass
     * one to navigate(), and it is never part of a route.
     */
    public static function pathOf(string $uri): string
    {
        $uri = explode('#', $uri, 2)[0];

        return '/'.trim(explode('?', $uri, 2)[0], '/');
    }

    /**
     * The query parameters a navigation URI carries, parsed as PHP fills $_GET
     * for a cold launch. Past max_input_vars the rest are dropped, as they
     * would be at request startup, instead of throwing.
     *
     * @return array<array-key, mixed>
     */
    public static function queryOf(string $uri): array
    {
        $uri = explode('#', $uri, 2)[0];
        $mark = strpos($uri, '?');

        return $mark === false ? [] : RequestFactory::query(substr($uri, $mark + 1));
    }

    public static function resolve(string $uri): ?array
    {
        $match = static::matchRoute($uri);

        if ($match === null) {
            return null;
        }

        $entry = $match['entry'];
        $route = clone $match['route'];
        $route->bind($match['request']);

        if (function_exists('app') && app()->bound('router')) {
            $router = app('router');
            $router->substituteBindings($route);
            ComponentRouteBinder::resolve($route, $entry['class']);
        }

        return [
            'class' => $entry['class'],
            'layout' => $entry['layout'] ?? null,
            'params' => $route->parametersWithoutNulls(),
            'query' => static::queryOf($uri),
            'route' => $route,
        ];
    }

    /**
     * Match a registered route without binding its parameters.
     *
     * @return array{entry: array, route: IlluminateRoute, request: Request}|null
     */
    private static function matchRoute(string $uri): ?array
    {
        // Routes match on the path. Leaving the query off also keeps Symfony
        // from parsing it here, where too many parameters would throw.
        $request = Request::create(static::pathOf($uri), 'GET');

        $literalRoutes = [];
        $parameterizedRoutes = [];

        foreach (static::$routes as $entry) {
            if ($entry['route']->parameterNames() === []) {
                $literalRoutes[] = $entry;
            } else {
                $parameterizedRoutes[] = $entry;
            }
        }

        foreach ([...$literalRoutes, ...$parameterizedRoutes] as $entry) {
            $registeredRoute = $entry['route'];

            if (function_exists('app') && app()->bound('router')) {
                $registeredRoute->setRouter(app('router'));
                $registeredRoute->setContainer(app());
            }

            // Match the registered route so its compiled pattern is retained
            // across this long-lived process. Binding happens only in
            // resolve(); predicates such as isNativeRoute() stay side-effect
            // free and cannot trigger model lookups.
            if (! $registeredRoute->matches($request)) {
                continue;
            }

            return [
                'entry' => $entry,
                'route' => $registeredRoute,
                'request' => $request,
            ];
        }

        return null;
    }

    public static function isNativeRoute(string $uri): bool
    {
        try {
            return static::matchRoute($uri) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ask the native screen on show to open a native route, for a lane that
     * cannot run one itself, and answer whether native took the request.
     *
     * Only the main lane can run a native screen. There is one element tree
     * per app and the main lane's thread made it, so another lane that
     * publishes into it frees memory of a Zend heap that is not its own,
     * and Zend aborts the app. The route goes as the `__deeplink` signal
     * the shell sends for a link, which the loop of the screen on show
     * turns into a navigation to it.
     */
    public static function openOnMainLane(string $uri): bool
    {
        return function_exists('nativephp_post_event')
            && nativephp_post_event('__deeplink', ['uri' => $uri]) === true;
    }

    /**
     * Consume a screen change requested from the `native:watch` terminal UI.
     * Returns the target URI, or null when nothing usable is waiting.
     *
     * The intent is always removed, even when rejected — a file we refuse to
     * act on must not sit there and be reconsidered on every reload.
     */
    public static function takeScreenIntent(): ?string
    {
        $path = base_path(self::SCREEN_INTENT_PATH);

        // The intent is written by another process (the host's watcher) into a
        // path this long-lived runtime may already have stat'd and cached as
        // missing, so don't trust a cached answer here.
        clearstatcache(true, $path);

        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        @unlink($path);

        $data = $raw ? json_decode($raw, true) : null;
        $uri = is_array($data) ? ($data['uri'] ?? null) : null;

        if (! is_string($uri) || $uri === '') {
            return null;
        }

        $uri = '/'.ltrim($uri, '/');

        if (time() - (int) ($data['ts'] ?? 0) > self::SCREEN_INTENT_TTL) {
            static::debugLog("screen intent for $uri ignored — stale");

            return null;
        }

        if (! static::isNativeRoute($uri)) {
            static::debugLog("screen intent for $uri ignored — not a native route");

            return null;
        }

        return $uri;
    }

    public static function clearRoutes(): void
    {
        static::$routes = [];
        static::$currentGroupLayout = null;
    }

    /**
     * Number of components currently on the navigation stack.
     * 1 means the user is at the root screen.
     */
    public function stackDepth(): int
    {
        return count($this->stack);
    }

    /**
     * True if the current screen is the first one on the stack
     * (i.e., it was not pushed from anything).
     */
    public function isRootScreen(): bool
    {
        return $this->stackDepth() <= 1;
    }

    /**
     * URI of the screen currently on top of the stack.
     */
    public function currentUri(): ?string
    {
        if (empty($this->stack)) {
            return null;
        }

        return $this->stack[count($this->stack) - 1]['uri'] ?? null;
    }

    /**
     * Full ordered list of stack entries — bottom (root) first, top
     * (current screen) last. Each entry is `['uri' => ..., 'params' => [...]]`.
     * Used by the hot-reload path to serialize the user's navigation
     * history into `.hot_restart` so back-button history survives a
     * PHP reboot.
     *
     * @return list<array{uri: string, params: array}>
     */
    public function getStackEntries(): array
    {
        return array_map(
            fn ($entry) => [
                'uri' => $entry['uri'] ?? '',
                'params' => $entry['params'] ?? [],
            ],
            $this->stack
        );
    }

    /**
     * Replay a list of stack entries below the current top. Each
     * entry is resolved through the route registry, the component is
     * instantiated + mounted, and the entry is pushed onto the stack
     * — so when the user taps back, the runloop's `onResume` path
     * picks up a fully-initialised component.
     *
     * Invalid / unresolvable URIs are skipped (e.g. a route renamed
     * since the hot-reload was triggered). Caller is responsible for
     * NOT including the current top — `start()` pushes that itself.
     *
     * @param  list<array{uri: string, params?: array}>  $entries
     */
    public function preloadStack(array $entries): void
    {
        foreach ($entries as $entry) {
            $uri = $entry['uri'] ?? null;
            if (! is_string($uri) || $uri === '') {
                continue;
            }

            try {
                $resolved = static::resolve($uri);
                if ($resolved === null) {
                    continue;
                }

                $component = $this->createComponent(
                    $resolved['class'],
                    $resolved['params'] ?: ($entry['params'] ?? []),
                    query: $resolved['query'],
                );
                if (! empty($resolved['layout'])) {
                    $component->setLayout($resolved['layout']);
                }
                $component->mountComponent();
            } catch (\Throwable $e) {
                static::debugLog("preloadStack: skipped $uri — ".$e->getMessage());

                continue;
            }

            $this->stack[] = [
                'component' => $component,
                'uri' => $uri,
                'params' => $resolved['params'] ?: ($entry['params'] ?? []),
            ];
        }
    }

    // ── Instance: session lifecycle ─────────────────

    /**
     * Entry point. Init shared memory, run the navigation loop,
     * shutdown when done.
     *
     * `$uri` is the URI the app launched at, query string included, so the
     * launch screen's query() answers the same as on any later screen.
     *
     * @return string|null Exit URI for redirect, or null
     */
    public function start(string $class, array $params = [], string $uri = ''): ?string
    {
        // A fatal in a previous render bails out past PHP catch and the host
        // survives, so report it now — the earliest re-entry after the fatal.
        if (function_exists('nativephp_devtools_report_last_fatal')) {
            nativephp_devtools_report_last_fatal(function_exists('storage_path') ? storage_path() : null);
        }

        NativeComponent::registerDumpHandler();

        nativephp_element_init();

        try {
            static::debugLog("start: class=$class uri=$uri");
            $component = $this->createComponent($class, $params, query: static::queryOf($uri));

            // Hydrate layout from the registered route entry so the
            // component knows its chrome before mount() runs.
            $resolved = $uri !== '' ? static::resolve($uri) : null;
            if ($resolved !== null && ! empty($resolved['layout'])) {
                $component->setLayout($resolved['layout']);
            }

            // The launch screen has always been recorded by its path, and the
            // shells look its tab up by that. Keep it that way.
            $this->stack[] = [
                'component' => $component,
                'uri' => $uri === '' ? '' : static::pathOf($uri),
                'params' => $params,
                // The launch screen arrived as a real HTTP request, so the
                // kernel already ran this route's middleware against the real
                // request. Running it again here would double-count anything
                // stateful (rate limiters, "last seen" writes).
                'guarded' => true,
            ];

            return $this->loop();
        } catch (\Throwable $e) {
            static::debugLog('start EXCEPTION: '.$e->getMessage()."\n".$e->getTraceAsString());

            return null;
        } finally {
            nativephp_element_shutdown();
        }
    }

    /**
     * Navigation loop — runs until the stack is empty or
     * we need to exit to a web route.
     */
    protected function loop(): ?string
    {
        $freshPush = true;
        $reenterCurrentScreen = false;

        while (! empty($this->stack)) {
            $entry = &$this->stack[count($this->stack) - 1];
            $component = $entry['component'];

            static::debugLog('loop: top, component='.get_class($component).' freshPush='.($freshPush ? 'Y' : 'N').' stack='.count($this->stack));

            // Run the route's middleware before the screen is allowed to
            // mount. Denial is expressed as a navigation intent on the
            // component, which runLoop() honors by returning immediately —
            // so mount() never runs and the guarded screen performs none of
            // its data loading before being turned away.
            if ($freshPush && ! ($entry['guarded'] ?? false)) {
                $entry['guarded'] = true;
                $denial = $this->guard($component, $entry['uri'] ?? '');

                if ($denial !== null) {
                    static::debugLog('loop: guard DENIED '.get_class($component)." type={$denial->type} uri={$denial->uri}");
                    $component->setNavigationIntent($denial);
                }
            }

            // This component is what's driving the screen from here until its
            // runLoop() returns — including mount()/onResume(), which run before
            // runLoop() gets to mark itself. "Start loading when the screen
            // opens" is the canonical async dispatch and it lives in mount(), so
            // without this the task would scope to the screen being replaced and
            // its completion (and its timeout) would be dropped as off-screen.
            $previousActiveComponent = NativeComponent::markActive($component);

            try {
                if ($freshPush && ! $component->hasNavigationIntent()) {
                    // For #[Lazy] screens, paint the placeholder before the
                    // (potentially slow) mount() so navigation feels instant.
                    $component->publishPlaceholder();
                    static::debugLog('loop: calling mount() on '.get_class($component));
                    $component->mountComponent();
                    $this->announce(new ScreenMounted(get_class($component), $entry['uri'] ?? null));
                } elseif (! $freshPush && ! $reenterCurrentScreen) {
                    static::debugLog('loop: calling onResume() on '.get_class($component));
                    $component->onResume();
                    $this->announce(new ScreenResumed(get_class($component), $entry['uri'] ?? null));
                }
            } catch (NativeDumpException $e) {
                $component->renderDumpScreen($e);
            } catch (\Throwable $e) {
                static::debugLog('mount/onResume FAILED in '.get_class($component).': '.$e->getMessage());
                $component->renderErrorScreen($e);
            }

            $reenterCurrentScreen = false;

            static::debugLog('loop: entering runLoop() on '.get_class($component));
            $component->runLoop();
            static::debugLog('loop: runLoop() returned on '.get_class($component));

            NativeComponent::restoreActive($previousActiveComponent);

            $intent = $component->getNavigationIntent();

            // Consume it: a component that stays on the stack (the one below a
            // push) must not keep a stale intent, or runLoop()'s "honor an
            // intent set during mount()" path would re-fire it on resume and
            // bounce the user forward again.
            $component->resetNavigationIntent();

            if ($intent === null) {
                static::debugLog('loop: no intent, popping '.get_class($component));
                $this->unmountComponent($component);
                array_pop($this->stack);
                $freshPush = false;

                if (! empty($this->stack)) {
                    $this->deferredTransition = Transition::SlideFromLeft;
                }

                continue;
            }

            static::debugLog("loop: intent type={$intent->type} uri={$intent->uri} stack=".count($this->stack));

            switch ($intent->type) {
                case NavigationIntent::NAVIGATE:
                    static::debugLog("NAVIGATE: resolving uri={$intent->uri}");
                    try {
                        $resolved = static::resolve($intent->uri);
                    } catch (\Throwable $e) {
                        static::debugLog('NAVIGATE binding FAILED: '.$e->getMessage()."\n".$e->getTraceAsString());
                        $component->renderErrorScreen($e);
                        $freshPush = false;
                        $reenterCurrentScreen = true;

                        break;
                    }

                    if ($resolved === null) {
                        static::debugLog('NAVIGATE: unresolved, exiting to web');
                        $this->unmountComponent($component);
                        $this->stack = [];

                        return $intent->uri;
                    }

                    static::debugLog("NAVIGATE: resolved to {$resolved['class']}, deferring transition");
                    $this->deferredTransition = $intent->transition ?? Transition::SlideFromRight;

                    static::debugLog("NAVIGATE: creating component {$resolved['class']}");
                    $next = $this->createComponent(
                        $resolved['class'],
                        $resolved['params'],
                        $intent->data,
                        $resolved['query'],
                    );
                    if (! empty($resolved['layout'])) {
                        $next->setLayout($resolved['layout']);
                    }
                    static::debugLog('NAVIGATE: component created, pushing to stack');

                    $this->stack[] = [
                        'component' => $next,
                        'uri' => $intent->uri,
                        'params' => $resolved['params'],
                    ];

                    $freshPush = true;
                    break;

                case NavigationIntent::BACK:
                    static::debugLog('BACK: popping '.get_class($component));
                    $this->unmountComponent($component);
                    array_pop($this->stack);
                    $freshPush = false;

                    if (empty($this->stack)) {
                        static::debugLog('BACK: stack empty, returning null');

                        return null;
                    }

                    static::debugLog('BACK: deferring transition, stack='.count($this->stack));
                    $this->deferredTransition = $intent->transition ?? Transition::SlideFromLeft;
                    break;

                case NavigationIntent::REPLACE:
                    static::debugLog("REPLACE: resolving uri={$intent->uri}");
                    try {
                        $resolved = static::resolve($intent->uri);
                    } catch (\Throwable $e) {
                        static::debugLog('REPLACE binding FAILED: '.$e->getMessage()."\n".$e->getTraceAsString());
                        $component->renderErrorScreen($e);
                        $freshPush = false;
                        $reenterCurrentScreen = true;

                        break;
                    }

                    if ($resolved === null) {
                        static::debugLog('REPLACE: unresolved, exiting to web');
                        $this->unmountComponent($component);
                        $this->stack = [];

                        return $intent->uri;
                    }

                    static::debugLog("REPLACE: resolved to {$resolved['class']}");
                    $this->unmountComponent($component);
                    array_pop($this->stack);

                    static::debugLog('REPLACE: deferring transition, stack='.count($this->stack));
                    $this->deferredTransition = $intent->transition ?? Transition::Fade;

                    try {
                        static::debugLog("REPLACE: creating component {$resolved['class']}");
                        $next = $this->createComponent(
                            $resolved['class'],
                            $resolved['params'],
                            $intent->data,
                            $resolved['query'],
                        );
                        if (! empty($resolved['layout'])) {
                            $next->setLayout($resolved['layout']);
                        }

                        $this->stack[] = [
                            'component' => $next,
                            'uri' => $intent->uri,
                            'params' => $resolved['params'],
                        ];

                        static::debugLog('REPLACE: pushed '.get_class($next).' to stack');
                    } catch (\Throwable $e) {
                        static::debugLog('REPLACE FAILED: '.$e->getMessage()."\n".$e->getTraceAsString());

                        return null;
                    }

                    $freshPush = true;
                    break;

                case NavigationIntent::EXIT_WEB:
                    static::debugLog("EXIT_WEB: uri={$intent->uri}");
                    $this->unmountComponent($component);
                    $this->stack = [];

                    return $intent->uri;

                case NavigationIntent::RESTART:
                    static::debugLog('RESTART: hot reload — PHP will exit, Kotlin handles re-execution');
                    // Unmount the entire stack — clean exit
                    while (! empty($this->stack)) {
                        $this->unmountComponent($this->stack[count($this->stack) - 1]['component']);
                        array_pop($this->stack);
                    }

                    // Return null — the .hot_restart file tells Kotlin to re-execute
                    return null;
            }
        }

        static::debugLog('loop: stack empty, returning null');

        return null;
    }

    /**
     * Unmount a component and tell the app it happened.
     *
     * Every pop in the loop above goes through here, so the event has one
     * home rather than seven.
     */
    protected function unmountComponent(NativeComponent $component): void
    {
        $component->unmount();
        $this->announce(new ScreenUnmounted(get_class($component), $this->currentUri()));
    }

    /**
     * Run the target route's middleware for a screen about to mount.
     * Null means the navigation is allowed.
     *
     * A guard that throws is treated as a denial rather than taking the
     * runloop down: failing open would silently grant access, which is the
     * bug this whole mechanism exists to fix. ScreenGuard::failed() decides
     * where the user goes instead.
     */
    protected function guard(NativeComponent $component, string $uri): ?NavigationIntent
    {
        if ($uri === '') {
            return null;
        }

        try {
            // Match without binding: the route's own SubstituteBindings runs
            // inside the guard's pipeline, after auth, as it would over HTTP.
            $match = static::matchRoute($uri);

            if ($match === null) {
                return null;
            }

            return ScreenGuard::check($match['route'], $uri);
        } catch (\Throwable $e) {
            return ScreenGuard::failed($e, $uri);
        }
    }

    /**
     * Fire a lifecycle event, best-effort.
     *
     * A listener that throws must not take the navigation loop down with
     * it — an observer is not allowed to break the app it observes. The
     * guard also keeps the router usable outside a booted container,
     * which the testing helpers rely on.
     */
    protected function announce(object $event): void
    {
        try {
            if (function_exists('event')) {
                event($event);
            }
        } catch (\Throwable $e) {
            static::debugLog('lifecycle event failed: '.$e->getMessage());
        }
    }

    protected function createComponent(string $class, array $params = [], array $data = [], array $query = []): NativeComponent
    {
        $component = new $class;
        $component->setRouter($this);
        $component->setParams($params);
        $component->setData($data);
        $component->setQuery($query);

        return $component;
    }
}
