<?php

namespace Native\Mobile\Icon\Svg;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Parses a custom icon SVG into an [SvgIcon]: plain paths, every shape
 * converted to path data and every presentation property resolved down
 * the svg → g → shape chain (attributes and `style=""` alike).
 *
 * Deliberately strict. Anything that would draw differently once flattened
 * to plain paths (transforms, clipping, masks, gradients, text, references,
 * stylesheets) fails with a [SvgConversionException] naming the file,
 * element and attribute, rather than producing an icon that quietly looks
 * wrong on one platform.
 *
 * Icons are single-colour templates: every painted colour becomes the tint,
 * so only alpha survives. Two different painted colours (currentColor
 * aside) fail, since one of them would be lost.
 */
final class SvgParser
{
    public const NAMESPACE = 'http://www.w3.org/2000/svg';

    private const SHAPES = ['path', 'circle', 'ellipse', 'line', 'rect', 'polyline', 'polygon'];

    private const DESCRIPTIVE = ['title', 'desc'];

    private const PRESENTATION = [
        'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'fill-rule', 'opacity', 'fill-opacity', 'stroke-opacity',
    ];

    /**
     * Attributes that change what's drawn in ways plain paths can't carry,
     * mapped to the values that are a no-op and so still fine.
     */
    private const UNSUPPORTED = [
        'transform' => [],
        'clip-path' => ['none'],
        'mask' => ['none'],
        'filter' => ['none'],
        'marker' => ['none'],
        'marker-start' => ['none'],
        'marker-mid' => ['none'],
        'marker-end' => ['none'],
        'stroke-dasharray' => ['none'],
        'stroke-dashoffset' => ['0'],
        'display' => ['inline'],
        'visibility' => ['visible'],
        'vector-effect' => ['none'],
        'paint-order' => ['normal'],
    ];

    /** SVG's own defaults, which differ from VectorDrawable's (no fill there). */
    private const DEFAULTS = [
        'fill' => ['colour' => '#000000', 'alpha' => 1.0],
        'stroke' => null,
        'stroke-width' => 1.0,
        'stroke-linecap' => 'butt',
        'stroke-linejoin' => 'miter',
        'stroke-miterlimit' => 4.0,
        'fill-rule' => 'nonzero',
        'fill-opacity' => 1.0,
        'stroke-opacity' => 1.0,
        'opacity' => 1.0,
    ];

    private const NUMBER = '[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?';

    private ?string $namespace = null;

    /** @var list<SvgPath> */
    private array $paths = [];

    /** @var array<string, string> Painted colours other than currentColor. */
    private array $colours = [];

    private function __construct(private readonly string $file) {}

    /**
     * @param  string  $file  Shown in error messages, e.g. `resources/icons/bell.svg`.
     *
     * @throws SvgConversionException
     */
    public static function parse(string $svg, string $file): SvgIcon
    {
        return (new self($file))->document($svg);
    }

