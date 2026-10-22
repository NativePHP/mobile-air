<?php

namespace Native\Mobile\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * On a device the native shell keeps APP_KEY in the Keychain/Keystore and
 * hands it to PHP through the process environment. This keeps the encryption
 * keys coming from there, and out of the config cache on disk.
 */
class DeviceAppKey
{
    /**
     * Take the encryption keys from the process environment, whatever the
     * (possibly cached) config says.
     */
    public static function apply(Repository $config): void
    {
        $key = getenv('APP_KEY');

        if (! is_string($key) || $key === '') {
            return;
        }

        $config->set('app.key', $key);

        $previous = getenv('APP_PREVIOUS_KEYS');

        if (is_string($previous)) {
            $config->set('app.previous_keys', array_values(array_filter(explode(',', $previous))));
        }
    }

    /**
     * Remove the encryption keys from a config cache file. apply() puts them
     * back at runtime.
     */
    public static function scrubCachedConfig(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        $config = require $path;

        if (! is_array($config) || ! isset($config['app']) || ! is_array($config['app'])) {
            return;
        }

        $config['app']['key'] = null;
        $config['app']['previous_keys'] = [];

        file_put_contents($path, '<?php return '.var_export($config, true).';'.PHP_EOL);
    }
}
