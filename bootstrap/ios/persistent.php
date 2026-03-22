<?php

/**
 * Persistent PHP Runtime Bootstrap — iOS
 *
 * Executed ONCE when the persistent interpreter boots.
 * Loads Composer autoloader, boots Laravel, creates the kernel,
 * and stores references for Runtime::dispatch() to reuse.
 *
 * After this script runs, the PHP interpreter stays alive.
 * Subsequent requests are dispatched via Runtime::dispatch()
 * through zend_eval_string() — no more init/shutdown per request.
 */

use Native\Mobile\Runtime;

$_timing = ['start' => microtime(true)];

// Capture OPcache status
$_opcacheInfo = 'unknown';
if (function_exists('opcache_get_status')) {
    $opcacheStatus = @opcache_get_status(false);
    if ($opcacheStatus) {
        $_opcacheInfo = 'enabled='.($opcacheStatus['opcache_enabled'] ? 'YES' : 'NO');
        $_opcacheInfo .= ',cached='.($opcacheStatus['opcache_statistics']['num_cached_scripts'] ?? 0);
    } else {
        $_opcacheInfo = 'disabled';
    }
} else {
    $_opcacheInfo = 'NOT_AVAILABLE';
}

define('LARAVEL_START', microtime(true));

// 1. Autoload (once)
require $_SERVER['COMPOSER_AUTOLOADER_PATH'];
$_timing['autoload'] = microtime(true);

// 2. Bootstrap Laravel application (once)
$app = require_once $_SERVER['LARAVEL_BOOTSTRAP_PATH'].'/app.php';
$_timing['bootstrap'] = microtime(true);

// 3. Boot the persistent runtime — stores kernel + app for reuse
try {
    Runtime::boot($app);
} catch (Throwable $e) {
    error_log('PERSISTENT BOOT FATAL: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    error_log('PERSISTENT BOOT TRACE: '.$e->getTraceAsString());
    // Echo so the C layer can capture it in the output buffer
    echo 'BOOT_FATAL: '.$e->getMessage();
}
$_timing['runtime_boot'] = microtime(true);

// Calculate timing
$autoloadMs = round(($_timing['autoload'] - $_timing['start']) * 1000, 1);
$bootstrapMs = round(($_timing['bootstrap'] - $_timing['autoload']) * 1000, 1);
$runtimeBootMs = round(($_timing['runtime_boot'] - $_timing['bootstrap']) * 1000, 1);
$totalMs = round(($_timing['runtime_boot'] - $_timing['start']) * 1000, 1);

error_log("PerfTiming: persistent.php opcache={$_opcacheInfo} autoload={$autoloadMs}ms bootstrap={$bootstrapMs}ms runtime_boot={$runtimeBootMs}ms TOTAL={$totalMs}ms");

// The interpreter stays alive from here.
// All subsequent work happens via zend_eval_string() calling Runtime::dispatch().
