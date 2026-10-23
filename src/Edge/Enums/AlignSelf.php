<?php

namespace Native\Mobile\Edge\Enums;

use Native\Mobile\Edge\Enums\Concerns\ResolvesAlignmentValue;

/**
 * Per-child cross-axis alignment override (CSS `align-self`). Same wire
 * domain as {@see AlignItems}: 1 = center, 2 = end, 3 = stretch, 4 = start,
 * and 0 = UNSET (inherit the container's `align-items`).
 *
 * Both renderers already treated 0 as "unset" here — they resolve the
 * effective alignment with `alignSelf > 0 ? alignSelf : align`. That made
 * `self-start` a silent no-op for as long as Start was 0, since it was
 * indistinguishable from "no self-* class". Moving Start to 4 fixes that
 * for free alongside {@see AlignItems}.
 */
enum AlignSelf: int
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
