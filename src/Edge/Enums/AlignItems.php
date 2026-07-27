<?php

namespace Native\Mobile\Edge\Enums;

use Native\Mobile\Edge\Enums\Concerns\ResolvesAlignmentValue;

/**
 * Cross-axis alignment of a flex container's children (CSS `align-items`).
 * Wire values match the native `FlexContainer` (iOS) / `ComposeFlexLayout`
 * (Android) enums: 0 = start, 1 = center, 2 = end, 3 = stretch.
 */
enum AlignItems: int
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
