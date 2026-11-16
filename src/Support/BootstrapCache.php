<?php

namespace Native\Mobile\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * The config cache a device builds for itself after it extracts the app
 * (`native:cache-bootstrap`), and what has to happen for it to stay right.
 *
 * It is built on the device, never on the build machine, because the config
 * holds absolute paths and those only exist on the device. Even there they
 * can move: an iOS data container can come back under a new path (a restore
 * onto another device, for one) while the extracted app stays the same
 * version and so is never re-extracted. The cache therefore records the
 * roots it was built under, and a boot that finds them changed rewrites the
 * paths in memory and writes the corrected cache back.
 *
 * The encryption keys never go in the file ({@see DeviceAppKey}).
 */
class BootstrapCache
{
    /** Where in the cached config the build-time roots are recorded. */
    public const ROOTS_KEY = 'nativephp-internal.bootstrap_cache_roots';

    /**
     * Values the shell sets in the process environment, which win over the
     * cache as they would over the .env file. iOS classic mode sets these
     * per request.
     */
    public const RUNTIME_ENV = [
        'app.url' => 'APP_URL',
        'app.asset_url' => 'ASSET_URL',
    ];

    /**
     * The directories every path in the config hangs off on a device.
     *
     * @return array<string, string>
     */
    public static function roots(Application $app): array
    {
        $database = getenv('DB_DATABASE');

        $roots = [
            'base' => $app->basePath(),
            'storage' => $app->storagePath(),
            'database' => is_string($database) && str_starts_with($database, '/') ? dirname($database) : null,
            'tempdir' => getenv('NATIVEPHP_TEMPDIR'),
        ];

        return array_filter($roots, fn ($root) => is_string($root) && $root !== '' && $root !== '/');
    }

    /**
     * Turn the file `config:cache` just wrote into the one a device keeps:
     * keys out, roots in.
     */
    public static function finishConfigCache(Application $app): bool
    {
        $path = $app->getCachedConfigPath();

        if (! is_file($path)) {
            return false;
        }

        $config = require $path;

        if (! is_array($config)) {
            return false;
        }

        data_set($config, static::ROOTS_KEY, static::roots($app));

        return static::write($path, $config);
    }

    /**
     * Called while providers register, after Laravel loaded the cached
     * config: put back what belongs to this process rather than the one that
     * built the cache.
     */
    public static function apply(Application $app): void
    {
        if (! $app->configurationIsCached()) {
            return;
        }

        $config = $app['config'];

        foreach (static::RUNTIME_ENV as $key => $variable) {
            $value = getenv($variable);

            if (is_string($value) && $value !== '') {
                $config->set($key, $value);
            }
        }

        $cachedRoots = $config->get(static::ROOTS_KEY);

        if (! is_array($cachedRoots)) {
            return;
        }

        $roots = static::roots($app);
        $moves = static::moves($cachedRoots, $roots);

        if ($moves === []) {
            return;
        }

        $relocated = static::relocate($config->all(), $moves);
        data_set($relocated, static::ROOTS_KEY, $roots);

        $config->set($relocated);

        // Fix the file too, so the next boot doesn't pay for this again.
        // Written from the config as loaded, before the device keys go in.
        static::write($app->getCachedConfigPath(), $relocated);
    }

    /**
     * Old root => new root for every root that moved, longest first so a
     * root inside another is replaced before the one around it.
     *
     * @return array<string, string>
     */
    public static function moves(array $cachedRoots, array $roots): array
    {
        $moves = [];

        foreach ($cachedRoots as $name => $old) {
            $new = $roots[$name] ?? null;

            if (is_string($old) && $old !== '' && is_string($new) && $old !== $new) {
                $moves[$old] = $new;
            }
        }

        uksort($moves, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $moves;
    }

    /**
     * Every string that is one of the old roots, or a path under one, with
     * the root swapped for its new location. Keys too: filesystems.links is
     * keyed by the link's path.
     */
    public static function relocate(array $config, array $moves): array
    {
        $relocated = [];

        foreach ($config as $key => $value) {
            if (is_string($key)) {
                $key = static::relocatePath($key, $moves);
            }

            if (is_array($value)) {
                $value = static::relocate($value, $moves);
            } elseif (is_string($value)) {
                $value = static::relocatePath($value, $moves);
            }

            $relocated[$key] = $value;
        }

        return $relocated;
    }

    protected static function relocatePath(string $value, array $moves): string
    {
        foreach ($moves as $old => $new) {
            if ($value === $old || str_starts_with($value, $old.'/')) {
                return $new.substr($value, strlen($old));
            }
        }

        return $value;
    }

    /**
     * Write a config cache file, without the encryption keys, atomically so
     * a runtime booting beside this one never reads half a file.
     */
    protected static function write(string $path, array $config): bool
    {
        $temp = $path.'.'.getmypid().'.tmp';

        try {
            if (file_put_contents($temp, '<?php return '.var_export(DeviceAppKey::scrub($config), true).';'.PHP_EOL) === false) {
                return false;
            }

            if (! rename($temp, $path)) {
                @unlink($temp);

                return false;
            }
        } catch (\Throwable) {
            @unlink($temp);

            return false;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return true;
    }
}
