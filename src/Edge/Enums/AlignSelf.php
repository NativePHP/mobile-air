<?php

namespace Native\Mobile\Edge\Enums;

use Native\Mobile\Edge\Enums\Concerns\ResolvesAlignmentValue;

/**
 * Per-child cross-axis alignment override (CSS `align-self`). Same wire
 * domain as {@see AlignItems}: 0 = start, 1 = center, 2 = end, 3 = stretch.
 */
enum AlignSelf: int
{
    use ResolvesAlignmentValue;

    case Start = 0;

    case Center = 1;

    case End = 2;

    case Stretch = 3;

    public static function fromLabel(string $label): ?self
    {
        return match (strtolower(trim($label))) {
            'start', 'flex-start' => self::Start,
            'center', 'centre', 'middle' => self::Center,
            'end', 'flex-end' => self::End,
            'stretch', 'fill' => self::Stretch,
            default => null,
        };
    }
}
