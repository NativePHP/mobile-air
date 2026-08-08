<?php

/**
 * Device-side exception reporting — dependency-free on purpose.
 *
 * These helpers run in places where Laravel may be half-booted or already
 * dead (boot fatals, PHP fatal errors, uncaught throwables), so they lean on
 * nothing but raw PHP: append the event to a durable on-device spool and
 * attempt one short best-effort POST to the devtools listener on the dev
 * machine. The endpoint comes from storage/framework/devtools.json, which
 * only `native:watch` ever provisions — without it the POST is skipped, and
 * the native drainer only runs in debug builds, so release builds are inert.
 */

if (! function_exists('nativephp_devtools_storage_path')) {
    function nativephp_devtools_storage_path(?string $storagePath): ?string
    {
        $storagePath ??= isset($_SERVER['LARAVEL_BOOTSTRAP_PATH'])
            ? dirname($_SERVER['LARAVEL_BOOTSTRAP_PATH']).'/storage'
            : null;

        return ($storagePath !== null && is_dir($storagePath)) ? $storagePath : null;
    }
}

if (! function_exists('nativephp_devtools_write_event')) {
    /**
     * Spool one event and best-effort POST it. The spool is the source of
     * truth; the native drainer delivers anything the POST couldn't.
     *
     * @param  array<string, mixed>  $exception  {class, message, file, line, trace?}
     */
    function nativephp_devtools_write_event(string $kind, string $mode, array $exception, ?string $storagePath = null): void
    {
        try {
            $storagePath = nativephp_devtools_storage_path($storagePath);

            if ($storagePath === null) {
                return;
            }

            $event = [
                'v' => 1,
                'id' => bin2hex(random_bytes(16)),
                'ts' => gmdate('Y-m-d\TH:i:s\Z'),
                'kind' => $kind,
                'mode' => $mode,
                'platform' => PHP_OS_FAMILY === 'Darwin' ? 'ios' : 'android',
                'exception' => $exception,
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

if (! function_exists('nativephp_devtools_report_throwable')) {
    function nativephp_devtools_report_throwable(Throwable $e, string $kind, string $mode, ?string $storagePath = null): void
    {
        nativephp_devtools_write_event($kind, $mode, [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 50),
        ], $storagePath);

        $GLOBALS['__nativephp_devtools_reported'] = true;
    }
}

if (! function_exists('nativephp_devtools_boot_report')) {
    function nativephp_devtools_boot_report(Throwable $e, ?string $storagePath = null): void
    {
        nativephp_devtools_report_throwable($e, 'boot_fatal', 'boot', $storagePath);
    }
}

if (! function_exists('nativephp_devtools_report_last_fatal')) {
    /**
     * Catch a fatal the persistent runtime swallowed. In the persistent
     * runtime a PHP fatal triggers a Zend bailout that the native host
     * catches to stay alive, so register_shutdown_function never fires. But
     * error_get_last() still holds it — so on the next dispatch/render entry
     * we report it (deduped so the same fatal isn't re-sent every cycle).
     */
    function nativephp_devtools_report_last_fatal(?string $storagePath = null): void
    {
        $error = error_get_last();
        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

        if ($error === null || ($error['type'] & $fatal) === 0) {
            return;
        }

        $signature = $error['message'].'|'.$error['file'].'|'.$error['line'];

        if (($GLOBALS['__nativephp_devtools_last_fatal_sig'] ?? null) === $signature) {
            return;
        }
        $GLOBALS['__nativephp_devtools_last_fatal_sig'] = $signature;

        nativephp_devtools_write_event('fatal', 'dispatch', [
            'class' => 'PHP Fatal Error',
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
            'trace' => [],
        ], $storagePath);
    }
}

if (! function_exists('nativephp_devtools_install_handlers')) {
    /**
     * Catch what the targeted try/catch sites can't: any uncaught throwable
     * that escapes the runtime, and true PHP fatal errors (E_ERROR, OOM,
     * undefined method, exceeded time limit) which no try/catch can trap.
     * Installed once, early, so the whole process lifetime is covered.
     *
     * Idempotent. The shutdown handler is additive and safe; the exception
     * handler chains whatever was previously installed so existing behavior
     * (Laravel's handler, the bootstrap echoes) is preserved.
     */
    function nativephp_devtools_install_handlers(?string $storagePath = null): void
    {
        if (! empty($GLOBALS['__nativephp_devtools_handlers_installed'])) {
            return;
        }
        $GLOBALS['__nativephp_devtools_handlers_installed'] = true;

        $resolved = nativephp_devtools_storage_path($storagePath);

        // Uncaught throwables that reached the top of the stack (i.e. did NOT
        // hit one of the framework's catch sites, which handle and swallow).
        $previous = set_exception_handler(function (Throwable $e) use ($resolved, &$previous) {
            nativephp_devtools_report_throwable($e, 'exception', 'uncaught', $resolved);

            if ($previous !== null) {
                $previous($e);
            }
        });

        // Genuine fatals — the only place they surface is at shutdown. Skip if
        // we already reported this cycle (an uncaught throwable also lands here
        // as the last error) to avoid a duplicate.
        register_shutdown_function(function () use ($resolved) {
            if (! empty($GLOBALS['__nativephp_devtools_reported'])) {
                return;
            }

            $error = error_get_last();
            $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

            if ($error === null || ($error['type'] & $fatal) === 0) {
                return;
            }

            $GLOBALS['__nativephp_devtools_last_fatal_sig'] = $error['message'].'|'.$error['file'].'|'.$error['line'];

            nativephp_devtools_write_event('fatal', 'shutdown', [
                'class' => 'PHP Fatal Error',
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'trace' => [],
            ], $resolved);
        });
    }
}
