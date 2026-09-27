<?php

namespace Native\Mobile\Edge\Exceptions;

use Native\Mobile\Edge\UnknownElementHint;
use RuntimeException;

/**
 * An element tag nothing knows how to build: no builtin, no ElementRegistry
 * entry, no child component. Usually a plugin element whose plugin is not
 * installed or not registered, so the message says which package to add.
 */
class UnknownElementException extends RuntimeException
{
    /**
     * Thrown by the collector when a `<native:*>` tag reaches it at render
     * time. The message keeps the long-standing "Unknown native element
     * type: foo" prefix.
     */
    public static function forType(string $type): self
    {
        return new self(trim("Unknown native element type: {$type}. ".(UnknownElementHint::for($type) ?? '')));
    }

    /**
     * Raised at compile time for bare tags (`<outlined-text-input>`) that no
     * registered element claims. The precompiler leaves such a tag as plain
     * markup, which a native render throws away without a trace.
     *
     * @param  string[]  $tags
     */
    public static function forBareTags(array $tags, ?string $viewPath = null): self
    {
        $where = $viewPath !== null ? ' in '.self::relativePath($viewPath) : '';

        $hints = [];
        foreach ($tags as $tag) {
            $hints[$tag] = UnknownElementHint::for(str_replace('-', '_', $tag))
                ?? 'Check the spelling, or register the plugin that provides it in app/Providers/NativeServiceProvider.php.';
        }

        $names = implode(', ', array_map(fn ($tag) => "<{$tag}>", $tags));

        if (count($tags) === 1) {
            return new self("Unknown native element {$names}{$where}. It would not be rendered. ".reset($hints));
        }

        $message = "Unknown native elements {$names}{$where}. They would not be rendered.";

        if (count(array_unique($hints)) === 1) {
            return new self($message.' '.preg_replace('/^It comes from /', 'They come from ', reset($hints)));
        }

        foreach ($hints as $tag => $hint) {
            $message .= "\n<{$tag}>: {$hint}";
        }

        return new self($message);
    }

    private static function relativePath(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
