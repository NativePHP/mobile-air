<?php

namespace Native\Mobile\Concerns;

trait CleansEnvFile
{
    /**
     * Removed whatever the app's config says. Each device generates its own
     * encryption key, so the developer's must never ship in the bundle.
     */
    protected array $alwaysCleanedEnvKeys = [
        'APP_KEY',
        'APP_PREVIOUS_KEYS',
    ];

    protected function cleanEnvFile(string $path): void
    {
        $cleanUpKeys = array_merge(
            config('nativephp.cleanup_env_keys', []),
            $this->alwaysCleanedEnvKeys,
        );

        $contents = collect(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ->filter(function (string $line) use ($cleanUpKeys) {
                $key = str($line)->before('=')->replaceMatches('/^\s*(export\s+)?/', '')->trim();

                return ! $key->is($cleanUpKeys)
                    && ! $key->startsWith('#');
            })
            ->join("\n");

        file_put_contents($path, $contents);
    }
}
