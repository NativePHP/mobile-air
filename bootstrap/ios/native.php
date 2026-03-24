<?php

use Illuminate\Contracts\Http\Kernel;
use Native\Mobile\Support\Ios\Request;
use Symfony\Component\HttpFoundation\Response;

$_timing = ['start' => microtime(true)];

if (isset($_SERVER['HTTP_COOKIE'])) {
    parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $cookies);
    $_COOKIE = $cookies;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    parse_str($_SERVER['QUERY_STRING'], $parsed);
    $_GET = $parsed;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    parse_str(file_get_contents('php://input'), $parsed);
    $_POST = $parsed;
}

define('LARAVEL_START', microtime(true));

// Register the Composer autoloader...
require __DIR__.'/../../../../autoload.php';
$_timing['autoload'] = microtime(true);

// Bootstrap Laravel and handle the request...
$app = require_once __DIR__.'/../../../../../bootstrap/app.php';
$_timing['bootstrap'] = microtime(true);

$kernel = $app->make(Kernel::class);
$_timing['kernel'] = microtime(true);

$request = Request::capture();
$_timing['capture'] = microtime(true);

// Bind request so service providers can resolve it during bootstrap
$app->instance('request', $request);

$kernel->bootstrap();

// Bind originalRequest AFTER bootstrap — Filament's SupportServiceProvider
// registers a scoped('originalRequest') during boot that would overwrite
// an earlier instance() call. This must come after to take precedence.
$app->instance('originalRequest', $request);

/** @var Response $response */
$response = $kernel->handle($request);
$_timing['handle'] = microtime(true);

$kernel->terminate($request, $response);
$_timing['terminate'] = microtime(true);

// Calculate timing breakdown (in ms)
$autoloadMs = round(($_timing['autoload'] - $_timing['start']) * 1000, 1);
$bootstrapMs = round(($_timing['bootstrap'] - $_timing['autoload']) * 1000, 1);
$kernelMs = round(($_timing['kernel'] - $_timing['bootstrap']) * 1000, 1);
$captureMs = round(($_timing['capture'] - $_timing['kernel']) * 1000, 1);
$handleMs = round(($_timing['handle'] - $_timing['capture']) * 1000, 1);
$terminateMs = round(($_timing['terminate'] - $_timing['handle']) * 1000, 1);
$totalMs = round(($_timing['terminate'] - $_timing['start']) * 1000, 1);

// Log timing (shows in Xcode console)
error_log("PerfTiming: PHP autoload={$autoloadMs}ms bootstrap={$bootstrapMs}ms kernel={$kernelMs}ms capture={$captureMs}ms handle={$handleMs}ms terminate={$terminateMs}ms TOTAL={$totalMs}ms");

$code = $response->getStatusCode();
$status = Response::$statusTexts[$code] ?? ($code == 419 ? 'Page Expired' : 'Unknown');
echo "HTTP/1.1 {$code} {$status}\r\n";
echo "X-PHP-Timing: autoload={$autoloadMs}ms,bootstrap={$bootstrapMs}ms,handle={$handleMs}ms,total={$totalMs}ms\r\n";
echo $response->headers."\r\n\r\n";

$response->sendContent();
