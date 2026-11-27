<?php

/**
 * Whether the bootstraps should write their PerfTiming lines.
 *
 * On when NATIVEPHP_PERF_TIMING is truthy in the process environment (or the
 * app's .env, once Laravel has loaded it), otherwise when app.debug is on.
 * Release builds stay quiet unless someone asks for the timings.
 */
if (! function_exists('nativephp_perf_timing_enabled')) {
    function nativephp_perf_timing_enabled($app = null): bool
    {
        $flag = getenv('NATIVEPHP_PERF_TIMING');

        if ($flag === false) {
            $flag = $_ENV['NATIVEPHP_PERF_TIMING'] ?? $_SERVER['NATIVEPHP_PERF_TIMING'] ?? '';
        }

        if ($flag !== '' && $flag !== null) {
            return filter_var($flag, FILTER_VALIDATE_BOOL);
        }

        try {
            return $app !== null && $app->bound('config') && (bool) $app['config']->get('app.debug');
        } catch (Throwable) {
            return false;
        }
    }
}
