<?php

namespace Native\Mobile\Edge;

class NativeRouter
{
    /**
     * File-based debug logging — error_log() doesn't reach Android logcat,
     * so we write to a file on device instead.
     */
    public static function debugLog(string $msg): void
    {
        $logPath = function_exists('storage_path')
            ? storage_path('logs/edge-nav.log')
            : sys_get_temp_dir() . '/edge-nav.log';
        @file_put_contents($logPath, date('[H:i:s.') . substr(microtime(), 2, 3) . '] ' . $msg . "\n", FILE_APPEND);
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
     * Called before resetBuffers() so the Kotlin side knows how to
     * animate the next screen swap.
     */
    protected static function signalTransition(Transition|string $type): void
    {
        $value = $type instanceof Transition ? $type->value : $type;

        if (function_exists('nativephp_call')) {
            nativephp_call('UI.SetTransition', json_encode(['type' => $value]));
        }
    }

    /**
     * Render a lightweight placeholder frame to shared memory.
     *
     * Called after resetBuffers() but before mount()/render() so the
     * Kotlin renderer has a tree to animate to immediately, rather than
     * waiting for the (potentially slow) first render of the new component.
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
     * @var array<string, string>
     */
    protected static array $routes = [];

    /**
     * Navigation stack. Each entry holds a live component instance
     * so state is preserved on back().
     *
     * @var array<int, array{component: NativeComponent, uri: string, params: array}>
     */
    protected array $stack = [];

    // ── Static registry ─────────────────────────────

    public static function register(string $uri, string $class): void
    {
        $pattern = '/'.ltrim($uri, '/');
        static::$routes[$pattern] = $class;
    }

    public static function resolve(string $uri): ?array
    {
        $uri = '/'.ltrim($uri, '/');

        // Exact match first
        if (isset(static::$routes[$uri])) {
            return ['class' => static::$routes[$uri], 'params' => []];
        }

        // Pattern match with route parameters
        foreach (static::$routes as $pattern => $class) {
            $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
            $regex = '#^'.$regex.'$#';

            if (preg_match($regex, $uri, $matches)) {
                $params = array_filter($matches, fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);

                return ['class' => $class, 'params' => $params];
            }
        }

        return null;
    }

    public static function isNativeRoute(string $uri): bool
    {
        return static::resolve($uri) !== null;
    }

    public static function clearRoutes(): void
    {
        static::$routes = [];
    }

    // ── Instance: session lifecycle ─────────────────

    /**
     * Entry point. Init shared memory, run the navigation loop,
     * shutdown when done.
     *
     * @return string|null  Exit URI for redirect, or null
     */
    public function start(string $class, array $params = []): ?string
    {
        NativeComponent::registerDumpHandler();

        nativephp_element_init();

        try {
            static::debugLog("start: class=$class");
            $component = $this->createComponent($class, $params);

            $this->stack[] = [
                'component' => $component,
                'uri' => '',
                'params' => $params,
            ];

            return $this->loop();
        } catch (\Throwable $e) {
            static::debugLog("start EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString());
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

        while (! empty($this->stack)) {
            $entry = &$this->stack[count($this->stack) - 1];
            $component = $entry['component'];

            static::debugLog("loop: top, component=" . get_class($component) . " freshPush=" . ($freshPush ? 'Y' : 'N') . " stack=" . count($this->stack));

            try {
                if ($freshPush) {
                    static::debugLog("loop: calling mount() on " . get_class($component));
                    $component->mount();
                } else {
                    static::debugLog("loop: calling onResume() on " . get_class($component));
                    $component->onResume();
                }
            } catch (NativeDumpException $e) {
                $component->renderDumpScreen($e);
            } catch (\Throwable $e) {
                static::debugLog("mount/onResume FAILED in " . get_class($component) . ": " . $e->getMessage());
                $component->renderErrorScreen($e);
            }

            static::debugLog("loop: entering runLoop() on " . get_class($component));
            $component->runLoop();
            static::debugLog("loop: runLoop() returned on " . get_class($component));

            $intent = $component->getNavigationIntent();

            if ($intent === null) {
                static::debugLog("loop: no intent, popping " . get_class($component));
                $component->unmount();
                array_pop($this->stack);
                $freshPush = false;

                if (! empty($this->stack)) {
                    static::signalTransition(Transition::SlideFromLeft);
                    static::resetBuffers();
                }

                continue;
            }

            static::debugLog("loop: intent type={$intent->type} uri={$intent->uri} stack=" . count($this->stack));

            switch ($intent->type) {
                case NavigationIntent::NAVIGATE:
                    static::debugLog("NAVIGATE: resolving uri={$intent->uri}");
                    $resolved = static::resolve($intent->uri);

                    if ($resolved === null) {
                        static::debugLog("NAVIGATE: unresolved, exiting to web");
                        $component->unmount();
                        $this->stack = [];

                        return $intent->uri;
                    }

                    static::debugLog("NAVIGATE: resolved to {$resolved['class']}, resetting buffers");
                    static::signalTransition($intent->transition ?? Transition::SlideFromRight);
                    static::resetBuffers();

                    static::debugLog("NAVIGATE: creating component {$resolved['class']}");
                    $next = $this->createComponent(
                        $resolved['class'],
                        $resolved['params'],
                        $intent->data
                    );
                    static::debugLog("NAVIGATE: component created, pushing to stack");

                    $this->stack[] = [
                        'component' => $next,
                        'uri' => $intent->uri,
                        'params' => $resolved['params'],
                    ];

                    $freshPush = true;
                    break;

                case NavigationIntent::BACK:
                    static::debugLog("BACK: popping " . get_class($component));
                    $component->unmount();
                    array_pop($this->stack);
                    $freshPush = false;

                    if (empty($this->stack)) {
                        static::debugLog("BACK: stack empty, returning null");
                        return null;
                    }

                    static::debugLog("BACK: resetting buffers, stack=" . count($this->stack));
                    static::signalTransition($intent->transition ?? Transition::SlideFromLeft);
                    static::resetBuffers();
                    break;

                case NavigationIntent::REPLACE:
                    static::debugLog("REPLACE: resolving uri={$intent->uri}");
                    $resolved = static::resolve($intent->uri);

                    if ($resolved === null) {
                        static::debugLog("REPLACE: unresolved, exiting to web");
                        $component->unmount();
                        $this->stack = [];

                        return $intent->uri;
                    }

                    static::debugLog("REPLACE: resolved to {$resolved['class']}");
                    $component->unmount();
                    array_pop($this->stack);

                    static::debugLog("REPLACE: resetting buffers, stack=" . count($this->stack));
                    static::signalTransition($intent->transition ?? Transition::Fade);
                    static::resetBuffers();

                    try {
                        static::debugLog("REPLACE: creating component {$resolved['class']}");
                        $next = $this->createComponent(
                            $resolved['class'],
                            $resolved['params'],
                            $intent->data
                        );

                        $this->stack[] = [
                            'component' => $next,
                            'uri' => $intent->uri,
                            'params' => $resolved['params'],
                        ];

                        static::debugLog("REPLACE: pushed " . get_class($next) . " to stack");
                    } catch (\Throwable $e) {
                        static::debugLog("REPLACE FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString());
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
                    static::debugLog("RESTART: hot reload — PHP will exit, Kotlin handles re-execution");
                    // Unmount the entire stack — clean exit
                    while (! empty($this->stack)) {
                        $this->stack[count($this->stack) - 1]['component']->unmount();
                        array_pop($this->stack);
                    }

                    // Return null — the .hot_restart file tells Kotlin to re-execute
                    return null;
            }
        }

        static::debugLog("loop: stack empty, returning null");
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
