<?php

namespace Native\Mobile\Edge\Enums;

use Native\Mobile\Edge\Enums\Concerns\ResolvesAlignmentValue;

/**
 * Main-axis distribution of a flex container's children (CSS
 * `justify-content`). Wire values match the native `FlexContainer` (iOS) /
 * `ComposeFlexLayout` (Android) enums: 0 = start, 1 = center, 2 = end,
 * 3 = space-between, 4 = space-around, 5 = space-evenly.
 */
enum JustifyContent: int
{
    use ResolvesAlignmentValue;

    case Start = 0;

    case Center = 1;

    case End = 2;

    case SpaceBetween = 3;

    case SpaceAround = 4;

    case SpaceEvenly = 5;

    public static function fromLabel(string $label): ?self
    {
        return match (strtolower(trim($label))) {
            'start', 'flex-start' => self::Start,
            'center', 'centre', 'middle' => self::Center,
            'end', 'flex-end' => self::End,
            'between', 'space-between', 'spacebetween' => self::SpaceBetween,
            'around', 'space-around', 'spacearound' => self::SpaceAround,
            'evenly', 'space-evenly', 'spaceevenly' => self::SpaceEvenly,
            default => null,
        };
    }
}
