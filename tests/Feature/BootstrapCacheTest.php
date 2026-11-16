<?php

use Illuminate\Container\Container;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Native\Mobile\NativeServiceProvider;
use Native\Mobile\Support\BootstrapCache;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * The device caches its own config after it extracts the app
 * (native:cache-bootstrap). The cache must keep the APP_KEY out, and a boot
 * that finds the app's directories somewhere else than where the cache was
 * built (an iOS data container restored under a new path) must move every
 * path with them.
 */

function bootstrapCacheTempConfig(array $config): string
{
    $path = sys_get_temp_dir().'/nativephp-config-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($path, '<?php return '.var_export($config, true).';'.PHP_EOL);

    return $path;
}

function useCachedConfigAt(string $path): void
{
    putenv("APP_CONFIG_CACHE={$path}");
    $_ENV['APP_CONFIG_CACHE'] = $_SERVER['APP_CONFIG_CACHE'] = $path;
}

afterEach(function () {
    foreach (['APP_CONFIG_CACHE', 'APP_URL', 'APP_KEY', 'DB_DATABASE', 'NATIVEPHP_TEMPDIR'] as $variable) {
        putenv($variable);
        unset($_ENV[$variable], $_SERVER[$variable]);
    }

    foreach ($this->cleanup ?? [] as $path) {
        @unlink($path);
    }
});

it('moves every path under a root that changed, the most specific root first', function () {
    $moves = BootstrapCache::moves(
        ['base' => '/old/app', 'storage' => '/old/app/storage', 'tempdir' => '/tmp'],
        ['base' => '/new/app', 'storage' => '/elsewhere/storage', 'tempdir' => '/tmp'],
    );

    expect($moves)->toBe(['/old/app/storage' => '/elsewhere/storage', '/old/app' => '/new/app']);

    $config = BootstrapCache::relocate([
        'view' => ['compiled' => '/old/app/storage/framework/views'],
        'database' => ['connections' => ['sqlite' => ['database' => '/old/app/database/database.sqlite']]],
        'filesystems' => ['disks' => ['local' => ['root' => '/old/app']], 'links' => ['/old/app/public/storage' => '/old/app/storage/app/public']],
        'unrelated' => ['/old/application', 'https://example.com/old/app', 42, null],
    ], $moves);

    expect($config)->toBe([
        'view' => ['compiled' => '/elsewhere/storage/framework/views'],
        'database' => ['connections' => ['sqlite' => ['database' => '/new/app/database/database.sqlite']]],
        'filesystems' => ['disks' => ['local' => ['root' => '/new/app']], 'links' => ['/new/app/public/storage' => '/elsewhere/storage/app/public']],
        'unrelated' => ['/old/application', 'https://example.com/old/app', 42, null],
    ]);
});

it('finishes a config cache with the roots recorded and no keys', function () {
    $key = 'base64:'.base64_encode(random_bytes(32));
    putenv('NATIVEPHP_TEMPDIR=/device/tmp');
    putenv('DB_DATABASE=/device/support/database/database.sqlite');

    $path = bootstrapCacheTempConfig(['app' => ['key' => $key, 'previous_keys' => ['old']], 'view' => ['compiled' => '/x']]);
    $this->cleanup = [$path];
    useCachedConfigAt($path);

    expect(BootstrapCache::finishConfigCache($this->app))->toBeTrue();

    $contents = file_get_contents($path);
    expect($contents)->not->toContain($key);

    $cached = require $path;
    expect($cached['app']['key'])->toBeNull()
        ->and($cached['app']['previous_keys'])->toBe([])
        ->and($cached['view']['compiled'])->toBe('/x')
        ->and($cached['nativephp-internal']['bootstrap_cache_roots'])->toBe([
            'base' => $this->app->basePath(),
            'storage' => $this->app->storagePath(),
            'database' => '/device/support/database',
            'tempdir' => '/device/tmp',
        ]);
});

it('moves the cached paths and rewrites the cache when the app booted somewhere else', function () {
    $key = 'base64:'.base64_encode(random_bytes(32));
    putenv("APP_KEY={$key}");

    $oldStorage = '/old-container/storage';
    $config = config()->all();
    $config['view']['compiled'] = $oldStorage.'/framework/views';
    $config['nativephp-internal']['running'] = true;
    $config['nativephp-internal']['bootstrap_cache_roots'] = ['storage' => $oldStorage];

    $path = bootstrapCacheTempConfig($config);
    $this->cleanup = [$path];
    useCachedConfigAt($path);
    config(['view.compiled' => $config['view']['compiled'], 'nativephp-internal.running' => true, 'nativephp-internal.bootstrap_cache_roots' => ['storage' => $oldStorage]]);

    expect($this->app->configurationIsCached())->toBeTrue();

    (new NativeServiceProvider($this->app))->register();

    $storage = $this->app->storagePath();
    expect(config('view.compiled'))->toBe($storage.'/framework/views')
        ->and(config('nativephp-internal.bootstrap_cache_roots.storage'))->toBe($storage)
        ->and(config('app.key'))->toBe($key);

    $rewritten = require $path;
    expect($rewritten['view']['compiled'])->toBe($storage.'/framework/views')
        ->and($rewritten['nativephp-internal']['bootstrap_cache_roots']['storage'])->toBe($storage)
        ->and(file_get_contents($path))->not->toContain($key);
});

