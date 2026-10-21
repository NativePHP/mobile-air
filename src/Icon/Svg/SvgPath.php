<?php

namespace Native\Mobile\Icon\Svg;

/**
 * One drawable shape of a custom icon, with every paint property resolved.
 *
 * Paint is always the tint colour, so only its alpha is kept: `null` means
 * the fill or stroke isn't painted at all.
 */
final class SvgPath
{
    public function __construct(
        public readonly string $data,
        public readonly ?float $fillAlpha,
        public readonly ?float $strokeAlpha,
        public readonly float $strokeWidth,
        public readonly string $lineCap,
        public readonly string $lineJoin,
        public readonly float $miterLimit,
        public readonly string $fillRule,
    ) {}
}
