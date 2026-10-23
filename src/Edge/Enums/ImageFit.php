<?php

namespace Native\Mobile\Edge\Enums;

use Native\Mobile\Edge\Enums\Concerns\ResolvesAlignmentValue;

/**
 * How an image fills its frame, after CSS `object-fit`. Wire values match
 * the native image renderers (mobile-ui's `NativeUIImageRenderer` on iOS,
 * `ImageRenderer` on Android): 0 = none, 1 = contain, 2 = cover, 3 = fill.
 */
enum ImageFit: int
{
    use ResolvesAlignmentValue;

    /** Natural size, cropped to the frame. */
    case None = 0;

    /** Scale to fit inside the frame, letterboxed. The renderers' default. */
    case Contain = 1;

    /** Scale to cover the frame, cropping the overflow. */
    case Cover = 2;

    /** Stretch to the frame's exact size. */
    case Fill = 3;

    public static function fromLabel(string $label): ?self
    {
        return match (strtolower(trim($label))) {
            'none' => self::None,
            'contain', 'fit', 'scale-down' => self::Contain,
            'cover', 'crop' => self::Cover,
            'fill', 'stretch' => self::Fill,
            default => null,
        };
    }
}
