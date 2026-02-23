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
        if (function_exists('nativephp_ui_reset')) {
            nativephp_ui_reset();
        }
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
        nativephp_ui_init();

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
            nativephp_ui_shutdown();
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

            if ($freshPush) {
                static::debugLog("loop: calling mount() on " . get_class($component));
                $component->mount();
            } else {
                static::debugLog("loop: calling onResume() on " . get_class($component));
                $component->onResume();
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
