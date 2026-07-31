<?php

namespace Native\Mobile\Traits;

trait CleansEnvFile
{
    protected function cleanEnvFile(string $path): void
    {
        $cleanUpKeys = config('nativephp.cleanup_env_keys', []);

        $contents = collect(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ->filter(function (string $line) use ($cleanUpKeys) {
                $key = str($line)->before('=');

                return ! $key->is($cleanUpKeys)
                    && ! $key->startsWith('#');
            })
            ->join("\n");

        file_put_contents($path, $contents);
    }
}