    private function document(string $svg): SvgIcon
    {
        if (trim($svg) === '') {
            throw SvgConversionException::invalidDocument($this->file, 'the file is empty');
        }

        // Entities are never expanded, and external ones never fetched (no
        // LIBXML_NOENT / LIBXML_DTDLOAD); refusing declarations outright also
        // rules out entity-expansion bombs.
        if (preg_match('/<!ENTITY/i', $svg)) {
            throw SvgConversionException::invalidDocument($this->file, 'entity declarations are not allowed in custom icons');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($svg, LIBXML_NONET);
            $errors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            $reason = isset($errors[0]) ? trim($errors[0]->message).' on line '.$errors[0]->line : 'unknown error';

            throw SvgConversionException::invalidDocument($this->file, "not well-formed XML ({$reason})");
        }

        $root = $document->documentElement;

        if ($root === null || $root->localName !== 'svg' || ! in_array($root->namespaceURI, [null, self::NAMESPACE], true)) {
            throw SvgConversionException::invalidDocument($this->file, 'the root element must be <svg>');
        }

        $this->namespace = $root->namespaceURI;

        [$minX, $minY, $width, $height] = $this->viewBox($root);

        $this->children($root, $this->style($root, self::DEFAULTS));

        if (count($this->colours) > 1) {
            throw SvgConversionException::multipleColours($this->file, array_values($this->colours));
        }

        return new SvgIcon($minX, $minY, $width, $height, $this->paths);
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function viewBox(DOMElement $root): array
    {
        if (! $root->hasAttribute('viewBox')) {
            $width = $this->intrinsicLength($root->getAttribute('width'));
            $height = $this->intrinsicLength($root->getAttribute('height'));

            if ($width === null || $height === null) {
                throw SvgConversionException::invalidAttribute($this->file, 'svg', 'viewBox', 'it is missing, and there is no numeric width and height to derive it from');
            }

            return [0.0, 0.0, $width, $height];
        }

        try {
            $numbers = PathData::numbers($root->getAttribute('viewBox'));
        } catch (InvalidArgumentException $e) {
            throw SvgConversionException::invalidAttribute($this->file, 'svg', 'viewBox', $e->getMessage());
        }

        if (count($numbers) !== 4) {
            throw SvgConversionException::invalidAttribute($this->file, 'svg', 'viewBox', 'expected four numbers (min-x min-y width height)');
        }

        if ($numbers[2] <= 0 || $numbers[3] <= 0) {
            throw SvgConversionException::invalidAttribute($this->file, 'svg', 'viewBox', 'its width and height must be greater than zero');
        }

        return [$numbers[0], $numbers[1], $numbers[2], $numbers[3]];
    }

    private function intrinsicLength(string $value): ?float
    {
        $length = $this->parseLength($value);

        return $length !== null && $length > 0 ? $length : null;
    }

    /**
     * @param  array<string, mixed>  $style
     */
    private function children(DOMElement $parent, array $style): void
    {
        foreach ($parent->childNodes as $node) {
            // Text, comments, CDATA and processing instructions draw nothing.
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->namespaceURI !== $this->namespace) {
                throw SvgConversionException::unsupportedElement($this->file, $node->nodeName, 'only SVG elements can be converted');
            }

            $name = $node->localName;

            if (in_array($name, self::DESCRIPTIVE, true)) {
                continue;
            }

            if ($name === 'defs') {
                if ($this->hasElementChildren($node)) {
                    throw SvgConversionException::unsupportedElement($this->file, 'defs', 'only an empty <defs> is allowed, since definitions are only used by references, gradients, clip paths and masks');
                }

                continue;
            }

            if ($name === 'g') {
                $this->children($node, $this->style($node, $style));

                continue;
            }

            if (in_array($name, self::SHAPES, true)) {
                $this->shape($node, $this->style($node, $style));

                continue;
            }

            throw SvgConversionException::unsupportedElement($this->file, $name, match ($name) {
                'use', 'symbol' => 'references can\'t be converted; expand them into plain shapes first',
                'linearGradient', 'radialGradient', 'pattern' => 'custom icons are tinted as one colour',
                'clipPath', 'mask' => 'clipping and masking can\'t be converted; flatten the icon into plain shapes first',
                'text', 'tspan', 'textPath' => 'convert text to outlines first',
                'image', 'foreignObject' => 'custom icons must be vector shapes',
                'style' => 'stylesheets aren\'t supported; use presentation attributes or style="" instead',
                'svg' => 'nested <svg> elements can\'t be converted',
                default => '',
            });
        }
    }

