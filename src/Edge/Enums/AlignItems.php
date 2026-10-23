<?php

namespace Native\Mobile\Edge\Enums;

use Native\Mobile\Edge\Enums\Concerns\ResolvesAlignmentValue;

/**
 * Cross-axis alignment of a flex container's children (CSS `align-items`).
 * Wire values match the native `FlexContainer` (iOS) / `ComposeFlexLayout`
 * (Android) enums: 1 = center, 2 = end, 3 = stretch, 4 = start.
 *
 * **0 is deliberately not a case — it means UNSET.** The layout array only
 * carries `align_items` when the author actually asked for an alignment, so
 * a node with no `items-*` class arrives as 0 and each renderer applies its
 * own default. Start therefore cannot be 0: if it were, "no items-* class"
 * and an explicit `items-start` would be the same byte on the wire, and the
 * renderers could not honour one without also changing the other (mobile-air
 * #309 — swapping iOS's transposed branches also flipped every unclassed
 * container from fill to hug).
 *
 * `parse()` validates integers against the cases, so `align-items="0"` now
 * resolves to null and leaves the native default in place — which is what
 * "unset" should do anyway.
 */
enum AlignItems: int
{
    use ResolvesAlignmentValue;

    case Start = 4;

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
