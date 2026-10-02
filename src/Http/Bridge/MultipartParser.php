<?php

namespace Native\Mobile\Http\Bridge;

use Generator;

/**
 * Splits a multipart/form-data body into parts the way PHP's own upload
 * handler (main/rfc1867.c) reads it, so that a body the native bridge hands
 * to PHP comes out the same as it would under PHP-FPM.
 *
 * The details that matter, all taken from rfc1867.c:
 *
 * - A part starts after a line that is exactly "--boundary". Lines may end
 *   in LF or CRLF, and anything before the first such line is ignored.
 * - A part's content runs up to the next "\n--boundary". One CR in front of
 *   that LF is dropped. Nothing else is searched for, so content may hold
 *   NUL bytes, bare CRLFs and blank lines.
 * - Header names are matched case-insensitively and the first one wins.
 *   A line that starts with whitespace, or has no colon, continues the
 *   previous header. Header lines are C strings to PHP, so they end at NUL.
 * - Content-Disposition parameters may be quoted with " or ', and a
 *   backslash escapes the quote character or another backslash.
 */
final class MultipartParser
{
    /** rfc1867.c refuses boundaries longer than FILLUNIT - strlen("\r\n--"). */
    public const MAX_BOUNDARY_LENGTH = 5116;

    private const WHITESPACE = " \t\n\r\x0B\x0C";

    /**
     * The boundary parameter of a multipart Content-Type, read the way PHP
     * reads it. Returns null when there is none, when a quoted boundary is
     * never closed, or when it is too long for PHP to accept.
     */
    public static function boundary(string $contentType): ?string
    {
        $contentType = self::untilNul($contentType);

        $at = strpos($contentType, 'boundary');

        if ($at === false) {
            $at = stripos($contentType, 'boundary');
        }

        if ($at === false) {
            return null;
        }

        $equals = strpos($contentType, '=', $at);

        if ($equals === false) {
            return null;
        }

        $boundary = substr($contentType, $equals + 1);

        if (str_starts_with($boundary, '"')) {
            $close = strpos($boundary, '"', 1);

            if ($close === false) {
                return null;
            }

            $boundary = substr($boundary, 1, $close - 1);
        } else {
            $boundary = substr($boundary, 0, strcspn($boundary, ',;'));
        }

        if (strlen($boundary) > self::MAX_BOUNDARY_LENGTH) {
            return null;
        }

        return $boundary;
    }

    /**
     * Every part of the body, in order. The content of each part is only
     * cut out of the body when the generator reaches it.
     *
     * `name` and `filename` are null when the Content-Disposition header has
     * no such parameter, which is not the same as an empty value. `type` is
     * the part's Content-Type up to the first ";", as PHP stores it in
     * $_FILES. `headers` is keyed by lower-case name. `complete` is false
     * when the body ended before the next boundary.
     *
     * @return Generator<int, array{name: ?string, filename: ?string, type: ?string, headers: array<string, string>, content: string, complete: bool}>
     */
    public function parts(string $body, string $boundary): Generator
    {
        $delimiter = '--'.$boundary;
        $next = "\n--".$boundary;
        $pos = 0;

        while ($this->skipPastDelimiter($body, $pos, $delimiter)) {
            $headers = $this->readHeaders($body, $pos);
            [$content, $complete] = $this->readContent($body, $pos, $next);

            [$name, $filename] = isset($headers['content-disposition'])
                ? $this->disposition($headers['content-disposition'])
                : [null, null];

            $type = null;

            if (isset($headers['content-type'])) {
                $semicolon = strpos($headers['content-type'], ';');
                $type = $semicolon === false
                    ? $headers['content-type']
                    : substr($headers['content-type'], 0, $semicolon);
            }

            yield [
                'name' => $name,
                'filename' => $filename,
                'type' => $type,
                'headers' => $headers,
                'content' => $content,
                'complete' => $complete,
            ];
        }
    }

    /** rfc1867.c find_boundary(): read lines until one is exactly the delimiter. */
    private function skipPastDelimiter(string $body, int &$pos, string $delimiter): bool
    {
        while (($line = $this->line($body, $pos)) !== null) {
            if ($line === $delimiter) {
                return true;
            }
        }

        return false;
    }

    /**
     * rfc1867.c multipart_buffer_headers(): header lines up to the first
     * empty one, or to the end of the body.
     *
     * @return array<string, string>
     */
    private function readHeaders(string $body, int &$pos): array
    {
        $entries = [];
        $key = null;
        $value = '';

        while (($line = $this->line($body, $pos)) !== null && $line !== '') {
            $colon = ctype_space($line[0]) ? false : strpos($line, ':');

            if ($colon !== false) {
                if ($key !== null) {
                    $entries[] = [$key, $value];
                }

                $key = substr($line, 0, $colon);
                $value = ltrim(substr($line, $colon + 1), self::WHITESPACE);
            } elseif ($key !== null) {
                $value .= $line;
            }
        }

        if ($key !== null) {
            $entries[] = [$key, $value];
        }

        $headers = [];

        foreach ($entries as [$name, $headerValue]) {
            $headers[strtolower($name)] ??= $headerValue;
        }

        return $headers;
    }

