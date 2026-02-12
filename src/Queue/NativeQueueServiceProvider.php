<?php

namespace Native\Mobile\Queue;

use Illuminate\Support\ServiceProvider;

class NativeQueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register the native queue connector
        $this->app->afterResolving('queue', function ($manager) {
            $manager->addConnector('native', function () {
                return new NativeQueueConnector($this->app['db']);
            });
        });
    }

    public function boot(): void
    {
        // Only register routes when running in NativePHP
        if (! config('nativephp-internal.running')) {
            return;
        }

        $this->registerRoutes();
        $this->registerCommands();
    }

    protected function registerRoutes(): void
    {
        $router = $this->app['router'];

        $router->group([
            'prefix' => '_native/queue',
            'middleware' => [], // No middleware - internal only
        ], function ($router) {
            // Process a single job
            $router->post('work', [NativeQueueController::class, 'work']);
            
            // Get queue status (job count, etc.)
            $router->get('status', [NativeQueueController::class, 'status']);
            
            // Clear failed jobs
            $router->post('clear-failed', [NativeQueueController::class, 'clearFailed']);
            
            // Retry a failed job
            $router->post('retry/{id}', [NativeQueueController::class, 'retry']);
        });
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                // Future: artisan commands for queue management
            ]);
        }
    }
}
