<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

if (isset($_SERVER['HTTP_COOKIE'])) {
    parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $cookies);
    $_COOKIE = $cookies;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_SERVER['QUERY_STRING'])) {
        parse_str($_SERVER['QUERY_STRING'], $parsed);
        $_GET = $parsed;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    parse_str(file_get_contents('php://input'), $parsed);
    $_POST = $parsed;
}

define('LARAVEL_START', microtime(true));

require $_SERVER['COMPOSER_AUTOLOADER_PATH'];

$app = require_once $_SERVER['LARAVEL_BOOTSTRAP_PATH'].'/app.php';

$kernel = $app->make(Kernel::class);

try {
    $request = Request::capture();
    try {
        $kernel->bootstrap();
    } catch (Throwable $e) {
        throw $e;
    }

    try {
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $code = $response->getStatusCode();
        $status = Response::$statusTexts[$code];
        echo "HTTP/1.1 {$code} {$status}\r\n";

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

} catch (Throwable $e) {
    echo 'DEBUG: Fatal error: '.$e->getMessage()."\n";
    echo 'DEBUG: Error type: '.get_class($e)."\n";
    echo "DEBUG: Trace:\n".$e->getTraceAsString()."\n";
    throw $e;
}
