<?php

namespace Native\Mobile\Http\Bridge;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Native\Mobile\Runtime;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
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

        foreach (self::parseHeaders($headers) as $key => $value) {
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

        if (isset($_SERVER['HTTP_COOKIE']) && $_SERVER['HTTP_COOKIE'] !== '') {
            foreach (explode('; ', $_SERVER['HTTP_COOKIE']) as $pair) {
                $parts = explode('=', $pair, 2);

                if (count($parts) === 2) {
                    $_COOKIE[$parts[0]] = urldecode($parts[1]);
                }
            }
        }

        $warnings = [];

        if ($_SERVER['QUERY_STRING'] !== '') {
            set_error_handler(function (int $level, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            });

            try {
                parse_str($_SERVER['QUERY_STRING'], $_GET);
            } finally {
                restore_error_handler();
            }
        }

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
     * Build the Request from the superglobals as Request::capture() does, but
     * hand it the body and the uploads directly.
     *
     * Request::capture() would read php://input a second time, and on
     * Symfony 8 it calls request_parse_body() for PUT, DELETE, PATCH and
     * QUERY. On the embed SAPI that function either empties php://input
     * (urlencoded) or calls a read_post handler the SAPI doesn't have
     * (multipart). The uploads go in as Illuminate UploadedFile objects that
     * know they didn't come through the SAPI, so isValid() and move() work.
     */
    public static function captureRequest(ParsedBody $parsed, string $body): Request
    {
        Request::enableHttpMethodParameterOverride();

        return Request::createFromBase(
            new SymfonyRequest($_GET, $_POST, [], $_COOKIE, self::withoutEmptyFiles($parsed->files), $_SERVER, $body)
        );
    }

    /**
     * An empty file input leaves no key behind, as Laravel's
     * Request::filterFiles() does with what PHP-FPM gives it.
     *
     * @param  array<array-key, mixed>  $files
     * @return array<array-key, mixed>
     */
    private static function withoutEmptyFiles(array $files): array
    {
        foreach ($files as $key => $file) {
            if (is_array($file)) {
                $files[$key] = self::withoutEmptyFiles($file);
            }

            if (empty($files[$key])) {
                unset($files[$key]);
            }
        }

        return $files;
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
            $request = self::captureRequest($parsed, $body);

            // Anything echoed outside the response (a stray echo, dump()) would
            // otherwise land in front of the status line. Keep it and put it at
            // the start of the body, where PHP-FPM would have sent it.
            $level = ob_get_level();
            ob_start();

            try {
                $response = Runtime::dispatch($request);
            } finally {
                $stray = self::endBuffers($level);
            }

            $content = $stray.RawHttpResponse::content($response);

            return [RawHttpResponse::head($response, strlen($content)), $content];
        } finally {
            $parsed?->cleanup();
        }
    }

    private static function endBuffers(int $level): string
    {
        while (ob_get_level() > $level + 1) {
            ob_end_flush();
        }

        return ob_get_level() > $level ? (string) ob_get_clean() : '';
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

    /**
     * "Name: value" lines joined by CRLF (a bare LF works too) as $_SERVER
     * keys: HTTP_ plus the name upper-cased with dashes as underscores. A
     * repeated header is joined with ", ", or "; " for Cookie.
     *
     * @return array<string, string>
     */
    public static function parseHeaders(string $block): array
    {
        $server = [];

        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $name = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1), " \t");

            if ($name === '') {
                continue;
            }

            $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));

            $server[$key] = isset($server[$key])
                ? $server[$key].($key === 'HTTP_COOKIE' ? '; ' : ', ').$value
                : $value;
        }

        return $server;
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
