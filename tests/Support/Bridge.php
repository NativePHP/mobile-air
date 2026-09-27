<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Builders for raw request bodies and a strict reader for raw HTTP responses,
 * shared by the bridge tests.
 */
final class Bridge
{
    public const BOUNDARY = '----NativeBridgeBoundary7MA4YWxkTrZu0gW';

    /** Every byte value from 0x00 to 0xFF, once. */
    public static function allBytes(): string
    {
        return implode('', array_map('chr', range(0, 255)));
    }

    public static function field(string $name, string $value): string
    {
        return "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}";
    }

    public static function file(string $name, string $filename, string $content, ?string $type = 'application/octet-stream'): string
    {
        $headers = "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n";

        if ($type !== null) {
            $headers .= "Content-Type: {$type}\r\n";
        }

        return $headers."\r\n".$content;
    }

    /**
     * A multipart/form-data body. Each part is its headers, a blank line and
     * its content, as field() and file() build them.
     *
     * @param  list<string>  $parts
     */
    public static function multipart(array $parts, bool $close = true, string $boundary = self::BOUNDARY): string
    {
        $body = '';

        foreach ($parts as $part) {
            $body .= "--{$boundary}\r\n{$part}\r\n";
        }

        return $close ? $body."--{$boundary}--\r\n" : $body;
    }

    public static function contentType(string $boundary = self::BOUNDARY): string
    {
        return "multipart/form-data; boundary={$boundary}";
    }

    /**
     * Split a raw HTTP response at its first blank line and check its framing:
     * a status line, well-formed header lines, and a content-length equal to
     * the number of body bytes.
     *
     * @return array{status: int, reason: string, headers: array<string, list<string>>, body: string}
     */
    public static function parse(string $raw): array
    {
        $split = strpos($raw, "\r\n\r\n");

        if ($split === false) {
            throw new RuntimeException('Response has no blank line: '.substr($raw, 0, 200));
        }

        $lines = explode("\r\n", substr($raw, 0, $split));
        $body = substr($raw, $split + 4);

        if (preg_match('#^HTTP/1\.1 (\d{3}) (.*)$#', array_shift($lines), $status) !== 1) {
            throw new RuntimeException('Bad status line in: '.substr($raw, 0, 200));
        }

        $headers = [];

        foreach ($lines as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                throw new RuntimeException("Bad header line [{$line}]");
            }

            $headers[strtolower(substr($line, 0, $colon))][] = ltrim(substr($line, $colon + 1));
        }

        if (($headers['content-length'] ?? null) !== [(string) strlen($body)]) {
            throw new RuntimeException(sprintf(
                'content-length %s does not match the %d body bytes',
                json_encode($headers['content-length'] ?? null),
                strlen($body),
            ));
        }

        return ['status' => (int) $status[1], 'reason' => $status[2], 'headers' => $headers, 'body' => $body];
    }
}