    /**
     * rfc1867.c multipart_buffer_read(): the bytes up to the next "\n--boundary".
     * Without a full match, PHP still stops in front of a trailing partial
     * match at the very end of the body.
     *
     * @return array{string, bool}
     */
    private function readContent(string $body, int &$pos, string $next): array
    {
        $length = strlen($body);
        $end = strpos($body, $next, $pos);
        $complete = $end !== false;

        if ($end === false) {
            $end = $this->partialMatchAtEnd($body, $pos, $next);
        }

        $stopsAtBoundary = $end !== null;
        $end ??= $length;

        $content = substr($body, $pos, $end - $pos);

        if ($stopsAtBoundary && str_ends_with($content, "\r")) {
            $content = substr($content, 0, -1);
        }

        $pos = $end;

        return [$content, $complete];
    }

    private function partialMatchAtEnd(string $body, int $pos, string $next): ?int
    {
        $length = strlen($body);

        for ($i = max($pos, $length - strlen($next) + 1); $i < $length; $i++) {
            if ($body[$i] === "\n" && str_starts_with($next, substr($body, $i))) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The next LF-terminated line, without its line ending, cut at the first
     * NUL the way C sees it. Null when no complete line is left.
     */
    private function line(string $body, int &$pos): ?string
    {
        $newline = strpos($body, "\n", $pos);

        if ($newline === false) {
            return null;
        }

        $line = substr($body, $pos, $newline - $pos);
        $pos = $newline + 1;

        if (str_ends_with($line, "\r")) {
            $line = substr($line, 0, -1);
        }

        return self::untilNul($line);
    }

    /**
     * The name and filename parameters of a Content-Disposition value
     * (the loop in rfc1867_post_handler()).
     *
     * @return array{?string, ?string}
     */
    private function disposition(string $value): array
    {
        $name = null;
        $filename = null;
        $value = ltrim($value, self::WHITESPACE);
        $length = strlen($value);
        $pos = 0;

        while ($pos < $length) {
            $pair = $this->word($value, $pos, ';');

            while ($pos < $length && ctype_space($value[$pos])) {
                $pos++;
            }

            if (! str_contains($pair, '=')) {
                continue;
            }

            $keyEnd = 0;
            $key = $this->word($pair, $keyEnd, '=');
            $rest = substr($pair, $keyEnd);

            if (strcasecmp($key, 'name') === 0) {
                $name = $this->parameterValue($rest);
            } elseif (strcasecmp($key, 'filename') === 0) {
                $filename = $this->parameterValue($rest);
            }
        }

        return [$name, $filename];
    }

    /** rfc1867.c php_ap_getword(): up to $stop, skipping over quoted runs. */
    private function word(string $subject, int &$pos, string $stop): string
    {
        $length = strlen($subject);
        $start = $pos;
        $at = $pos;

        while ($at < $length && $subject[$at] !== $stop) {
            $quote = $subject[$at];

            if ($quote === '"' || $quote === "'") {
                $at++;

                while ($at < $length && $subject[$at] !== $quote) {
                    $at += ($subject[$at] === '\\' && $at + 1 < $length && $subject[$at + 1] === $quote) ? 2 : 1;
                }

                if ($at < $length) {
                    $at++;
                }
            } else {
                $at++;
            }
        }

        if ($at >= $length) {
            $pos = $length;

            return substr($subject, $start);
        }

        $word = substr($subject, $start, $at - $start);

        while ($at < $length && $subject[$at] === $stop) {
            $at++;
        }

        $pos = $at;

        return $word;
    }

    /** rfc1867.c php_ap_getword_conf(): a quoted or bare parameter value. */
    private function parameterValue(string $value): string
    {
        $value = ltrim($value, self::WHITESPACE);

        if ($value === '') {
            return '';
        }

        if ($value[0] === '"' || $value[0] === "'") {
            return $this->unescape(substr($value, 1), $value[0]);
        }

        return $this->unescape(substr($value, 0, strcspn($value, self::WHITESPACE)), null);
    }

    /** rfc1867.c substring_conf(). */
    private function unescape(string $value, ?string $quote): string
    {
        $out = '';
        $length = strlen($value);

        for ($i = 0; $i < $length && $value[$i] !== $quote; $i++) {
            if ($value[$i] === '\\' && $i + 1 < $length
                && ($value[$i + 1] === '\\' || ($quote !== null && $value[$i + 1] === $quote))) {
                $i++;
            }

            $out .= $value[$i];
        }

        return $out;
    }

    private static function untilNul(string $value): string
    {
        $nul = strpos($value, "\0");

        return $nul === false ? $value : substr($value, 0, $nul);
    }
}
