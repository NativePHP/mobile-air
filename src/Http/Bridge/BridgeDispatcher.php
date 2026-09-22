<?php

namespace Native\Mobile\Http\Bridge;

use InvalidArgumentException;
use Native\Mobile\Runtime;
use Throwable;

/**
 * The one PHP call the native bridge makes for each web request on the
 * persistent and embedded-webview lanes, on iOS and Android. It replaces the
 * ~90 lines of PHP that used to be spliced into C strings and eval'd per
 * request. The C side evals a single line:
 *
 *     \Native\Mobile\Http\Bridge\BridgeDispatcher::handle('ios', 'persistent',
 *         '<method>', '<uri>', '<script>', '<cookie>', '<content type>', '<headers>');
 *
 * with every request value base64-encoded, so nothing from the request is
 * ever parsed as PHP. The body goes into php://input, and exactly one raw
 * HTTP response with an exact content-length comes back through the output.
 */
final class BridgeDispatcher
{
    public const PLATFORMS = ['ios', 'android'];

    public const LANES = ['persistent', 'webview'];

    /**
     * Where handle() reads the request body from.
     *
     * @internal Tests point this at a data: URL, because php://input is empty under the CLI.
     */
    public static string $input = 'php://input';

    /**
     * handle() closes every output buffer above this level before it starts.
     *
     * @internal Tests raise it so PHPUnit keeps its own buffer.
     */
    public static int $bufferFloor = 0;

    /**
     * Serve one request and echo the raw HTTP response.
     *
     * $platform and $lane are plain strings. Every other argument is base64 so
     * that nothing from the request is ever parsed as PHP. The body is not an
     * argument: it is read from php://input, which the C side filled with
     * the exact bytes.
     *
     * @param  string  $platform  'ios' or 'android'
     * @param  string  $lane  'persistent' or 'webview'
     * @param  string  $method  base64 request method
     * @param  string  $uri  base64 request URI: path plus "?query" if there is one
     * @param  string  $scriptPath  base64 path of the native.php front controller
     * @param  string  $cookie  base64 Cookie header value, or ''
     * @param  string  $contentType  base64 Content-Type of the body, or ''
     * @param  string  $headers  base64 block of "Name: value" lines joined by CRLF, or ''
     */
    public static function handle(
        string $platform,
        string $lane,
        string $method,
        string $uri,
        string $scriptPath,
        string $cookie,
        string $contentType,
        string $headers,
    ): void {
        while (ob_get_level() > self::$bufferFloor) {
            ob_end_clean();
        }

        try {
            $body = file_get_contents(self::$input);

            [$head, $content] = self::serve(
                $platform,
                $lane,
                self::decode('method', $method),
                self::decode('uri', $uri),
                self::decode('scriptPath', $scriptPath),
                self::decode('cookie', $cookie),
                self::decode('contentType', $contentType),
                self::decode('headers', $headers),
                $body === false ? '' : $body,
            );
        } catch (Throwable $e) {
            [$head, $content] = self::errorResponse($lane, $e);
        }

        echo $head;
        echo $content;
    }

    /**
     * Everything handle() does, with plain (not base64) arguments and the body
     * passed in, returning the raw HTTP response instead of echoing it.
     */
    public static function dispatch(
        string $platform,
        string $lane,
        string $method,
        string $uri,
        string $scriptPath,
        string $cookie = '',
        string $contentType = '',
        string $headers = '',
        string $body = '',
    ): string {
        try {
            [$head, $content] = self::serve($platform, $lane, $method, $uri, $scriptPath, $cookie, $contentType, $headers, $body);
        } catch (Throwable $e) {
            [$head, $content] = self::errorResponse($lane, $e);
        }

        return $head.$content;
    }

