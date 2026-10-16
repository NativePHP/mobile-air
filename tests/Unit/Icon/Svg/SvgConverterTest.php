<?php

use Native\Mobile\Icon\Svg\NormalisedSvgWriter;
use Native\Mobile\Icon\Svg\SvgConversionException;
use Native\Mobile\Icon\Svg\SvgIcon;
use Native\Mobile\Icon\Svg\SvgParser;
use Native\Mobile\Icon\Svg\VectorDrawableWriter;

/**
 * The custom icon converter: one parse into paths, written out as an
 * Android VectorDrawable and as the normalised SVG an iOS asset catalog
 * gets. Both outputs carry the same geometry and explicit paint.
 */
function lucideIcon(string $name): string
{
    return file_get_contents(__DIR__.'/../../../Fixtures/Icons/lucide/'.$name.'.svg');
}

function parseIcon(string $body, string $attributes = 'viewBox="0 0 24 24"'): SvgIcon
{
    return SvgParser::parse('<svg xmlns="http://www.w3.org/2000/svg" '.$attributes.'>'.$body.'</svg>', 'resources/icons/test.svg');
}

/**
 * @return list<string>
 */
function iconPathData(SvgIcon $icon): array
{
    return array_map(fn ($path) => $path->data, $icon->paths);
}

