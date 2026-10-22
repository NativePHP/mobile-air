<?php

namespace Native\Mobile\Icon\Svg;

/**
 * A custom icon parsed down to paths in its own viewBox.
 */
final class SvgIcon
{
    /** Intrinsic size of the icon's larger side, in dp / pt. */
    public const SIZE = 24;

    /**
     * @param  list<SvgPath>  $paths
     */
    public function __construct(
        public readonly float $minX,
        public readonly float $minY,
        public readonly float $viewportWidth,
        public readonly float $viewportHeight,
        public readonly array $paths,
    ) {}

    /**
     * Sized from the viewBox alone, so a `0 0 960 960` Material SVG and a
     * `0 0 24 24` Lucide one come out the same size.
     */
    public function width(): float
    {
        return self::SIZE * $this->viewportWidth / max($this->viewportWidth, $this->viewportHeight);
    }

    public function height(): float
    {
        return self::SIZE * $this->viewportHeight / max($this->viewportWidth, $this->viewportHeight);
    }
}