    private function hasElementChildren(DOMElement $element): bool
    {
        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMElement) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve an element's presentation properties on top of its parent's.
     * `style=""` declarations beat attributes, as in CSS. `opacity` isn't
     * inherited but multiplies down, since it ends up in each path's alpha.
     *
     * @param  array<string, mixed>  $inherited
     * @return array<string, mixed>
     */
    private function style(DOMElement $element, array $inherited): array
    {
        $name = $element->localName;
        $own = [];

        foreach ($element->attributes as $attribute) {
            // xml:space, xlink:href, editor metadata and the like.
            if ($attribute->namespaceURI !== null) {
                continue;
            }

            $value = trim($attribute->value);

            if (array_key_exists($attribute->name, self::UNSUPPORTED)) {
                if (! in_array(strtolower($value), self::UNSUPPORTED[$attribute->name], true)) {
                    throw SvgConversionException::unsupportedAttribute($this->file, $name, $attribute->name, match ($attribute->name) {
                        'transform' => 'flatten transforms into the path data first',
                        'stroke-dasharray', 'stroke-dashoffset' => 'dashed strokes can\'t be drawn on Android',
                        default => 'it can\'t be represented once the icon is flattened to plain paths',
                    });
                }

                continue;
            }

            if (in_array($attribute->name, self::PRESENTATION, true)) {
                $own[$attribute->name] = [$value, $attribute->name];
            }
        }

        if ($element->hasAttribute('style')) {
            foreach ($this->declarations($name, $element->getAttribute('style')) as $property => $value) {
                $own[$property] = [$value, 'style'];
            }
        }

        $style = $inherited;

        foreach ($own as $property => [$value, $source]) {
            $style = $this->apply($style, $name, $source, $property, $value);
        }

        return $style;
    }

    /**
     * @return array<string, string>
     */
    private function declarations(string $element, string $css): array
    {
        $declarations = [];

        foreach (explode(';', preg_replace('#/\*.*?\*/#s', '', $css)) as $declaration) {
            if (trim($declaration) === '') {
                continue;
            }

            $parts = explode(':', $declaration, 2);

            if (count($parts) !== 2) {
                throw SvgConversionException::invalidAttribute($this->file, $element, 'style', 'can\'t read the declaration "'.trim($declaration).'"');
            }

            $property = strtolower(trim($parts[0]));

            if (! in_array($property, self::PRESENTATION, true)) {
                throw SvgConversionException::invalidAttribute($this->file, $element, 'style', "\"{$property}\" isn't supported in custom icons; only ".implode(', ', self::PRESENTATION).' can be set there');
            }

            $declarations[$property] = trim(preg_replace('/!\s*important\s*$/i', '', $parts[1]));
        }

        return $declarations;
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    private function apply(array $style, string $element, string $source, string $property, string $value): array
    {
        $keyword = strtolower($value);

        if ($keyword === 'inherit') {
            return $style;
        }

        $invalid = fn (string $expected) => SvgConversionException::invalidAttribute(
            $this->file, $element, $source, "{$property} \"{$value}\" isn't {$expected}"
        );

        switch ($property) {
            case 'fill':
            case 'stroke':
                $style[$property] = $this->paint($element, $source, $property, $value);
                break;

            case 'stroke-width':
                $width = $this->parseLength($value);
                if ($width === null || $width < 0) {
                    throw $invalid('a non-negative number (units other than px can\'t be converted)');
                }
                $style[$property] = $width;
                break;

            case 'stroke-linecap':
                if (! in_array($keyword, ['butt', 'round', 'square'], true)) {
                    throw $invalid('one of butt, round or square');
                }
                $style[$property] = $keyword;
                break;

            case 'stroke-linejoin':
                if (! in_array($keyword, ['miter', 'round', 'bevel'], true)) {
                    throw $invalid('one of miter, round or bevel');
                }
                $style[$property] = $keyword;
                break;

            case 'stroke-miterlimit':
                $limit = $this->parseNumber($value);
                if ($limit === null || $limit < 1) {
                    throw $invalid('a number of at least 1');
                }
                $style[$property] = $limit;
                break;

            case 'fill-rule':
                if (! in_array($keyword, ['nonzero', 'evenodd'], true)) {
                    throw $invalid('nonzero or evenodd');
                }
                $style[$property] = $keyword;
                break;

            case 'opacity':
            case 'fill-opacity':
            case 'stroke-opacity':
                $alpha = $this->parseAlpha($value);
                if ($alpha === null) {
                    throw $invalid('a number or percentage');
                }
                $style[$property] = $property === 'opacity' ? $style['opacity'] * $alpha : $alpha;
                break;
        }

        return $style;
    }

    /**
     * @return array{colour: ?string, alpha: float}|null `colour` is null for currentColor; null paint is `none`.
     */
    private function paint(string $element, string $source, string $property, string $value): ?array
    {
        $keyword = strtolower($value);

        if ($keyword === 'none') {
            return null;
        }

        if ($keyword === 'currentcolor') {
            return ['colour' => null, 'alpha' => 1.0];
        }

        if (str_starts_with($keyword, 'url(')) {
            throw SvgConversionException::unsupportedAttribute($this->file, $element, $source, "{$property} uses a gradient or pattern; custom icons are tinted as one colour");
        }

        return $this->colour($keyword) ?? throw SvgConversionException::invalidAttribute(
            $this->file, $element, $source,
            "{$property} \"{$value}\" isn't a supported colour (use currentColor, none, a hex colour, black, white or rgb())"
        );
    }

    /**
     * @return array{colour: string, alpha: float}|null
     */
    private function colour(string $value): ?array
    {
        if ($value === 'black') {
            return ['colour' => '#000000', 'alpha' => 1.0];
        }

        if ($value === 'white') {
            return ['colour' => '#ffffff', 'alpha' => 1.0];
        }

        if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value, $match)) {
            $hex = $match[1];
            if (strlen($hex) <= 4) {
                $hex = preg_replace('/(.)/', '$1$1', $hex);
            }

            return [
                'colour' => '#'.substr($hex, 0, 6),
                'alpha' => strlen($hex) === 8 ? hexdec(substr($hex, 6, 2)) / 255 : 1.0,
            ];
        }

