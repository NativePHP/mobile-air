<?php

/*
 * A Laravel app for running bootstrap/{ios,android}/native.php the way classic
 * mode runs them: once per request, in a fresh process. It has one route that
 * reports what the request looked like to Laravel.
 */

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

return $app;
