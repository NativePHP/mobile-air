<?php

namespace Native\Mobile\Plugins;

use Illuminate\Filesystem\Filesystem;

/**
 * Maps a plugin renderer class (from nativephp.json) to the native source
 * file that defines it, so compilers can omit registrations for renderers
 * whose files were not copied.
 *
 * When a plugin opts into manages_native_sources, the hook returns a subset
 * of files but the manifest still lists every renderer. Registering a class
 * that was never copied breaks the native build.
 *
 * If a renderer cannot be mapped to any file, callers keep the registration
 * (and the file, if the hook already selected it). Dropping an unmapped
 * renderer would turn a parser miss into a silent missing-symbol failure.
 */
class NativeSourceTypeMap
{
    /**
     * Relative path of the source file that defines $renderer, or null
     * when the type cannot be found under $sourceRoot.
     */
    public static function fileFor(
        string $sourceRoot,
        string $renderer,
        string $platform,
        Filesystem $files
    ): ?string {
        if ($renderer === '' || ! $files->isDirectory($sourceRoot)) {
            return null;
        }

        $index = static::index($sourceRoot, $platform, $files);
        $simple = static::simpleName($renderer);

        if (isset($index[$renderer])) {
            return $index[$renderer];
        }

        if ($simple !== $renderer && isset($index[$simple])) {
            return $index[$simple];
        }

        // Filename matching is not a type mapping. An unmapped renderer is
        // kept (registration + file) rather than dropped on a guess.
        return null;
    }

    /**
     * Whether a renderer should be emitted into the generated registration.
     *
     * $selectedRelativePaths is the subset the hook (or ios.sources) actually
     * copied. Null means every source was copied, so every renderer stays.
     *
     * @param  list<string>|null  $selectedRelativePaths
     */
    public static function shouldRegister(
        string $renderer,
        string $sourceRoot,
        ?array $selectedRelativePaths,
        string $platform,
        Filesystem $files
    ): bool {
        if ($selectedRelativePaths === null) {
            return true;
        }

        $file = static::fileFor($sourceRoot, $renderer, $platform, $files);

        // Unmapped: keep the registration. The defining file is unknown, so
        // dropping it would be a guess, and the copy pipeline has already
        // decided which files made it into the app.
        if ($file === null) {
            return true;
        }

        return static::isSelected($file, $selectedRelativePaths);
    }

    /**
     * Files to copy for renderers that have no type mapping, so an unmapped
     * class still has a source in the app. Only files named after the class
     * that were not already selected are returned.
     *
     * @param  list<string>  $renderers
     * @param  list<string>  $selectedRelativePaths
     * @return list<string>
     */
    public static function filesToKeepForUnmapped(
        array $renderers,
        string $sourceRoot,
        array $selectedRelativePaths,
        string $platform,
        Filesystem $files
    ): array {
        $extra = [];

        foreach ($renderers as $renderer) {
            if (! is_string($renderer) || $renderer === '') {
                continue;
            }

            if (static::fileFor($sourceRoot, $renderer, $platform, $files) !== null) {
                continue;
            }

            $named = static::fileNamedFor(
                $sourceRoot,
                static::simpleName($renderer),
                $platform,
                $files
            );

            if ($named === null || static::isSelected($named, $selectedRelativePaths)) {
                continue;
            }

            $extra[] = $named;
        }

        return array_values(array_unique($extra));
    }

    /**
     * @param  list<string>  $selectedRelativePaths
     */
    public static function isSelected(string $relative, array $selectedRelativePaths): bool
    {
        $relative = static::normalizeRelative($relative);

        foreach ($selectedRelativePaths as $path) {
            $path = static::normalizeRelative((string) $path);

            if ($path === '' || $relative === '') {
                continue;
            }

            if ($relative === $path || str_starts_with($relative, $path.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identifier => relative path, built by scanning type declarations.
     *
     * Kotlin entries are stored as both the FQN (`package.Type`) and the
     * simple name. Swift entries are the type name as it appears in source.
     *
     * @return array<string, string>
     */
    public static function index(string $sourceRoot, string $platform, Filesystem $files): array
    {
        if (! $files->isDirectory($sourceRoot)) {
            return [];
        }

        $index = [];
        $extension = $platform === 'android' ? 'kt' : 'swift';

        foreach (static::sourceFiles($sourceRoot, $extension, $files) as $relative) {
            $content = $files->get($sourceRoot.'/'.$relative);
            $names = $platform === 'android'
                ? static::kotlinTypeNames($content)
                : static::swiftTypeNames($content);

            foreach ($names as $name) {
                $index[$name] ??= $relative;
            }
        }

        return $index;
    }

    /**
     * @return list<string>
     */
    protected static function sourceFiles(string $sourceRoot, string $extension, Filesystem $files): array
    {
        if ($extension === 'swift') {
            return SwiftSourceFilter::collect($sourceRoot);
        }

        $relative = [];

        foreach ($files->allFiles($sourceRoot) as $file) {
            if ($file->getExtension() !== $extension) {
                continue;
            }

            $path = $file->getPathname();
            $relative[] = static::normalizeRelative(substr($path, strlen(rtrim($sourceRoot, '/'))));
        }

        sort($relative);

        return $relative;
    }

    /**
     * @return list<string>
     */
    protected static function kotlinTypeNames(string $content): array
    {
        $package = null;

        if (preg_match('/^package\s+([\w.]+)/m', $content, $matches)) {
            $package = $matches[1];
        }

        if (! preg_match_all(
            '/^(?:(?:public|internal|private|protected|open|abstract|data|sealed|inner|value|annotation|enum|fun)\s+)*(?:class|object|interface)\s+(\w+)/m',
            $content,
            $matches
        )) {
            return [];
        }

        $names = [];

        foreach ($matches[1] as $simple) {
            $names[] = $simple;

            if ($package !== null) {
                $names[] = $package.'.'.$simple;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    protected static function swiftTypeNames(string $content): array
    {
        if (! preg_match_all(
            '/^(?:(?:public|internal|private|fileprivate|open|final)\s+)*(?:struct|class|enum|actor)\s+(\w+)/m',
            $content,
            $matches
        )) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    public static function simpleName(string $renderer): string
    {
        $parts = explode('.', $renderer);

        return (string) end($parts);
    }

    /**
     * A file whose basename is the renderer class, used to keep an unmapped
     * renderer's source in the copy set. Not used to drop registrations.
     */
    public static function fileNamedFor(
        string $sourceRoot,
        string $simple,
        string $platform,
        Filesystem $files
    ): ?string {
        if ($simple === '') {
            return null;
        }

        $extension = $platform === 'android' ? 'kt' : 'swift';
        $needle = $simple.'.'.$extension;

        foreach (static::sourceFiles($sourceRoot, $extension, $files) as $relative) {
            if (basename($relative) === $needle) {
                return $relative;
            }
        }

        return null;
    }

    protected static function normalizeRelative(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