it('leaves the cache file alone when nothing moved', function () {
    $roots = BootstrapCache::roots($this->app);
    $path = bootstrapCacheTempConfig(['marker' => true]);
    $this->cleanup = [$path];
    useCachedConfigAt($path);
    config(['nativephp-internal.running' => true, 'nativephp-internal.bootstrap_cache_roots' => $roots, 'marker' => true]);

    clearstatcache();
    (new NativeServiceProvider($this->app))->register();

    expect(require $path)->toBe(['marker' => true]);
});

it('lets the URL the shell sets in the environment win over the cache', function () {
    $path = bootstrapCacheTempConfig([]);
    $this->cleanup = [$path];
    useCachedConfigAt($path);
    putenv('APP_URL=php://127.0.0.1');
    config(['nativephp-internal.running' => true, 'app.url' => 'http://from-the-cache']);

    (new NativeServiceProvider($this->app))->register();

    expect(config('app.url'))->toBe('php://127.0.0.1');
});

it('changes nothing when the config is not cached', function () {
    putenv('APP_URL=php://127.0.0.1');
    config(['nativephp-internal.running' => true, 'app.url' => 'http://configured']);

    expect($this->app->configurationIsCached())->toBeFalse();

    (new NativeServiceProvider($this->app))->register();

    expect(config('app.url'))->toBe('http://configured');
});

it('caches config and events on a device, without the key', function () {
    $key = 'base64:'.base64_encode(random_bytes(32));
    putenv("APP_KEY={$key}");
    putenv('NATIVEPHP_TEMPDIR=/device/tmp');

    $configPath = sys_get_temp_dir().'/nativephp-config-'.bin2hex(random_bytes(6)).'.php';
    $eventsPath = sys_get_temp_dir().'/nativephp-events-'.bin2hex(random_bytes(6)).'.php';
    $this->cleanup = [$configPath, $eventsPath];
    useCachedConfigAt($configPath);
    putenv("APP_EVENTS_CACHE={$eventsPath}");
    $_ENV['APP_EVENTS_CACHE'] = $_SERVER['APP_EVENTS_CACHE'] = $eventsPath;

    config(['nativephp-internal.running' => true, 'nativephp.version' => '1.2.3']);

    try {
        Artisan::call('native:cache-bootstrap', [], $buffer = new BufferedOutput);
    } finally {
        putenv('APP_EVENTS_CACHE');
        unset($_ENV['APP_EVENTS_CACHE'], $_SERVER['APP_EVENTS_CACHE']);

        // config:cache boots a fresh app, which takes over the container
        // and the facades, as it does under `artisan optimize`. The testbench
        // skeleton it boots also turns putenv() off for env().
        Env::enablePutenv();
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Artisan::call('view:clear');
    }

    // The testbench skeleton has no resources/views/native, so its views
    // step fails; the device always ships that directory.
    expect($buffer->fetch())->toMatch('/PerfTiming: native:cache-bootstrap config=[\d.]+ms events=[\d.]+ms/')
        ->and(is_file($configPath))->toBeTrue()
        ->and(is_file($eventsPath))->toBeTrue()
        ->and(file_get_contents($configPath))->not->toContain($key);

    $cached = require $configPath;
    expect($cached['app']['key'])->toBeNull()
        ->and($cached['nativephp-internal']['bootstrap_cache_roots']['tempdir'])->toBe('/device/tmp');
});

it('caches nothing off a device', function () {
    config(['nativephp-internal.running' => false]);

    Artisan::call('native:cache-bootstrap');

    expect(Artisan::output())->toContain('Only runs on a device')
        ->and($this->app->configurationIsCached())->toBeFalse();
});

it('caches nothing when the app turned it off', function () {
    config(['nativephp-internal.running' => true, 'nativephp.cache_bootstrap' => false]);

    Artisan::call('native:cache-bootstrap');

    expect(Artisan::output())->toContain('disabled')
        ->and($this->app->configurationIsCached())->toBeFalse();
});

it('caches nothing for a DEBUG build', function () {
    config(['nativephp-internal.running' => true, 'nativephp.version' => 'debug']);

    Artisan::call('native:cache-bootstrap');

    expect(Artisan::output())->toContain('DEBUG build')
        ->and($this->app->configurationIsCached())->toBeFalse();
});

it('is what both shells run after extracting, and routes are never cached', function () {
    $ios = file_get_contents(__DIR__.'/../../resources/xcode/NativePHP/AppUpdateManager.swift');
    $android = file_get_contents(__DIR__.'/../../resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt');

    expect($ios)->toContain('"native:cache-bootstrap"')
        ->and($android)->toContain('"native:cache-bootstrap"')
        ->and($android)->not->toContain('runArtisanCommand("config:cache")')
        ->and($ios.$android)->not->toContain('route:cache"');

    foreach (['resources/xcode/NativePHP/HotReloadServer.swift', 'resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'] as $file) {
        expect(file_get_contents(__DIR__.'/../../'.$file))->toContain('clearBootstrapCache()');
    }
});
