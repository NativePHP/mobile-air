<?php

namespace Native\Mobile\Http\Bridge;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Builds the Laravel request for an embedded runtime from the parts the
 * native side hands over, and turns those parts into the values a SAPI would
 * have put in $_SERVER, $_GET and $_COOKIE.
 *
 * Which server variables a runtime sets is its own business. This class only
 * does the parts every runtime does the same way.
 */
final class RequestFactory
{
    /**
     * The request as Request::capture() would build it, but from the parts.
     *
     * Request::capture() calls createFromGlobals(), which on Symfony 8 calls
     * request_parse_body() for PUT, DELETE, PATCH and QUERY. On the embed SAPI
     * that either empties php://input or calls a read_post handler the SAPI
     * doesn't have. Building from the parts also means php://input is read
     * once. The uploads go in as the Illuminate UploadedFile objects in
     * $parsed, which know they didn't come through the SAPI, so isValid() and
     * move() work.
     *
     * @param  array<array-key, mixed>  $query  $_GET
     * @param  array<array-key, mixed>  $cookies  $_COOKIE
     * @param  array<array-key, mixed>  $server  $_SERVER
     */
    public static function make(array $query, ParsedBody $parsed, array $cookies, array $server, string $body): Request
    {
        Request::enableHttpMethodParameterOverride();

        return Request::createFromBase(new SymfonyRequest(
            $query,
            $parsed->fields,
            [],
            $cookies,
            self::withoutEmptyFiles($parsed->files),
            $server,
            $body,
        ));
    }

    /**
     * "Name: value" lines joined by CRLF (a bare LF works too) as $_SERVER
     * keys: HTTP_ plus the name upper-cased with dashes as underscores. Names
     * and values are trimmed; a line without a colon or a name is skipped. A
     * repeated header is joined with ", ", or "; " for Cookie.
     *
     * @return array<string, string>
     */
    public static function headerVariables(string $block): array
    {
        $vars = [];

        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $name = trim(substr($line, 0, $colon));

            if ($name === '') {
                continue;
            }

            $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
            $value = trim(substr($line, $colon + 1));

            $vars[$key] = isset($vars[$key])
                ? $vars[$key].($key === 'HTTP_COOKIE' ? '; ' : ', ').$value
                : $value;
        }

        return $vars;
    }

    /**
     * A Cookie header as name => value: split on ";", each pair trimmed and
     * split at its first "=", the value urldecoded. Pairs without a "=" or
     * without a name are skipped, and a later cookie of the same name wins.
     *
     * @return array<string, string>
     */
    public static function cookies(string $header): array
    {
        $cookies = [];

        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', trim($pair), 2);

            if (count($parts) === 2 && $parts[0] !== '') {
                $cookies[$parts[0]] = urldecode($parts[1]);
            }
        }

        return $cookies;
    }

    /**
     * A query string as PHP puts it in $_GET, max_input_vars included. PHP
     * would log a warning at request startup, where Laravel's error handler
     * would turn it into an exception, so warnings go into $warnings instead.
     *
     * @param  list<string>  $warnings
     *
     * @param-out list<string>  $warnings
     *
     * @return array<array-key, mixed>
     */
    public static function query(string $queryString, array &$warnings = []): array
    {
        $query = [];

        if ($queryString === '') {
            return $query;
        }

        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            parse_str($queryString, $query);
        } finally {
            restore_error_handler();
        }

        return $query;
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
}
