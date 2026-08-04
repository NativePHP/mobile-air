<?php

namespace Native\Mobile\Support;

class PathHelper
{
    /**
     * Rewrite a path to use forward slashes.
     *
     * Windows hands us a mix of separators: base_path() and SplFileInfo
     * return backslashes, while configured paths and __DIR__-based ones
     * use forward slashes. Comparing them only works once normalized.
     */
    public static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Strip the base directory from a path, if the path sits inside it.
     *
     * Both sides are normalized first, so this matches on Windows too. The
     * result always uses forward slashes; a path outside the base is
     * returned normalized but otherwise untouched.
     */
    public static function relativeTo(string $path, string $base): string
    {
        $path = static::normalize($path);
        $base = rtrim(static::normalize($base), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
