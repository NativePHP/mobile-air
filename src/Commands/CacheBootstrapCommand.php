<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Support\BootstrapCache;

/**
 * Run by the iOS and Android shells once after they extract a new version of
 * the app, before the persistent runtime boots. It caches the config, the
 * event listeners and the compiled Blade views, so that every later boot
 * loads one file instead of parsing every config file and discovering
 * listeners again.
 *
 * Built here rather than at build time because the config holds the device's
 * own paths, and the APP_KEY only exists on the device.
 *
 * Routes are not cached: Route::native() fills NativeRouter's registry while
 * the routes file runs, and a cached route table never runs it.
 *
 * Not meant to be run by hand.
 */
class CacheBootstrapCommand extends Command
{
    protected $signature = 'native:cache-bootstrap';

    protected $description = 'Cache config, events and views on the device (internal)';

    protected $hidden = true;

    public function handle(): int
    {
        // Whatever happens next, nothing an earlier version left may survive.
        $this->callSilent('config:clear');
        $this->callSilent('event:clear');

        if (! config('nativephp-internal.running')) {
            $this->components->warn('Only runs on a device. Nothing cached.');

            return self::SUCCESS;
        }

        if (! config('nativephp.cache_bootstrap', true)) {
            $this->components->info('Bootstrap cache disabled (nativephp.cache_bootstrap). Nothing cached.');

            return self::SUCCESS;
        }

        // A DEBUG build re-extracts on every launch, and hot reload changes
        // code under the running app: caching would only slow it down.
        if (strcasecmp((string) config('nativephp.version'), 'DEBUG') === 0) {
            $this->components->info('DEBUG build. Nothing cached.');

            return self::SUCCESS;
        }

        $timings = [];

        $timings['config'] = $this->step('config', 'config:clear', function () {
            if ($this->callSilent('config:cache') !== 0 || ! BootstrapCache::finishConfigCache($this->laravel)) {
                throw new \RuntimeException('config:cache failed');
            }
        });

        $timings['events'] = $this->step('events', 'event:clear', function () {
            if ($this->callSilent('event:cache') !== 0) {
                throw new \RuntimeException('event:cache failed');
            }
        });

        $timings['views'] = $this->step('views', 'view:clear', function () {
            if ($this->callSilent('view:cache') !== 0) {
                throw new \RuntimeException('view:cache failed');
            }
        });

        $this->line('PerfTiming: native:cache-bootstrap '.collect($timings)
            ->map(fn ($ms, $step) => $step.'='.($ms === null ? 'failed' : $ms.'ms'))
            ->implode(' '));

        return self::SUCCESS;
    }

    /**
     * Run one cache step. A step that fails is cleared and left out, so the
     * app boots the slow way instead of from half a cache.
     */
    protected function step(string $name, string $clear, \Closure $cache): ?float
    {
        $started = microtime(true);

        try {
            $cache();
        } catch (\Throwable $e) {
            $this->callSilent($clear);
            $this->components->warn("Could not cache {$name}: {$e->getMessage()}");
            error_log("native:cache-bootstrap: {$name} not cached: {$e->getMessage()}");

            return null;
        }

        return round((microtime(true) - $started) * 1000, 1);
    }
}
