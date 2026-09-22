<?php

/*
 * A Laravel app for running the bridge the way the native side does: once per
 * request in a fresh process for classic mode, or through BridgeDispatcher for
 * the other lanes. /inspect reports what the request looked like to Laravel,
 * /bytes answers with every byte value, and a 413 reports what the request
 * held when Laravel's ValidatePostSize refused it.
 */

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Orchestra\Testbench\Foundation\Application;

use function Orchestra\Testbench\default_skeleton_path;

$app = Application::create(basePath: default_skeleton_path(), options: ['extra' => ['providers' => []]]);

$app['router']->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/inspect', fn (Request $request) => [
    'class' => $request::class,
    'method' => $request->method(),
    'schemeAndHost' => $request->getSchemeAndHttpHost(),
    'query' => $request->query(),
    'input' => $request->input(),
    'post' => $_POST,
    'cookies' => $request->cookies->all(),
    'contentLength' => $request->server('CONTENT_LENGTH'),
    'contentSha' => hash('sha256', $request->getContent()),
    'files' => array_map(fn (UploadedFile $file) => [
        'class' => $file::class,
        'name' => $file->getClientOriginalName(),
        'type' => $file->getClientMimeType(),
        'size' => $file->getSize(),
        'sha' => hash_file('sha256', $file->getPathname()),
        'valid' => $file->isValid(),
        'path' => $file->getPathname(),
    ], $request->allFiles()),
    'phpFiles' => array_keys($_FILES),
]);

$app['router']->get('/bytes', fn () => response(
    "\0".implode('', array_map('chr', range(0, 255)))."\r\n\r\n\0 end\n",
    200,
    ['Content-Type' => 'application/octet-stream'],
));

$app->make(ExceptionHandler::class)->renderable(fn (PostTooLargeException $e, Request $request) => response()->json([
    'heldBytes' => strlen($request->getContent()),
    'contentLength' => $request->server('CONTENT_LENGTH'),
    'post' => $_POST,
    'files' => $_FILES,
    'peakMemory' => memory_get_peak_usage(),
], 413));

return $app;
