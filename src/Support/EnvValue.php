<?php

namespace Native\Mobile\Support;

/**
 * Quoting for values written to .env files.
 *
 * phpdotenv mangles unquoted values two ways: everything from an unquoted
 * '#' onwards is parsed as a comment (silently truncating the value), and
 * an unquoted space is a parse error that aborts bootstrap before any
 * artisan command runs. Credential passwords are user-chosen, so both are
 * reachable — every value goes out double quoted, with the characters that
 * are special inside a double-quoted dotenv string escaped.
 */
class EnvValue
{
    public static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    /**
     * Whether the value contains characters that an unquoted .env entry or
     * an unquoted shell argument would mangle — used to warn at entry time.
     */
    public static function needsQuoting(string $value): bool
    {
        return (bool) preg_match('/[#\s"\'\\\\$!`]/', $value);
    }
}
