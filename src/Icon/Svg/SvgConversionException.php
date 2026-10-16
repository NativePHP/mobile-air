<?php

namespace Native\Mobile\Icon\Svg;

use RuntimeException;

/**
 * A custom icon SVG the build can't turn into native assets.
 *
 * Every message starts with the file and, where there is one, names the
 * element and attribute at fault, so the build output alone is enough to
 * find and fix the problem.
 */
class SvgConversionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $iconFile,
        public readonly ?string $element = null,
        public readonly ?string $attribute = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidName(string $file): self
    {
        return new self(
            "{$file}: custom icon file names must be lowercase letters, numbers and single hyphens, e.g. git-commit-vertical.svg.",
            $file,
        );
    }

    public static function invalidDocument(string $file, string $reason): self
    {
        return new self("{$file}: {$reason}", $file);
    }

    public static function unsupportedElement(string $file, string $element, string $reason = ''): self
    {
        $reason = $reason !== '' ? $reason : 'only svg, g, path, circle, ellipse, line, rect, polyline and polygon can be converted';

        return new self("{$file}: <{$element}> is not supported in custom icons ({$reason}).", $file, $element);
    }

    public static function unsupportedAttribute(string $file, string $element, string $attribute, string $reason): self
    {
        return new self("{$file}: attribute \"{$attribute}\" on <{$element}> is not supported in custom icons ({$reason}).", $file, $element, $attribute);
    }

    public static function invalidAttribute(string $file, string $element, string $attribute, string $reason): self
    {
        return new self("{$file}: invalid \"{$attribute}\" on <{$element}>: {$reason}.", $file, $element, $attribute);
    }

    /**
     * @param  list<string>  $colours
     */
    public static function multipleColours(string $file, array $colours): self
    {
        return new self(
            "{$file}: custom icons are tinted as one colour, but this one paints with ".implode(', ', $colours).'. Use a single colour (or currentColor) throughout.',
            $file,
        );
    }
}
