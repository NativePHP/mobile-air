<?php

use Illuminate\Contracts\Http\Kernel;
use Native\Mobile\Http\Bridge\BridgeDispatcher;
use Native\Mobile\Http\Bridge\OutputCapture;
use Native\Mobile\Http\Bridge\RawHttpResponse;
use Native\Mobile\Support\Ios\Request;
use Symfony\Component\HttpFoundation\Response;

$_timing = ['start' => microtime(true)];

define('LARAVEL_START', microtime(true));

// Register the Composer autoloader...
require __DIR__.'/../../../../autoload.php';
$_timing['autoload'] = microtime(true);

// Bootstrap Laravel and handle the request...
$app = require_once __DIR__.'/../../../../../bootstrap/app.php';
$_timing['bootstrap'] = microtime(true);

$kernel = $app->make(Kernel::class);
$_timing['kernel'] = microtime(true);

// Query params, cookies, and the body from php://input parsed into $_POST,
// $_FILES and $request->file(), the way PHP-FPM would. Not Request::capture():
// on the embed SAPI it never fills $_FILES, and on Symfony 8 it calls
// request_parse_body(). Upload temp files the app didn't move are deleted when
// the request ends, as PHP does.
[$request, $parsedBody] = BridgeDispatcher::classicRequest(Request::class);
register_shutdown_function(fn () => $parsedBody->cleanup());
$_timing['capture'] = microtime(true);

// Bind request so service providers can resolve it during bootstrap
$app->instance('request', $request);

$kernel->bootstrap();

// Bind originalRequest AFTER bootstrap — Filament's SupportServiceProvider
// registers a scoped('originalRequest') during boot that would overwrite
// an earlier instance() call. This must come after to take precedence.
$app->instance('originalRequest', $request);

// Anything echoed outside the response (a stray echo, dump()) is kept for the
// body instead of landing in front of the status line.
/** @var Response $response */
$response = OutputCapture::run(fn () => $kernel->handle($request), $stray);
$_timing['handle'] = microtime(true);

OutputCapture::run(fn () => $kernel->terminate($request, $response), $late);
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

// Raw HTTP/1.1, as the persistent and webview lanes write it: status line,
// headers, a blank line, then the body byte for byte with an exact
// content-length.
$response->headers->set('X-PHP-Timing', "autoload={$autoloadMs}ms,bootstrap={$bootstrapMs}ms,handle={$handleMs}ms,total={$totalMs}ms");

$content = $stray.RawHttpResponse::content($response).$late;

echo RawHttpResponse::head($response, strlen($content));
echo $content;
