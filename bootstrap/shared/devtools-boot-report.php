<?php

/**
 * Boot-fatal reporter — dependency-free on purpose.
 *
 * Runs when Laravel failed to boot (or never got the chance), so it can use
 * nothing but raw PHP: append the fatal to a durable on-device spool and
 * attempt one short best-effort POST to the devtools listener on the dev
 * machine. The endpoint comes from storage/framework/devtools.json, which
 * only `native:watch` ever provisions — without it this is a no-op, so
 * release builds are unaffected.
 */
if (! function_exists('nativephp_devtools_boot_report')) {
    function nativephp_devtools_boot_report(Throwable $e, ?string $storagePath = null): void
    {
        try {
            $storagePath ??= isset($_SERVER['LARAVEL_BOOTSTRAP_PATH'])
                ? dirname($_SERVER['LARAVEL_BOOTSTRAP_PATH']).'/storage'
                : null;

            if ($storagePath === null || ! is_dir($storagePath)) {
                return;
            }

            $event = [
                'v' => 1,
                'id' => bin2hex(random_bytes(16)),
                'ts' => gmdate('Y-m-d\TH:i:s\Z'),
                'kind' => 'boot_fatal',
                'mode' => 'boot',
                'platform' => PHP_OS_FAMILY === 'Darwin' ? 'ios' : 'android',
                'exception' => [
                    'class' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 50),
                ],
            ];

            $line = json_encode($event, JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                return;
            }

            $spoolDir = $storagePath.'/framework/devtools';
            if (! is_dir($spoolDir)) {
                @mkdir($spoolDir, 0755, true);
            }
            @file_put_contents($spoolDir.'/spool.jsonl', $line."\n", FILE_APPEND | LOCK_EX);

            $configPath = $storagePath.'/framework/devtools.json';
            if (! is_file($configPath)) {
                return;
            }

            $config = json_decode((string) @file_get_contents($configPath), true);
            if (! is_array($config) || empty($config['endpoint'])) {
                return;
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n"
                        .'Authorization: Bearer '.($config['token'] ?? '')."\r\n",
                    'content' => $line,
                    'timeout' => 0.2,
                    'ignore_errors' => true,
                ],
            ]);

            @file_get_contents(rtrim($config['endpoint'], '/').'/events', false, $context);
        } catch (Throwable) {
            // Reporting a fatal must never create a second one.
        }
    }
}