        if (preg_match('/^rgba?\(\s*(.*?)\s*\)$/', $value, $match)) {
            $channels = $match[1];
            $alpha = null;

            if (str_contains($channels, '/')) {
                [$channels, $alpha] = array_map('trim', explode('/', $channels, 2));
            }

            $parts = preg_split('/\s*,\s*|\s+/', $channels);

            if ($alpha === null && count($parts) === 4) {
                $alpha = array_pop($parts);
            }

            if (count($parts) !== 3) {
                return null;
            }

            $rgb = [];
            foreach ($parts as $part) {
                if (str_ends_with($part, '%')) {
                    $number = $this->parseNumber(substr($part, 0, -1));
                    $number = $number === null ? null : $number * 2.55;
                } else {
                    $number = $this->parseNumber($part);
                }

                if ($number === null) {
                    return null;
                }

                $rgb[] = (int) round(min(255, max(0, $number)));
            }

            $alpha = $alpha === null ? 1.0 : $this->parseAlpha($alpha);

            return $alpha === null ? null : ['colour' => vsprintf('#%02x%02x%02x', $rgb), 'alpha' => $alpha];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $style
     */
    private function shape(DOMElement $element, array $style): void
    {
        $name = $element->localName;

        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMElement && ! in_array($node->localName, self::DESCRIPTIVE, true)) {
                throw SvgConversionException::unsupportedElement($this->file, $node->nodeName, "only <title> and <desc> may sit inside <{$name}>");
            }
        }

        $segments = match ($name) {
            'path' => $this->path($element),
            'circle' => $this->circle($element),
            'ellipse' => $this->ellipse($element),
            'line' => [['M', [$this->length($element, 'x1'), $this->length($element, 'y1')]], ['L', [$this->length($element, 'x2'), $this->length($element, 'y2')]]],
            'rect' => $this->rect($element),
            'polyline' => $this->poly($element, false),
            'polygon' => $this->poly($element, true),
            default => [],
        };

