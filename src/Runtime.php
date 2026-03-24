<?php

namespace Native\Mobile;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Output\BufferedOutput;

class Runtime
{
    protected static ?Application $app = null;

    protected static ?Kernel $kernel = null;

    protected static bool $booted = false;

    /** @var array<callable> */
    protected static array $resetCallbacks = [];

    protected static array $config = [
        'reset_instances' => true,
        'gc_between_dispatches' => false,
    ];

    /**
     * Boot the persistent runtime. Called once from persistent.php bootstrap.
     */
    public static function boot(Application $app): void
    {
        static::$app = $app;

        // Bind a placeholder request so service providers that resolve 'request'
        // during bootstrap don't fail (no real HTTP request exists yet in persistent mode)
        if (! $app->bound('request')) {
            $app->instance('request', Request::capture());
        }

        static::$kernel = $app->make(Kernel::class);
        static::$kernel->bootstrap();
        static::$booted = true;

        // Load runtime config if available
        $runtimeConfig = $app['config']->get('nativephp.runtime', []);
        static::$config = array_merge(static::$config, $runtimeConfig);
    }

    /**
     * Dispatch a request through the persistent kernel.
     * Resets minimal state between dispatches (not Octane — just what matters for mobile).
     */
    public static function dispatch(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        if (! static::$booted) {
            throw new \RuntimeException('Runtime not booted. Call Runtime::boot() first.');
        }

        // Reset state from previous dispatch
        static::reset();

        // Bind fresh request into the container
        static::$app->instance('request', $request);
        Facade::clearResolvedInstance('request');

        // Set originalRequest to current request — prevents stale Livewire
        // PersistentMiddleware state from producing a Request with empty method
        static::$app->instance('originalRequest', $request);

        // Handle the request through the kernel
        try {
            $response = static::$kernel->handle($request);
        } catch (\Throwable $e) {
            $response = new Response(
                'Error: '.$e->getMessage()."\n".$e->getTraceAsString(),
                500,
                ['Content-Type' => 'text/plain']
            );
        }

        // Terminate (fires terminable middleware)
        static::$kernel->terminate($request, $response);

        return $response;
    }

    /**
     * Reset state between dispatches.
     * Minimal — just the things that matter for single-user mobile.
     */
    public static function reset(): void
    {
        if (! static::$app) {
            return;
        }

        // 1. Clear resolved facade instances so they get fresh ones
        if (static::$config['reset_instances']) {
            Facade::clearResolvedInstances();
        }

        // 2. Flush the router's matched route state
        if (static::$app->resolved('router')) {
            $router = static::$app['router'];
            // Reset the current route and request on the router
            if (method_exists($router, 'setCurrentRoute')) {
                $router->setCurrentRoute(null);
            }
        }

        // 3. Flush Livewire state (scripts/assets rendered flags, etc.)
        // Livewire uses flush-state to reset static flags between requests (same as Octane)
        if (static::$app->bound('livewire')) {
            try {
                static::$app->make('livewire')->flushState();
            } catch (\Throwable $e) {
                // Livewire flushState can fail if not fully initialized
            }
        }

        // 4. Run developer-registered reset callbacks
        foreach (static::$resetCallbacks as $callback) {
            $callback(static::$app);
        }

        // 5. Optional GC between dispatches
        if (static::$config['gc_between_dispatches']) {
            gc_collect_cycles();
        }
    }

    /**
     * Register a callback to run between dispatches.
     * Useful for developers to clean up custom state.
     */
    public static function onReset(callable $callback): void
    {
        static::$resetCallbacks[] = $callback;
    }

    /**
     * Run an artisan command through the persistent app instance.
     */
    public static function artisan(string $command): string
    {
        if (! static::$booted) {
            throw new \RuntimeException('Runtime not booted. Call Runtime::boot() first.');
        }

        $output = new BufferedOutput;

        // Parse command and arguments
        $parts = str_getcsv($command, ' ');
        $commandName = array_shift($parts);

        $params = ['command' => $commandName];
        foreach ($parts as $part) {
            if (str_starts_with($part, '--')) {
                $kv = explode('=', substr($part, 2), 2);
                $params['--'.$kv[0]] = $kv[1] ?? true;
            } else {
                $params[] = $part;
            }
        }

        Artisan::call($commandName, array_slice($params, 1), $output);
        $result = $output->fetch();

        return $result;
    }

    /**
     * Clean shutdown of the persistent runtime.
     */
    public static function shutdown(): void
    {
        if (static::$app) {
            // Terminate any pending work
            if (static::$kernel && method_exists(static::$kernel, 'terminate')) {
                // Kernel terminate already called per-dispatch, but flush remaining
            }

            static::$app->flush();
        }

        static::$app = null;
        static::$kernel = null;
        static::$booted = false;
        static::$resetCallbacks = [];
    }

    public static function isBooted(): bool
    {
        return static::$booted;
    }

    public static function getApp(): ?Application
    {
        return static::$app;
    }
}
