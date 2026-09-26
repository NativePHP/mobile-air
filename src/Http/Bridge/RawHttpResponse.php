<?php

namespace Native\Mobile\Http\Bridge;

use Symfony\Component\HttpFoundation\Response;

/**
 * Writes a Symfony or Laravel response as raw HTTP/1.1 bytes: a status line,
 * one "name: value" line per header, a blank line, then the body exactly as
 * the response produced it. Native code reads this back and splits it at the
 * first blank line.
 *
 * The body is collected first so the head can carry its exact length in
 * content-length. Any content-length the app set is replaced by it, and
 * transfer-encoding is dropped, because the body is never chunked.
 */
final class RawHttpResponse
{
    private const WHITESPACE = " \t\n\r\x0B\x0C";

    public static function toString(Response $response): string
    {
        $body = self::content($response);

        return self::head($response, strlen($body)).$body;
    }

    /** Echo the same bytes toString() returns, without building them into one string. */
    public static function emit(Response $response): void
    {
        $body = self::content($response);

        echo self::head($response, strlen($body));
        echo $body;
    }

    /**
     * The status line and headers for a body of $contentLength bytes, ending
     * in the blank line. Header names are the lower-case ones Symfony keeps.
     * A header that PHP's header() would refuse, because it holds a CR, LF
     * or NUL, is left out rather than allowed to break the framing.
     */
    public static function head(Response $response, int $contentLength): string
    {
        $code = $response->getStatusCode();
        $text = Response::$statusTexts[$code] ?? 'OK';
        $head = "HTTP/1.1 {$code} {$text}\r\n";

        foreach ($response->headers->all() as $name => $values) {
            $lower = strtolower((string) $name);

            if ($lower === 'content-length' || $lower === 'transfer-encoding') {
                continue;
            }

            foreach ($values as $value) {
                $line = rtrim($name.': '.$value, self::WHITESPACE);

                if (strpbrk($line, "\r\n\0") === false) {
                    $head .= $line."\r\n";
                }
            }
        }

        return $head."content-length: {$contentLength}\r\n\r\n";
    }

    /**
     * Run sendContent() and return every byte it wrote, however it wrote them:
     * echo for a plain Response, a callback for a StreamedResponse, or
     * php://output for a BinaryFileResponse. The callback's ob_flush() and
     * flush() calls stay inside the capture, and buffers it left open are
     * flushed into the body, as they would be at the end of a request.
     */
    public static function content(Response $response): string
    {
        OutputCapture::run(fn () => $response->sendContent(), $body);

        return $body;
    }
}
