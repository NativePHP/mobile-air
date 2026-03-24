<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

$_timing = ['start' => microtime(true)];

// Capture OPcache status early (will be logged later with timing)
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

require $_SERVER['COMPOSER_AUTOLOADER_PATH'];
$_timing['autoload'] = microtime(true);

$app = require_once $_SERVER['LARAVEL_BOOTSTRAP_PATH'].'/app.php';
$_timing['bootstrap'] = microtime(true);

/*
|--------------------------------------------------------------------------
| Normalize incoming environment
|--------------------------------------------------------------------------
| We want to make sure Laravel sees:
| - full query params (even for POSTs)
| - real cookies (without mangling)
| - raw input untouched (for JSON & file uploads)
|--------------------------------------------------------------------------
*/

// ✅ Preserve cookies as-is
if (isset($_SERVER['HTTP_COOKIE'])) {
    $cookiePairs = explode('; ', $_SERVER['HTTP_COOKIE']);
    $cookies = [];
    foreach ($cookiePairs as $pair) {
        $parts = explode('=', $pair, 2);
        if (count($parts) === 2) {
            $cookies[$parts[0]] = urldecode($parts[1]);
        }
    }
    $_COOKIE = $cookies;
}

// ✅ Preserve query params for ALL request methods
if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
    parse_str($_SERVER['QUERY_STRING'], $_GET);
}

// ✅ Let Laravel handle POST parsing itself (important for multipart/form-data)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Don't manually parse php://input — Laravel will handle JSON/form-data properly
}

/*
|--------------------------------------------------------------------------
| Handle Laravel request
|--------------------------------------------------------------------------
*/

$kernel = $app->make(Kernel::class);
$_timing['kernel'] = microtime(true);

try {
    $request = Request::capture();
    $_timing['capture'] = microtime(true);

    // Bind request so service providers can resolve it during bootstrap
    $app->instance('request', $request);

    $kernel->bootstrap();
    $_timing['kernel_bootstrap'] = microtime(true);

    // Bind originalRequest AFTER bootstrap — Filament's SupportServiceProvider
    // registers a scoped('originalRequest') during boot that would overwrite
    // an earlier instance() call. This must come after to take precedence.
    $app->instance('originalRequest', $request);

    $response = $kernel->handle($request);
    $_timing['handle'] = microtime(true);

    $kernel->terminate($request, $response);
    $_timing['terminate'] = microtime(true);

    // Calculate timing breakdown (in ms)
    $autoloadMs = round(($_timing['autoload'] - $_timing['start']) * 1000, 1);
    $bootstrapMs = round(($_timing['bootstrap'] - $_timing['autoload']) * 1000, 1);
    $kernelMs = round(($_timing['kernel'] - $_timing['bootstrap']) * 1000, 1);
    $captureMs = round(($_timing['capture'] - $_timing['kernel']) * 1000, 1);
    $kernelBootMs = round(($_timing['kernel_bootstrap'] - $_timing['capture']) * 1000, 1);
    $handleMs = round(($_timing['handle'] - $_timing['kernel_bootstrap']) * 1000, 1);
    $terminateMs = round(($_timing['terminate'] - $_timing['handle']) * 1000, 1);
    $totalMs = round(($_timing['terminate'] - $_timing['start']) * 1000, 1);

    // Log timing via error_log (shows in logcat)
    error_log("PerfTiming: PHP opcache={$_opcacheInfo} autoload={$autoloadMs}ms bootstrap={$bootstrapMs}ms kernel={$kernelMs}ms capture={$captureMs}ms kernel_boot={$kernelBootMs}ms handle={$handleMs}ms terminate={$terminateMs}ms TOTAL={$totalMs}ms");

    // Send headers and body manually (for your bridge)
    $code = $response->getStatusCode();
    $status = Response::$statusTexts[$code] ?? 'OK';
    echo "HTTP/1.1 {$code} {$status}\r\n";

    // Add timing header
    echo "X-PHP-Timing: opcache={$_opcacheInfo},autoload={$autoloadMs}ms,bootstrap={$bootstrapMs}ms,kernel_boot={$kernelBootMs}ms,handle={$handleMs}ms,total={$totalMs}ms\r\n";

    foreach ($response->headers->all() as $name => $values) {
        foreach ($values as $value) {
            echo "{$name}: {$value}\r\n";
        }
    }

    echo "\r\n";
    $response->sendContent();

} catch (Throwable $e) {
    echo 'DEBUG: Request handling error: '.$e->getMessage()."\n";
    echo 'DEBUG: Error type: '.get_class($e)."\n";
    echo "DEBUG: Trace:\n".$e->getTraceAsString()."\n";
}
