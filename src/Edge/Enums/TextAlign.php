<?php

namespace Native\Mobile\Edge\Enums;

use Native\Mobile\Edge\Enums\Concerns\ResolvesAlignmentValue;

/**
 * Horizontal text alignment. Wire values match the native text renderers
 * (iOS `SwiftUINodeRenderer.resolveTextAlignment`, Android equivalent):
 * 0 = leading/left, 1 = center, 2 = trailing/right.
 */
enum TextAlign: int
{
    use ResolvesAlignmentValue;

    case Left = 0;

    case Center = 1;

    case Right = 2;

    public static function fromLabel(string $label): ?self
    {
        return match (strtolower(trim($label))) {
            'left', 'start', 'leading' => self::Left,
            'center', 'centre', 'middle' => self::Center,
            'right', 'end', 'trailing' => self::Right,
            default => null,
        };
    }
}
