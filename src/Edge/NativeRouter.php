<?php

namespace Native\Mobile\Edge;

use Illuminate\Http\Request;
use Illuminate\Routing\Pipeline;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

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

    /**
     * File-based debug logging — error_log() doesn't reach Android logcat,
     * so we write to a file on device instead.
     */
    public static function debugLog(string $msg): void
    {
        $logPath = function_exists('storage_path')
            ? storage_path('logs/edge-nav.log')
            : sys_get_temp_dir().'/edge-nav.log';
        @file_put_contents($logPath, date('[H:i:s.').substr(microtime(), 2, 3).'] '.$msg."\n", FILE_APPEND);
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
     * Each entry: ['class' => string, 'layout' => ?string, 'route' => ?Route]
     *
     * @var array<string, array{class: string, layout: ?string, route: ?Route}>
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
     * Flush the deferred transition — resets buffers and signals the
     * transition type to Kotlin. Called just before the first publish()
     * of a new component so the old tree stays visible until the new
     * one is ready.
     */
    public function flushDeferredTransition(): void
    {
        if ($this->deferredTransition === null) {
            return;
        }

        $t = $this->deferredTransition;
        $this->deferredTransition = null;

        static::signalTransition($t);
        static::resetBuffers();
    }

    // ── Static registry ─────────────────────────────

    public static function register(string $uri, string $class, ?string $layout = null, ?Route $route = null): void
    {
        $pattern = '/'.ltrim($uri, '/');
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
        return array_map(fn (array $entry) => [
            'class' => $entry['class'],
            'layout' => $entry['layout'],
        ], static::$routes);
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

    public static function resolve(string $uri): ?array
    {
        $uri = '/'.ltrim($uri, '/');

        // Exact match first
        if (isset(static::$routes[$uri])) {
            $entry = static::$routes[$uri];

            return [
                'class' => $entry['class'],
                'layout' => $entry['layout'] ?? null,
                'params' => [],
            ];
        }

        // Pattern match with route parameters
        foreach (static::$routes as $pattern => $entry) {
            $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
            $regex = '#^'.$regex.'$#';

            if (preg_match($regex, $uri, $matches)) {
                $params = array_filter($matches, fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);

                return [
                    'class' => $entry['class'],
                    'layout' => $entry['layout'] ?? null,
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    public static function isNativeRoute(string $uri): bool
    {
        return static::resolve($uri) !== null;
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

            $resolved = static::resolve($uri);
            if ($resolved === null) {
                continue;
            }

            if ($this->runRouteMiddleware($uri) !== null) {
                static::debugLog("preloadStack: skipped $uri — route middleware blocked navigation");

                continue;
            }

            try {
                $component = $this->createComponent(
                    $resolved['class'],
                    $resolved['params'] ?: ($entry['params'] ?? []),
                );
                if (! empty($resolved['layout'])) {
                    $component->setLayout($resolved['layout']);
                }
                $component->mount();
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
     * @return Response|string|null Middleware response, exit URI, or null
     */
    public function start(string $class, array $params = [], string $uri = ''): Response|string|null
    {
        NativeComponent::registerDumpHandler();

        nativephp_element_init();

        try {
            static::debugLog("start: class=$class uri=$uri");
            $component = $this->createComponent($class, $params);

            // Hydrate layout from the registered route entry so the
            // component knows its chrome before mount() runs.
            $resolved = $uri !== '' ? static::resolve($uri) : null;
            if ($resolved !== null && ! empty($resolved['layout'])) {
                $component->setLayout($resolved['layout']);
            }

            $this->stack[] = [
                'component' => $component,
                'uri' => $uri,
                'params' => $params,
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
    protected function loop(): Response|string|null
    {
        $freshPush = true;

        while (! empty($this->stack)) {
            $entry = &$this->stack[count($this->stack) - 1];
            $component = $entry['component'];

            static::debugLog('loop: top, component='.get_class($component).' freshPush='.($freshPush ? 'Y' : 'N').' stack='.count($this->stack));

            try {
                if ($freshPush) {
                    // For #[Lazy] screens, paint the placeholder before the
                    // (potentially slow) mount() so navigation feels instant.
                    $component->publishPlaceholder();
                    static::debugLog('loop: calling mount() on '.get_class($component));
                    $component->mount();
                } else {
                    static::debugLog('loop: calling onResume() on '.get_class($component));
                    $component->onResume();
                }
            } catch (NativeDumpException $e) {
                $component->renderDumpScreen($e);
            } catch (\Throwable $e) {
                static::debugLog('mount/onResume FAILED in '.get_class($component).': '.$e->getMessage());
                $component->renderErrorScreen($e);
            }

            static::debugLog('loop: entering runLoop() on '.get_class($component));
            $component->runLoop();
            static::debugLog('loop: runLoop() returned on '.get_class($component));

            $intent = $component->getNavigationIntent();

            // Consume it: a component that stays on the stack (the one below a
            // push) must not keep a stale intent, or runLoop()'s "honor an
            // intent set during mount()" path would re-fire it on resume and
            // bounce the user forward again.
            $component->resetNavigationIntent();

            if ($intent === null) {
                static::debugLog('loop: no intent, popping '.get_class($component));
                $component->unmount();
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
                    $resolved = static::resolve($intent->uri);

                    if ($resolved === null) {
                        static::debugLog('NAVIGATE: unresolved, exiting to web');
                        $component->unmount();
                        $this->stack = [];

                        return $intent->uri;
                    }

                    $middlewareResponse = $this->runRouteMiddleware($intent->uri);
                    if ($middlewareResponse !== null) {
                        static::debugLog('NAVIGATE: route middleware blocked navigation with status '.$middlewareResponse->getStatusCode());
                        $component->unmount();
                        $this->stack = [];

                        return $middlewareResponse;
                    }

                    static::debugLog("NAVIGATE: resolved to {$resolved['class']}, deferring transition");
                    $this->deferredTransition = $intent->transition ?? Transition::SlideFromRight;

                    static::debugLog("NAVIGATE: creating component {$resolved['class']}");
                    $next = $this->createComponent(
                        $resolved['class'],
                        $resolved['params'],
                        $intent->data
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
                    $component->unmount();
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
                    $resolved = static::resolve($intent->uri);

                    if ($resolved === null) {
                        static::debugLog('REPLACE: unresolved, exiting to web');
                        $component->unmount();
                        $this->stack = [];

                        return $intent->uri;
                    }

                    $middlewareResponse = $this->runRouteMiddleware($intent->uri);
                    if ($middlewareResponse !== null) {
                        static::debugLog('REPLACE: route middleware blocked navigation with status '.$middlewareResponse->getStatusCode());
                        $component->unmount();
                        $this->stack = [];

                        return $middlewareResponse;
                    }

                    static::debugLog("REPLACE: resolved to {$resolved['class']}");
                    $component->unmount();
                    array_pop($this->stack);

                    static::debugLog('REPLACE: deferring transition, stack='.count($this->stack));
                    $this->deferredTransition = $intent->transition ?? Transition::Fade;

                    try {
                        static::debugLog("REPLACE: creating component {$resolved['class']}");
                        $next = $this->createComponent(
                            $resolved['class'],
                            $resolved['params'],
                            $intent->data
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
                    $component->unmount();
                    $this->stack = [];

                    return $intent->uri;

                case NavigationIntent::RESTART:
                    static::debugLog('RESTART: hot reload — PHP will exit, Kotlin handles re-execution');
                    // Unmount the entire stack — clean exit
                    while (! empty($this->stack)) {
                        $this->stack[count($this->stack) - 1]['component']->unmount();
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
     * Run the Laravel route middleware associated with an in-app navigation.
     *
     * A null response means the middleware pipeline reached its destination.
     * Any response means middleware short-circuited and the screen must not mount.
     */
    protected function runRouteMiddleware(string $uri): ?Response
    {
        $resolved = static::resolve($uri);
        if ($resolved === null) {
            return null;
        }

        $pattern = $this->routePatternFor($uri);
        $registeredRoute = $pattern !== null ? (static::$routes[$pattern]['route'] ?? null) : null;
        if (! $registeredRoute instanceof Route) {
            return null;
        }
        $route = clone $registeredRoute;

        $currentRequest = app('request');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $queryString = parse_url($uri, PHP_URL_QUERY) ?: '';
        parse_str($queryString, $query);

        $server = $currentRequest->server->all();
        $server['REQUEST_METHOD'] = 'GET';
        $server['REQUEST_URI'] = $uri;
        $server['PATH_INFO'] = $path;
        $server['QUERY_STRING'] = $queryString;

        /** @var Request $request */
        $request = $currentRequest->duplicate(
            query: $query,
            request: [],
            attributes: [],
            files: [],
            server: $server,
        );
        $request->setMethod('GET');

        $route->setContainer(app());
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        $router = app('router');
        $currentRoute = $router->getCurrentRoute();
        $currentRouterRequest = $router->getCurrentRequest();
        $middleware = app()->bound('middleware.disable') && app('middleware.disable') === true
            ? []
            : $router->gatherRouteMiddleware($route);
        $destination = new \stdClass;

        $hadRouteBinding = app()->bound(Route::class);
        $currentRouteBinding = $hadRouteBinding ? app(Route::class) : null;

        app()->instance('request', $request);
        app()->instance(Route::class, $route);
        Facade::clearResolvedInstance('request');

        // Router has no public current-route setter, but route middleware may
        // legitimately use the Route facade. Scope the same context Laravel's
        // normal dispatch establishes, then restore the long-lived request.
        (function (Route $route): void {
            $this->current = $route;
        })->call($router, $route);
        (function (Request $request): void {
            $this->currentRequest = $request;
        })->call($router, $request);

        try {
            $result = (new Pipeline(app()))
                ->send($request)
                ->through($middleware)
                ->then(fn () => $destination);

            return $result === $destination
                ? null
                : $router->prepareResponse($request, $result);
        } finally {
            app()->instance('request', $currentRequest);
            if ($hadRouteBinding) {
                app()->instance(Route::class, $currentRouteBinding);
            } else {
                app()->forgetInstance(Route::class);
            }
            (function (?Route $route): void {
                $this->current = $route;
            })->call($router, $currentRoute);
            (function (?Request $request): void {
                $this->currentRequest = $request;
            })->call($router, $currentRouterRequest);
            Facade::clearResolvedInstance('request');
        }
    }

    protected function routePatternFor(string $uri): ?string
    {
        $path = '/'.ltrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/');

        if (isset(static::$routes[$path])) {
            return $path;
        }

        foreach (array_keys(static::$routes) as $pattern) {
            $regex = preg_replace('/\{(\w+)\}/', '[^/]+', $pattern);
            if (preg_match('#^'.$regex.'$#', $path)) {
                return $pattern;
            }
        }

        return null;
    }

    protected function createComponent(string $class, array $params = [], array $data = []): NativeComponent
    {
        $component = new $class;
        $component->setRouter($this);
        $component->setParams($params);
        $component->setData($data);

        return $component;
    }
}
