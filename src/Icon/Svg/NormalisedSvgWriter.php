<?php

namespace Native\Mobile\Icon\Svg;

/**
 * Writes an [SvgIcon] back out as the plain SVG an iOS asset catalog gets:
 * `<path>` elements only, every paint property explicit on each, and
 * painted colours as `#000000` (never currentColor, which CoreSVG may not
 * resolve). The template image is tinted at runtime.
 */
final class NormalisedSvgWriter
{
    public static function write(SvgIcon $icon): string
    {
        $number = PathData::number(...);

        $xml = sprintf(
            '<svg xmlns="%s" width="%s" height="%s" viewBox="%s %s %s %s">'."\n",
            SvgParser::NAMESPACE,
            $number($icon->width()),
            $number($icon->height()),
            $number($icon->minX),
            $number($icon->minY),
            $number($icon->viewportWidth),
            $number($icon->viewportHeight),
        );

        foreach ($icon->paths as $path) {
            $attributes = array_filter([
                'd' => $path->data,
                'fill' => $path->fillAlpha === null ? 'none' : '#000000',
                'fill-opacity' => $path->fillAlpha !== null && $path->fillAlpha < 1 ? $number($path->fillAlpha) : null,
                'fill-rule' => $path->fillRule,
                'stroke' => $path->strokeAlpha === null ? 'none' : '#000000',
                'stroke-opacity' => $path->strokeAlpha !== null && $path->strokeAlpha < 1 ? $number($path->strokeAlpha) : null,
                'stroke-width' => $number($path->strokeWidth),
                'stroke-linecap' => $path->lineCap,
                'stroke-linejoin' => $path->lineJoin,
                'stroke-miterlimit' => $number($path->miterLimit),
            ], fn (?string $value) => $value !== null);

            $xml .= '  <path';
            foreach ($attributes as $attribute => $value) {
                $xml .= ' '.$attribute.'="'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES).'"';
            }
            $xml .= "/>\n";
        }

        return $xml."</svg>\n";
    }
}
