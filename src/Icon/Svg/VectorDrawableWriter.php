<?php

namespace Native\Mobile\Icon\Svg;

/**
 * Writes an [SvgIcon] as an Android VectorDrawable.
 *
 * Every paint property is written out, since VectorDrawable's defaults
 * aren't SVG's (no fill, for one). Painted fills and strokes are opaque
 * black with their alpha alongside; the renderer tints them.
 */
final class VectorDrawableWriter
{
    private const PAINTED = '#FF000000';

    private const UNPAINTED = '#00000000';

    public static function write(SvgIcon $icon): string
    {
        $number = PathData::number(...);

        $xml = '<?xml version="1.0" encoding="utf-8"?>'."\n"
            .self::open('vector xmlns:android="http://schemas.android.com/apk/res/android"', [
                'android:width' => $number($icon->width()).'dp',
                'android:height' => $number($icon->height()).'dp',
                'android:viewportWidth' => $number($icon->viewportWidth),
                'android:viewportHeight' => $number($icon->viewportHeight),
            ], '');

        // VectorDrawable viewports always start at 0,0, so a viewBox that
        // doesn't (Material's `0 -960 960 960`) is shifted into place.
        $translate = array_filter([
            'android:translateX' => $icon->minX != 0 ? $number(-$icon->minX) : null,
            'android:translateY' => $icon->minY != 0 ? $number(-$icon->minY) : null,
        ]);

        $indent = '    ';

        if ($translate !== []) {
            $xml .= self::open('group', $translate, $indent);
            $indent .= '    ';
        }

        foreach ($icon->paths as $path) {
            $xml .= self::open('path', self::pathAttributes($path), $indent, selfClosing: true);
        }

        if ($translate !== []) {
            $xml .= "    </group>\n";
        }

        return $xml."</vector>\n";
    }

    /**
     * @return array<string, string>
     */
    private static function pathAttributes(SvgPath $path): array
    {
        $number = PathData::number(...);

        return array_filter([
            'android:pathData' => $path->data,
            'android:fillColor' => $path->fillAlpha === null ? self::UNPAINTED : self::PAINTED,
            'android:fillAlpha' => $path->fillAlpha !== null && $path->fillAlpha < 1 ? $number($path->fillAlpha) : null,
            'android:fillType' => $path->fillRule === 'evenodd' ? 'evenOdd' : 'nonZero',
            'android:strokeColor' => $path->strokeAlpha === null ? self::UNPAINTED : self::PAINTED,
            'android:strokeAlpha' => $path->strokeAlpha !== null && $path->strokeAlpha < 1 ? $number($path->strokeAlpha) : null,
            'android:strokeWidth' => $number($path->strokeWidth),
            'android:strokeLineCap' => $path->lineCap,
            'android:strokeLineJoin' => $path->lineJoin,
            'android:strokeMiterLimit' => $number($path->miterLimit),
        ], fn (?string $value) => $value !== null);
    }

    /**
     * One attribute per line, Android Studio style.
     *
     * @param  array<string, string>  $attributes
     */
    private static function open(string $name, array $attributes, string $indent, bool $selfClosing = false): string
    {
        $lines = [$indent.'<'.$name];

        foreach ($attributes as $attribute => $value) {
            $lines[] = $indent.'    '.$attribute.'="'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES).'"';
        }

        return implode("\n", $lines).($selfClosing ? ' />' : '>')."\n";
    }
}