function conversionError(string $svg): SvgConversionException
{
    try {
        SvgParser::parse($svg, 'resources/icons/broken.svg');
    } catch (SvgConversionException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the SVG to be rejected.');
}

it('converts Lucide git-commit-vertical to a VectorDrawable', function () {
    $expected = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <vector xmlns:android="http://schemas.android.com/apk/res/android"
            android:width="24dp"
            android:height="24dp"
            android:viewportWidth="24"
            android:viewportHeight="24">
            <path
                android:pathData="M12 3 v6"
                android:fillColor="#00000000"
                android:fillType="nonZero"
                android:strokeColor="#FF000000"
                android:strokeWidth="2"
                android:strokeLineCap="round"
                android:strokeLineJoin="round"
                android:strokeMiterLimit="4" />
            <path
                android:pathData="M15 12 A3 3 0 0 1 9 12 A3 3 0 0 1 15 12 Z"
                android:fillColor="#00000000"
                android:fillType="nonZero"
                android:strokeColor="#FF000000"
                android:strokeWidth="2"
                android:strokeLineCap="round"
                android:strokeLineJoin="round"
                android:strokeMiterLimit="4" />
            <path
                android:pathData="M12 15 v6"
                android:fillColor="#00000000"
                android:fillType="nonZero"
                android:strokeColor="#FF000000"
                android:strokeWidth="2"
                android:strokeLineCap="round"
                android:strokeLineJoin="round"
                android:strokeMiterLimit="4" />
        </vector>

        XML;

    $icon = SvgParser::parse(lucideIcon('git-commit-vertical'), 'git-commit-vertical.svg');

    expect(VectorDrawableWriter::write($icon))->toBe($expected);
});

it('converts Lucide git-commit-vertical to a normalised SVG', function () {
    $expected = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
          <path d="M12 3 v6" fill="none" fill-rule="nonzero" stroke="#000000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke-miterlimit="4"/>
          <path d="M15 12 A3 3 0 0 1 9 12 A3 3 0 0 1 15 12 Z" fill="none" fill-rule="nonzero" stroke="#000000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke-miterlimit="4"/>
          <path d="M12 15 v6" fill="none" fill-rule="nonzero" stroke="#000000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke-miterlimit="4"/>
        </svg>

        SVG;

    $icon = SvgParser::parse(lucideIcon('git-commit-vertical'), 'git-commit-vertical.svg');

    expect(NormalisedSvgWriter::write($icon))->toBe($expected);
});

it('converts Lucide image (rounded rect, circle, relative path) to both outputs', function () {
    $vectorDrawable = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <vector xmlns:android="http://schemas.android.com/apk/res/android"
            android:width="24dp"
            android:height="24dp"
            android:viewportWidth="24"
            android:viewportHeight="24">
            <path
                android:pathData="M5 3 H19 A2 2 0 0 1 21 5 V19 A2 2 0 0 1 19 21 H5 A2 2 0 0 1 3 19 V5 A2 2 0 0 1 5 3 Z"
                android:fillColor="#00000000"
                android:fillType="nonZero"
                android:strokeColor="#FF000000"
                android:strokeWidth="2"
                android:strokeLineCap="round"
                android:strokeLineJoin="round"
                android:strokeMiterLimit="4" />
            <path
                android:pathData="M11 9 A2 2 0 0 1 7 9 A2 2 0 0 1 11 9 Z"
                android:fillColor="#00000000"
                android:fillType="nonZero"
                android:strokeColor="#FF000000"
                android:strokeWidth="2"
                android:strokeLineCap="round"
                android:strokeLineJoin="round"
                android:strokeMiterLimit="4" />
            <path
                android:pathData="m21 15 l-3.086 -3.086 a2 2 0 0 0 -2.828 0 L6 21"
                android:fillColor="#00000000"
                android:fillType="nonZero"
                android:strokeColor="#FF000000"
                android:strokeWidth="2"
                android:strokeLineCap="round"
                android:strokeLineJoin="round"
                android:strokeMiterLimit="4" />
        </vector>

        XML;

    $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
          <path d="M5 3 H19 A2 2 0 0 1 21 5 V19 A2 2 0 0 1 19 21 H5 A2 2 0 0 1 3 19 V5 A2 2 0 0 1 5 3 Z" fill="none" fill-rule="nonzero" stroke="#000000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke-miterlimit="4"/>
          <path d="M11 9 A2 2 0 0 1 7 9 A2 2 0 0 1 11 9 Z" fill="none" fill-rule="nonzero" stroke="#000000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke-miterlimit="4"/>
          <path d="m21 15 l-3.086 -3.086 a2 2 0 0 0 -2.828 0 L6 21" fill="none" fill-rule="nonzero" stroke="#000000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke-miterlimit="4"/>
        </svg>

        SVG;

    $icon = SvgParser::parse(lucideIcon('image'), 'image.svg');

    expect(VectorDrawableWriter::write($icon))->toBe($vectorDrawable)
        ->and(NormalisedSvgWriter::write($icon))->toBe($svg);
});

it('converts every Lucide fixture into well-formed outputs', function (string $name) {
    $source = new DOMDocument;
    $source->loadXML(lucideIcon($name));

    $icon = SvgParser::parse(lucideIcon($name), "{$name}.svg");

    $drawable = new DOMDocument;
    $normalised = new DOMDocument;

    expect($icon->paths)->toHaveCount($source->documentElement->childNodes->length)
        ->and($drawable->loadXML(VectorDrawableWriter::write($icon)))->toBeTrue()
        ->and($drawable->getElementsByTagName('path'))->toHaveCount(count($icon->paths))
        ->and($normalised->loadXML(NormalisedSvgWriter::write($icon)))->toBeTrue()
        ->and($normalised->getElementsByTagName('path'))->toHaveCount(count($icon->paths));
})->with([
    'git-commit-vertical', 'bell', 'user-round', 'settings-2', 'message-square-reply',
    'star', 'bookmark', 'link', 'trash-2', 'image',
]);

it('converts shapes to path data', function (string $shape, array $expected) {
    expect(iconPathData(parseIcon($shape, 'viewBox="0 0 24 24" fill="none" stroke="currentColor"')))->toBe($expected);
})->with([
    'circle' => ['<circle cx="12" cy="12" r="3"/>', ['M15 12 A3 3 0 0 1 9 12 A3 3 0 0 1 15 12 Z']],
    'ellipse' => ['<ellipse cx="12" cy="10" rx="4" ry="2"/>', ['M16 10 A4 2 0 0 1 8 10 A4 2 0 0 1 16 10 Z']],
    'ellipse with one radius' => ['<ellipse cx="12" cy="10" rx="4"/>', ['M16 10 A4 4 0 0 1 8 10 A4 4 0 0 1 16 10 Z']],
    'line' => ['<line x1="1" y1="2" x2="20" y2="22"/>', ['M1 2 L20 22']],
    'zero-length line keeps its caps' => ['<line x1="5" y1="5" x2="5" y2="5"/>', ['M5 5 L5 5']],
    'rect' => ['<rect x="2" y="3" width="10" height="8"/>', ['M2 3 H12 V11 H2 Z']],
    'rect at the origin' => ['<rect width="24" height="24"/>', ['M0 0 H24 V24 H0 Z']],
    'rounded rect' => ['<rect x="2" y="2" width="20" height="10" rx="2" ry="1"/>', ['M4 2 H20 A2 1 0 0 1 22 3 V11 A2 1 0 0 1 20 12 H4 A2 1 0 0 1 2 11 V3 A2 1 0 0 1 4 2 Z']],
    'rect with rx only' => ['<rect width="10" height="10" rx="2"/>', ['M2 0 H8 A2 2 0 0 1 10 2 V8 A2 2 0 0 1 8 10 H2 A2 2 0 0 1 0 8 V2 A2 2 0 0 1 2 0 Z']],
    'rect with ry only' => ['<rect width="10" height="10" ry="3"/>', ['M3 0 H7 A3 3 0 0 1 10 3 V7 A3 3 0 0 1 7 10 H3 A3 3 0 0 1 0 7 V3 A3 3 0 0 1 3 0 Z']],
    'rect radii clamped to half its size' => ['<rect width="10" height="4" rx="20" ry="20"/>', ['M5 0 A5 2 0 0 1 10 2 A5 2 0 0 1 5 4 A5 2 0 0 1 0 2 A5 2 0 0 1 5 0 Z']],
    'polyline' => ['<polyline points="1,2 3,4 5 6"/>', ['M1 2 L3 4 L5 6']],
    'polygon' => ['<polygon points="12 2 22 22 2 22"/>', ['M12 2 L22 22 L2 22 Z']],
    'px lengths' => ['<circle cx="12px" cy="12px" r="3px"/>', ['M15 12 A3 3 0 0 1 9 12 A3 3 0 0 1 15 12 Z']],
]);

it('skips shapes SVG draws nothing for', function (string $shape) {
    expect(parseIcon($shape, 'viewBox="0 0 24 24" fill="none" stroke="currentColor"')->paths)->toBe([]);
})->with([
    'circle with no radius' => '<circle cx="12" cy="12" r="0"/>',
    'circle without r' => '<circle cx="12" cy="12"/>',
    'ellipse with a zero radius' => '<ellipse cx="12" cy="12" rx="0" ry="4"/>',
    'rect with no width' => '<rect width="0" height="10"/>',
    'rect with no height' => '<rect width="10"/>',
    'polyline with one point' => '<polyline points="1 2"/>',
    'polygon with no points' => '<polygon points=""/>',
    'path without d' => '<path/>',
    'path with empty d' => '<path d=""/>',
    'nothing painted' => '<path d="M0 0h24v24H0z" stroke="none"/>',
    'stroke of zero width' => '<path d="M0 0h24" stroke-width="0"/>',
    'fully transparent' => '<path d="M0 0h24" stroke-opacity="0"/>',
]);

it('re-emits compact path syntax from real path elements', function () {
    $icon = parseIcon('<path d="M2 2a1 1 0 012 2l.5.5-1-1e1z"/>');

    expect(iconPathData($icon))->toBe(['M2 2 a1 1 0 0 1 2 2 l0.5 0.5 l-1 -10 z']);
});

/**
 * A Material Symbols download (Apache 2.0): `24px` sizes, a `0 -960 960 960`
 * viewBox and a light grey fill that becomes the tint.
 */
it('converts a Material Symbols icon with its shifted 960 viewBox to 24', function () {
    $material = '<svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="M160-200v-80h80v-280q0-83 50-147.5T420-792v-28q0-25 17.5-42.5T480-880q25 0 42.5 17.5T540-820v28q80 20 130 84.5T720-560v280h80v80H160Zm320-300Zm0 420q-33 0-56.5-23.5T400-160h160q0 33-23.5 56.5T480-80ZM320-280h320v-280q0-66-47-113t-113-47q-66 0-113 47t-47 113v280Z"/></svg>';

    $icon = SvgParser::parse($material, 'resources/icons/notifications.svg');

    expect(VectorDrawableWriter::write($icon))->toBe(<<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <vector xmlns:android="http://schemas.android.com/apk/res/android"
            android:width="24dp"
            android:height="24dp"
            android:viewportWidth="960"
            android:viewportHeight="960">
            <group
                android:translateY="960">
                <path
                    android:pathData="M160 -200 v-80 h80 v-280 q0 -83 50 -147.5 T420 -792 v-28 q0 -25 17.5 -42.5 T480 -880 q25 0 42.5 17.5 T540 -820 v28 q80 20 130 84.5 T720 -560 v280 h80 v80 H160 Z m320 -300 Z m0 420 q-33 0 -56.5 -23.5 T400 -160 h160 q0 33 -23.5 56.5 T480 -80 Z M320 -280 h320 v-280 q0 -66 -47 -113 t-113 -47 q-66 0 -113 47 t-47 113 v280 Z"
                    android:fillColor="#FF000000"
                    android:fillType="nonZero"
                    android:strokeColor="#00000000"
                    android:strokeWidth="0"
                    android:strokeLineCap="butt"
                    android:strokeLineJoin="miter"
                    android:strokeMiterLimit="4" />
            </group>
        </vector>

        XML);

    expect(NormalisedSvgWriter::write($icon))->toBe(<<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 -960 960 960">
          <path d="M160 -200 v-80 h80 v-280 q0 -83 50 -147.5 T420 -792 v-28 q0 -25 17.5 -42.5 T480 -880 q25 0 42.5 17.5 T540 -820 v28 q80 20 130 84.5 T720 -560 v280 h80 v80 H160 Z m320 -300 Z m0 420 q-33 0 -56.5 -23.5 T400 -160 h160 q0 33 -23.5 56.5 T480 -80 Z M320 -280 h320 v-280 q0 -66 -47 -113 t-113 -47 q-66 0 -113 47 t-47 113 v280 Z" fill="#000000" fill-rule="nonzero" stroke="none" stroke-width="0" stroke-linecap="butt" stroke-linejoin="miter" stroke-miterlimit="4"/>
        </svg>

        SVG);
});

/**
 * Tabler icons (MIT) open with an invisible bounding box: neither fill nor
 * stroke paints, so it's dropped rather than drawn or counted as a colour.
 */
it('converts a Tabler icon and drops its invisible bounding box', function () {
    $tabler = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6" /><path d="M9 17v1a3 3 0 0 0 6 0v-1" /></svg>';

    $icon = SvgParser::parse($tabler, 'resources/icons/bell.svg');

    expect(VectorDrawableWriter::write($icon))->toBe(<<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <vector xmlns:android="http://schemas.android.com/apk/res/android"
            android:width="24dp"
            android:height="24dp"
            android:viewportWidth="24"
            android:viewportHeight="24">
            <path
                android:pathData="M10 5 a2 2 0 1 1 4 0 a7 7 0 0 1 4 6 v3 a4 4 0 0 0 2 3 h-16 a4 4 0 0 0 2 -3 v-3 a7 7 0 0 1 4 -6"
                android:fillColor="#00000000"
                android:fillType="nonZero"
                android:strokeColor="#FF000000"
                android:strokeWidth="2"
                android:strokeLineCap="round"
                android:strokeLineJoin="round"
                android:strokeMiterLimit="4" />
            <path
                android:pathData="M9 17 v1 a3 3 0 0 0 6 0 v-1"
                android:fillColor="#00000000"
                android:fillType="nonZero"
                android:strokeColor="#FF000000"
                android:strokeWidth="2"
                android:strokeLineCap="round"
                android:strokeLineJoin="round"
                android:strokeMiterLimit="4" />
        </vector>

        XML);

    expect(NormalisedSvgWriter::write($icon))->toBe(<<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
          <path d="M10 5 a2 2 0 1 1 4 0 a7 7 0 0 1 4 6 v3 a4 4 0 0 0 2 3 h-16 a4 4 0 0 0 2 -3 v-3 a7 7 0 0 1 4 -6" fill="none" fill-rule="nonzero" stroke="#000000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke-miterlimit="4"/>
          <path d="M9 17 v1 a3 3 0 0 0 6 0 v-1" fill="none" fill-rule="nonzero" stroke="#000000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke-miterlimit="4"/>
        </svg>

        SVG);
});

it('does not count the colour of an unpainted shape', function () {
    $icon = parseIcon('<path d="M0 0h24v24H0z" fill="none" stroke="none"/><rect width="24" height="24" fill="#ffffff" fill-opacity="0"/><path d="M2 2h4v4z" fill="#000"/>');

    expect($icon->paths)->toHaveCount(1);
});

it('translates both axes when the viewBox origin moves on both', function () {
    $drawable = VectorDrawableWriter::write(parseIcon('<path d="M0 0h1"/>', 'viewBox="-2 3.5 24 24"'));

    expect($drawable)->toContain("<group\n        android:translateX=\"2\"\n        android:translateY=\"-3.5\">");
});

it('sizes non-square icons by their larger side', function () {
    $icon = parseIcon('<path d="M0 0h48"/>', 'viewBox="0 0 48 24"');

    expect($icon->width())->toBe(24.0)->and($icon->height())->toBe(12.0)
        ->and(NormalisedSvgWriter::write($icon))->toStartWith('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="12" viewBox="0 0 48 24">');
});

it('derives the viewBox from numeric width and height', function () {
    $icon = parseIcon('<path d="M0 0h48"/>', 'width="48px" height="48"');

    expect([$icon->minX, $icon->minY, $icon->viewportWidth, $icon->viewportHeight])->toBe([0.0, 0.0, 48.0, 48.0])
        ->and($icon->width())->toBe(24.0);
});

it('inherits presentation attributes from svg and g', function () {
    $icon = parseIcon(
        '<g stroke-width="3" stroke-linecap="square"><g stroke-linejoin="bevel"><path d="M0 0h1"/></g></g>',
        'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-miterlimit="10"'
    );

    $path = $icon->paths[0];

    expect($path->fillAlpha)->toBeNull()
        ->and($path->strokeAlpha)->toBe(1.0)
        ->and($path->strokeWidth)->toBe(3.0)
        ->and($path->lineCap)->toBe('square')
        ->and($path->lineJoin)->toBe('bevel')
        ->and($path->miterLimit)->toBe(10.0);
});

it('applies SVG defaults when nothing is set', function () {
    $path = parseIcon('<path d="M0 0h24v24z"/>')->paths[0];

    expect($path->fillAlpha)->toBe(1.0)
        ->and($path->strokeAlpha)->toBeNull()
        ->and($path->strokeWidth)->toBe(0.0)
        ->and($path->lineCap)->toBe('butt')
        ->and($path->lineJoin)->toBe('miter')
        ->and($path->miterLimit)->toBe(4.0)
        ->and($path->fillRule)->toBe('nonzero');
});

it('reads style="" and lets it beat presentation attributes', function () {
    $icon = parseIcon(
        '<g style="stroke: currentColor; stroke-width: 4 !important"><path d="M0 0h1" fill="#000" style="fill:none;stroke-linecap:round; /* comment */"/></g>'
    );

    $path = $icon->paths[0];

    expect($path->fillAlpha)->toBeNull()
        ->and($path->strokeAlpha)->toBe(1.0)
        ->and($path->strokeWidth)->toBe(4.0)
        ->and($path->lineCap)->toBe('round');
});

it('treats inherit as the parent value', function () {
    $path = parseIcon('<g stroke="#000" stroke-width="2"><path d="M0 0h1" stroke-width="inherit" fill="none"/></g>')->paths[0];

    expect($path->strokeWidth)->toBe(2.0)->and($path->strokeAlpha)->toBe(1.0);
});

it('multiplies opacity into fill and stroke alpha', function () {
    $icon = parseIcon(
        '<g opacity="0.5"><path d="M0 0h1v1z" opacity=".5" fill-opacity="0.8" stroke="currentColor" stroke-opacity="50%"/></g>'
    );

    $path = $icon->paths[0];

    expect($path->fillAlpha)->toBe(0.2)
        ->and($path->strokeAlpha)->toBe(0.125);

    expect(VectorDrawableWriter::write($icon))
        ->toContain('android:fillAlpha="0.2"')
        ->toContain('android:strokeAlpha="0.125"');

    expect(NormalisedSvgWriter::write($icon))
        ->toContain('fill="#000000" fill-opacity="0.2"')
        ->toContain('stroke="#000000" stroke-opacity="0.125"')
        ->not->toContain(' opacity=');
});

it('carries alpha from the colour itself', function (string $colour, float $alpha) {
    expect(parseIcon('<path d="M0 0h1v1z" fill="'.$colour.'"/>')->paths[0]->fillAlpha)->toEqualWithDelta($alpha, 0.0001);
})->with([
    '#rgba' => ['#0008', 0x88 / 255],
    '#rrggbbaa' => ['#00000080', 0x80 / 255],
    'rgb() with slash alpha' => ['rgb(0 0 0 / 25%)', 0.25],
    'rgba()' => ['rgba(0, 0, 0, 0.5)', 0.5],
    'plain rgb()' => ['rgb(10%, 20%, 30%)', 1.0],
    'white' => ['white', 1.0],
    'currentColor' => ['currentColor', 1.0],
]);

it('maps fill-rule evenodd to both outputs', function () {
    $icon = parseIcon('<path d="M0 0h10v10H0z M2 2h6v6H2z" fill-rule="evenodd"/>');

    expect(VectorDrawableWriter::write($icon))->toContain('android:fillType="evenOdd"')
        ->and(NormalisedSvgWriter::write($icon))->toContain('fill-rule="evenodd"');
});

it('ignores title, desc, comments, empty defs and non-rendering attributes', function () {
    $svg = <<<'SVG'
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">
        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" viewBox="0 0 24 24" aria-hidden="true" data-slot="icon" class="icon" xml:space="preserve">
          <title>Bell</title>
          <desc>A bell</desc>
          <!-- drawn by hand -->
          <defs></defs>
          <defs/>
          <g id="layer" clip-path="none" display="inline">
            <path id="p" class="p" d="M0 0h1"><title>edge</title></path>
          </g>
        </svg>
        SVG;

    expect(iconPathData(SvgParser::parse($svg, 'bell.svg')))->toBe(['M0 0 h1']);
});

it('accepts an svg without the SVG namespace', function () {
    expect(iconPathData(SvgParser::parse('<svg viewBox="0 0 24 24"><path d="M0 0h1"/></svg>', 'plain.svg')))->toBe(['M0 0 h1']);
});

it('fails loudly on unsupported elements', function (string $body, string $element) {
    $error = conversionError('<svg xmlns="http://www.w3.org/2000/svg" xmlns:x="urn:x" viewBox="0 0 24 24">'.$body.'</svg>');

    expect($error->iconFile)->toBe('resources/icons/broken.svg')
        ->and($error->element)->toBe($element)
        ->and($error->attribute)->toBeNull()
        ->and($error->getMessage())->toStartWith('resources/icons/broken.svg: <'.$element.'> is not supported in custom icons');
})->with([
    'use' => ['<use href="#a"/>', 'use'],
    'non-empty defs' => ['<defs><path id="a" d="M0 0h1"/></defs>', 'defs'],
    'clipPath' => ['<clipPath id="c"><rect width="1" height="1"/></clipPath>', 'clipPath'],
    'mask' => ['<mask id="m"/>', 'mask'],
    'linearGradient' => ['<linearGradient id="g"/>', 'linearGradient'],
    'radialGradient' => ['<radialGradient id="g"/>', 'radialGradient'],
    'pattern' => ['<pattern id="p"/>', 'pattern'],
    'filter' => ['<filter id="f"/>', 'filter'],
    'text' => ['<text>Hi</text>', 'text'],
    'image' => ['<image href="a.png"/>', 'image'],
    'style' => ['<style>path { fill: red }</style>', 'style'],
    'symbol' => ['<symbol id="s"/>', 'symbol'],
    'marker' => ['<marker id="m"/>', 'marker'],
    'foreignObject' => ['<foreignObject/>', 'foreignObject'],
    'nested svg' => ['<svg viewBox="0 0 1 1"/>', 'svg'],
    'unknown element' => ['<blink/>', 'blink'],
    'nested in a group' => ['<g><g><use href="#a"/></g></g>', 'use'],
    'inside a shape' => ['<path d="M0 0h1"><animate attributeName="d"/></path>', 'animate'],
    'foreign namespace' => ['<x:thing/>', 'x:thing'],
]);

it('fails loudly on transforms anywhere', function (string $svg, string $element) {
    $error = conversionError($svg);

    expect($error->element)->toBe($element)
        ->and($error->attribute)->toBe('transform')
        ->and($error->getMessage())->toBe("resources/icons/broken.svg: attribute \"transform\" on <{$element}> is not supported in custom icons (flatten transforms into the path data first).");
})->with([
    'on a path' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h1" transform="rotate(45)"/></svg>', 'path'],
    'on a group' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><g transform="translate(1 1)"><path d="M0 0h1"/></g></svg>', 'g'],
    'on the root' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" transform="scale(2)"><path d="M0 0h1"/></svg>', 'svg'],
    'identity still fails' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle r="1" transform=""/></svg>', 'circle'],
]);

it('fails loudly on unsupported or invalid attributes', function (string $body, string $element, string $attribute, string $message) {
    $error = conversionError('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'.$body.'</svg>');

    expect($error->element)->toBe($element)
        ->and($error->attribute)->toBe($attribute)
        ->and($error->getMessage())->toContain('resources/icons/broken.svg')
        ->and($error->getMessage())->toContain($message);
})->with([
    'clip-path' => ['<g clip-path="url(#c)"><path d="M0 0h1"/></g>', 'g', 'clip-path', 'attribute "clip-path" on <g>'],
    'mask attribute' => ['<path d="M0 0h1" mask="url(#m)"/>', 'path', 'mask', 'attribute "mask" on <path>'],
    'filter attribute' => ['<path d="M0 0h1" filter="url(#f)"/>', 'path', 'filter', 'attribute "filter" on <path>'],
    'dashes' => ['<path d="M0 0h1" stroke="#000" stroke-dasharray="2 2"/>', 'path', 'stroke-dasharray', 'dashed strokes'],
    'display none' => ['<path d="M0 0h1" display="none"/>', 'path', 'display', 'attribute "display" on <path>'],
    'gradient paint' => ['<path d="M0 0h1" fill="url(#g)"/>', 'path', 'fill', 'gradient or pattern'],
    'named colour' => ['<path d="M0 0h1" fill="red"/>', 'path', 'fill', 'fill "red" isn\'t a supported colour'],
    'bad hex' => ['<path d="M0 0h1" stroke="#12345"/>', 'path', 'stroke', 'stroke "#12345" isn\'t a supported colour'],
    'other style property' => ['<path d="M0 0h1" style="stroke-dasharray: 2"/>', 'path', 'style', '"stroke-dasharray" isn\'t supported'],
    'unreadable style' => ['<path d="M0 0h1" style="fill"/>', 'path', 'style', 'can\'t read the declaration "fill"'],
    'bad style value' => ['<path d="M0 0h1" style="stroke-linecap: pointy"/>', 'path', 'style', 'stroke-linecap "pointy" isn\'t one of butt, round or square'],
    'bad linejoin' => ['<path d="M0 0h1" stroke-linejoin="arcs"/>', 'path', 'stroke-linejoin', 'isn\'t one of miter, round or bevel'],
    'bad miterlimit' => ['<path d="M0 0h1" stroke-miterlimit="0.5"/>', 'path', 'stroke-miterlimit', 'a number of at least 1'],
    'bad fill-rule' => ['<path d="M0 0h1" fill-rule="winding"/>', 'path', 'fill-rule', 'nonzero or evenodd'],
    'stroke width in em' => ['<path d="M0 0h1" stroke-width="1em"/>', 'path', 'stroke-width', 'non-negative number'],
    'bad opacity' => ['<path d="M0 0h1" opacity="half"/>', 'path', 'opacity', 'a number or percentage'],
    'malformed path data' => ['<path d="M0 0 L1"/>', 'path', 'd', 'expected a number, found the end of the data'],
    'path data without moveto' => ['<path d="L1 1"/>', 'path', 'd', 'must start with a moveto'],
    'percentage length' => ['<circle cx="50%" cy="12" r="3"/>', 'circle', 'cx', '"50%" isn\'t a number'],
    'negative radius' => ['<circle cx="12" cy="12" r="-3"/>', 'circle', 'r', 'must not be negative'],
    'negative width' => ['<rect width="-1" height="2"/>', 'rect', 'width', 'must not be negative'],
    'odd points' => ['<polyline points="1 2 3"/>', 'polyline', 'points', 'odd number of coordinates'],
    'bad points' => ['<polygon points="1 2 x 4"/>', 'polygon', 'points', 'expected a number at offset 4'],
]);

it('fails loudly on a bad viewBox', function (string $attributes, string $message) {
    $error = conversionError('<svg xmlns="http://www.w3.org/2000/svg" '.$attributes.'><path d="M0 0h1"/></svg>');

    expect($error->element)->toBe('svg')
        ->and($error->attribute)->toBe('viewBox')
        ->and($error->getMessage())->toContain($message);
})->with([
    'missing, nothing to derive from' => ['', 'no numeric width and height'],
    'missing, width in percent' => ['width="100%" height="24"', 'no numeric width and height'],
    'too few numbers' => ['viewBox="0 0 24"', 'expected four numbers'],
    'not numbers' => ['viewBox="0 0 auto 24"', 'expected a number'],
    'zero size' => ['viewBox="0 0 0 24"', 'greater than zero'],
]);

it('fails loudly on documents that are not SVG', function (string $svg, string $message) {
    $error = conversionError($svg);

    expect($error->iconFile)->toBe('resources/icons/broken.svg')
        ->and($error->getMessage())->toStartWith('resources/icons/broken.svg: ')
        ->and($error->getMessage())->toContain($message);
})->with([
    'empty file' => ['  ', 'the file is empty'],
    'malformed XML' => ['<svg viewBox="0 0 24 24"><path d="M0 0h1"></svg>', 'not well-formed XML'],
    'wrong root' => ['<html xmlns="http://www.w3.org/1999/xhtml"/>', 'the root element must be <svg>'],
    'root in another namespace' => ['<svg xmlns="urn:not-svg" viewBox="0 0 24 24"/>', 'the root element must be <svg>'],
    'entity declarations' => ['<!DOCTYPE svg [<!ENTITY a "aaaa">]><svg viewBox="0 0 24 24">&a;</svg>', 'entity declarations are not allowed'],
    'external entities' => ['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg viewBox="0 0 24 24">&x;</svg>', 'entity declarations are not allowed'],
]);

it('fails when an icon paints with more than one colour', function () {
    $error = conversionError('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h1v1z" fill="#000"/><path d="M2 2h1v1z" fill="#ffffff" stroke="rgb(255, 0, 0)"/></svg>');

    expect($error->getMessage())->toBe('resources/icons/broken.svg: custom icons are tinted as one colour, but this one paints with #000000, #ffffff, #ff0000. Use a single colour (or currentColor) throughout.');
});

it('allows one colour alongside currentColor, however it is written', function () {
    $icon = parseIcon('<path d="M0 0h1v1z" fill="#000"/><path d="M2 2h1v1z" fill="black" stroke="currentColor"/><path d="M4 4h1v1z" fill="rgb(0,0,0)" fill-opacity=".5"/><path d="M5 5h1v1z" fill="#000000"/>');

    expect($icon->paths)->toHaveCount(4);
});

it('only counts colours that actually paint', function () {
    // The white fill never paints a line, and the red stroke has no width.
    $icon = parseIcon('<g fill="#ffffff"><line x1="0" y1="0" x2="1" y2="1" stroke="#000"/></g><path d="M0 0h1v1z" fill="#000" stroke="#ff0000" stroke-width="0"/>');

    expect($icon->paths)->toHaveCount(2);
});