    /**
     * Set up the superglobals for this request. Returns what was parsed out of
     * the body; the caller owns cleaning up its temp files.
     */
    public static function prepareGlobals(
        string $platform,
        string $lane,
        string $method,
        string $uri,
        string $scriptPath,
        string $cookie,
        string $contentType,
        string $headers,
        string $body,
    ): ParsedBody {
        if (! in_array($platform, self::PLATFORMS, true)) {
            throw new InvalidArgumentException("Unknown bridge platform [{$platform}].");
        }

        if (! in_array($lane, self::LANES, true)) {
            throw new InvalidArgumentException("Unknown bridge lane [{$lane}].");
        }

        // $_SERVER outlives each request in a persistent context. Drop the
        // previous request's headers before this one's go in.
        foreach (array_keys($_SERVER) as $key) {
            if (self::isHeaderKey((string) $key)) {
                unset($_SERVER[$key]);
            }
        }

        // The persistent lanes pick up the process environment on every
        // request, as they always have. Request headers never come from it
        // any more: they come from $headers, so a header set in the env for
        // one request can't leak into the next.
        if ($lane === 'persistent') {
            foreach (getenv() as $key => $value) {
                if (! self::isHeaderKey($key)) {
                    $_SERVER[$key] = $value;
                }
            }
        }

        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['SCRIPT_FILENAME'] = $scriptPath;
        $_SERVER['PHP_SELF'] = '/native.php';
        $_SERVER['SERVER_NAME'] = '127.0.0.1';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['APP_URL'] = $platform === 'ios' ? 'php://127.0.0.1' : 'http://127.0.0.1';
        $_SERVER['NATIVEPHP_RUNNING'] = 'true';
        $_SERVER['NATIVEPHP_PLATFORM'] = $platform;
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        $_SERVER['REQUEST_TIME'] = (int) $_SERVER['REQUEST_TIME_FLOAT'];

        foreach (RequestFactory::headerVariables($headers) as $key => $value) {
            $_SERVER[$key] = $value;
        }

        $_SERVER['HTTP_HOST'] = '127.0.0.1';

        if ($cookie !== '') {
            $_SERVER['HTTP_COOKIE'] = $cookie;
        }

        // The content type that travelled with the body describes it; a header
        // copy may describe what the page sent before the bridge re-encoded it.
        if ($contentType !== '') {
            $_SERVER['CONTENT_TYPE'] = $contentType;
            $_SERVER['HTTP_CONTENT_TYPE'] = $contentType;
        } elseif (isset($_SERVER['HTTP_CONTENT_TYPE'])) {
            $_SERVER['CONTENT_TYPE'] = $_SERVER['HTTP_CONTENT_TYPE'];
        }

        // The real length, so Laravel's ValidatePostSize sees what PHP-FPM
        // would show it.
        if ($body !== '' || isset($_SERVER['HTTP_CONTENT_LENGTH'])) {
            $_SERVER['CONTENT_LENGTH'] = (string) strlen($body);

            if (isset($_SERVER['HTTP_CONTENT_LENGTH'])) {
                $_SERVER['HTTP_CONTENT_LENGTH'] = $_SERVER['CONTENT_LENGTH'];
            }
        }

        $queryAt = strpos($uri, '?');
        $_SERVER['QUERY_STRING'] = $queryAt === false ? '' : substr($uri, $queryAt + 1);

        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_REQUEST = [];

        $warnings = [];
        $_COOKIE = RequestFactory::cookies($_SERVER['HTTP_COOKIE'] ?? '');
        $_GET = RequestFactory::query($_SERVER['QUERY_STRING'], $warnings);

        $parsed = (new RequestBodyParser(tempDir: self::tempDir()))
            ->parse($method, $_SERVER['CONTENT_TYPE'] ?? null, $body);

        $_POST = $parsed->fields;
        $_FILES = $parsed->phpFiles;

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && $body !== '') {
            $_REQUEST = array_merge($_GET, $_POST, $_COOKIE);
        }

        // PHP logs these at request startup. Laravel's error handler would turn
        // them into exceptions, so they are logged directly instead.
        foreach ([...$warnings, ...$parsed->warnings] as $warning) {
            error_log('PHP Warning:  '.$warning);
        }

        return $parsed;
    }

    /**
     * @return array{string, string} the head and the body
     */
    private static function serve(
        string $platform,
        string $lane,
        string $method,
        string $uri,
        string $scriptPath,
        string $cookie,
        string $contentType,
        string $headers,
        string $body,
    ): array {
        $parsed = null;

        try {
            $parsed = self::prepareGlobals($platform, $lane, $method, $uri, $scriptPath, $cookie, $contentType, $headers, $body);

            // Built from the superglobals just set, as Request::capture() did.
            $request = RequestFactory::make($_GET, $parsed, $_COOKIE, $_SERVER, $body);

            // Anything echoed outside the response (a stray echo, dump()) would
            // otherwise land in front of the status line. Keep it and put it at
            // the start of the body, where PHP-FPM would have sent it.
            $response = OutputCapture::run(fn () => Runtime::dispatch($request), $stray);

            $content = $stray.RawHttpResponse::content($response);

            return [RawHttpResponse::head($response, strlen($content)), $content];
        } finally {
            $parsed?->cleanup();
        }
    }

    /**
     * The plain-text 500 the old eval printed, now with a content-length.
     *
     * @return array{string, string}
     */
    private static function errorResponse(string $lane, Throwable $e): array
    {
        $prefix = $lane === 'webview' ? 'Webview' : 'Persistent';
        $content = "{$prefix} dispatch error: ".$e->getMessage()."\n".$e->getTraceAsString();

        return [
            "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\nContent-Length: ".strlen($content)."\r\n\r\n",
            $content,
        ];
    }

    private static function isHeaderKey(string $key): bool
    {
        return str_starts_with($key, 'HTTP_') || $key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH';
    }

    private static function decode(string $name, string $value): string
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            throw new InvalidArgumentException("Bridge argument [{$name}] is not valid base64.");
        }

        return $decoded;
    }

    private static function tempDir(): ?string
    {
        $dir = getenv('NATIVEPHP_TEMPDIR');

        return is_string($dir) && $dir !== '' ? $dir : null;
    }
}