        // Zero-size shapes and empty path data: SVG draws nothing for them.
        if ($segments === []) {
            return;
        }

        // A line encloses no area, so its fill never paints.
        $fill = $name === 'line' ? null : $style['fill'];
        $stroke = $style['stroke-width'] > 0 ? $style['stroke'] : null;

        $fillAlpha = $fill === null ? null : $fill['alpha'] * $style['fill-opacity'] * $style['opacity'];
        $strokeAlpha = $stroke === null ? null : $stroke['alpha'] * $style['stroke-opacity'] * $style['opacity'];

        $fillAlpha = $fillAlpha > 0 ? $fillAlpha : null;
        $strokeAlpha = $strokeAlpha > 0 ? $strokeAlpha : null;

        if ($fillAlpha === null && $strokeAlpha === null) {
            return;
        }

        foreach ([[$fill, $fillAlpha], [$stroke, $strokeAlpha]] as [$paint, $alpha]) {
            if ($alpha !== null && $paint['colour'] !== null) {
                $this->colours[$paint['colour']] = $paint['colour'];
            }
        }

        $this->paths[] = new SvgPath(
            data: PathData::format($segments),
            fillAlpha: $fillAlpha,
            strokeAlpha: $strokeAlpha,
            strokeWidth: $strokeAlpha === null ? 0.0 : $style['stroke-width'],
            lineCap: $style['stroke-linecap'],
            lineJoin: $style['stroke-linejoin'],
            miterLimit: $style['stroke-miterlimit'],
            fillRule: $style['fill-rule'],
        );
    }

    /**
     * @return list<array{0: string, 1: list<float>}>
     */
    private function path(DOMElement $element): array
    {
        try {
            return PathData::parse($element->getAttribute('d'));
        } catch (InvalidArgumentException $e) {
            throw SvgConversionException::invalidAttribute($this->file, 'path', 'd', $e->getMessage());
        }
    }

    /**
     * Two arcs from the rightmost point, clockwise, as SVG itself draws it.
     *
     * @return list<array{0: string, 1: list<float>}>
     */
    private function circle(DOMElement $element): array
    {
        $r = $this->size($element, 'r');

        return $r > 0 ? $this->ellipseSegments($this->length($element, 'cx'), $this->length($element, 'cy'), $r, $r) : [];
    }

    /**
     * @return list<array{0: string, 1: list<float>}>
     */
    private function ellipse(DOMElement $element): array
    {
        [$rx, $ry] = $this->radii($element);

        return $rx > 0 && $ry > 0 ? $this->ellipseSegments($this->length($element, 'cx'), $this->length($element, 'cy'), $rx, $ry) : [];
    }

    /**
     * @return list<array{0: string, 1: list<float>}>
     */
    private function ellipseSegments(float $cx, float $cy, float $rx, float $ry): array
    {
        return [
            ['M', [$cx + $rx, $cy]],
            ['A', [$rx, $ry, 0, 0, 1, $cx - $rx, $cy]],
            ['A', [$rx, $ry, 0, 0, 1, $cx + $rx, $cy]],
            ['Z', []],
        ];
    }

    /**
     * @return list<array{0: string, 1: list<float>}>
     */
    private function rect(DOMElement $element): array
    {
        $x = $this->length($element, 'x');
        $y = $this->length($element, 'y');
        $width = $this->size($element, 'width');
        $height = $this->size($element, 'height');

        if ($width <= 0 || $height <= 0) {
            return [];
        }

        [$rx, $ry] = $this->radii($element);
        $rx = min($rx, $width / 2);
        $ry = min($ry, $height / 2);

        if ($rx <= 0 || $ry <= 0) {
            return [['M', [$x, $y]], ['H', [$x + $width]], ['V', [$y + $height]], ['H', [$x]], ['Z', []]];
        }

        // Straight edges are left out where the corners meet.
        $horizontal = $width > 2 * $rx;
        $vertical = $height > 2 * $ry;

        return array_values(array_filter([
            ['M', [$x + $rx, $y]],
            $horizontal ? ['H', [$x + $width - $rx]] : null,
            ['A', [$rx, $ry, 0, 0, 1, $x + $width, $y + $ry]],
            $vertical ? ['V', [$y + $height - $ry]] : null,
            ['A', [$rx, $ry, 0, 0, 1, $x + $width - $rx, $y + $height]],
            $horizontal ? ['H', [$x + $rx]] : null,
            ['A', [$rx, $ry, 0, 0, 1, $x, $y + $height - $ry]],
            $vertical ? ['V', [$y + $ry]] : null,
            ['A', [$rx, $ry, 0, 0, 1, $x + $rx, $y]],
            ['Z', []],
        ]));
    }

    /**
     * `rx` / `ry` for rect and ellipse: one missing (or `auto`) takes the
     * other's value; both missing is zero.
     *
     * @return array{0: float, 1: float}
     */
    private function radii(DOMElement $element): array
    {
        $rx = $this->autoSize($element, 'rx');
        $ry = $this->autoSize($element, 'ry');

        return [$rx ?? $ry ?? 0.0, $ry ?? $rx ?? 0.0];
    }

    private function autoSize(DOMElement $element, string $attribute): ?float
    {
        if (! $element->hasAttribute($attribute) || strtolower(trim($element->getAttribute($attribute))) === 'auto') {
            return null;
        }

        return $this->size($element, $attribute);
    }

    /**
     * @return list<array{0: string, 1: list<float>}>
     */
    private function poly(DOMElement $element, bool $closed): array
    {
        $name = $element->localName;

        try {
            $numbers = PathData::numbers($element->getAttribute('points'));
        } catch (InvalidArgumentException $e) {
            throw SvgConversionException::invalidAttribute($this->file, $name, 'points', $e->getMessage());
        }

        if (count($numbers) % 2 !== 0) {
            throw SvgConversionException::invalidAttribute($this->file, $name, 'points', 'it has an odd number of coordinates');
        }

        if (count($numbers) < 4) {
            return [];
        }

        $segments = [];
        foreach (array_chunk($numbers, 2) as $index => $point) {
            $segments[] = [$index === 0 ? 'M' : 'L', $point];
        }

        if ($closed) {
            $segments[] = ['Z', []];
        }

        return $segments;
    }

    private function length(DOMElement $element, string $attribute): float
    {
        if (! $element->hasAttribute($attribute)) {
            return 0.0;
        }

        $value = trim($element->getAttribute($attribute));

        return $this->parseLength($value) ?? throw SvgConversionException::invalidAttribute(
            $this->file, $element->localName, $attribute,
            "\"{$value}\" isn't a number (units other than px, and percentages, can't be converted)"
        );
    }

    private function size(DOMElement $element, string $attribute): float
    {
        $size = $this->length($element, $attribute);

        if ($size < 0) {
            throw SvgConversionException::invalidAttribute($this->file, $element->localName, $attribute, 'it must not be negative');
        }

        return $size;
    }

    private function parseNumber(string $value): ?float
    {
        return preg_match('/^'.self::NUMBER.'$/', trim($value)) ? (float) trim($value) : null;
    }

    private function parseLength(string $value): ?float
    {
        return preg_match('/^\s*('.self::NUMBER.')(?:px)?\s*$/', $value, $match) ? (float) $match[1] : null;
    }

    private function parseAlpha(string $value): ?float
    {
        $value = trim($value);
        $percent = str_ends_with($value, '%');
        $number = $this->parseNumber($percent ? substr($value, 0, -1) : $value);

        if ($number === null) {
            return null;
        }

        return min(1.0, max(0.0, $percent ? $number / 100 : $number));
    }
}
