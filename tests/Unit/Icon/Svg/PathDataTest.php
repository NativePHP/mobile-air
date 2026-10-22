<?php

use Native\Mobile\Icon\Svg\PathData;

/**
 * Path data is re-emitted with a command letter on every segment and a
 * space between every number, so Android's and CoreSVG's parsers never see
 * the compact forms they each mishandle.
 */
function normalisePath(string $data): string
{
    return PathData::format(PathData::parse($data));
}

it('re-emits compact path syntax with explicit separators', function (string $data, string $expected) {
    expect(normalisePath($data))->toBe($expected);
})->with([
    'plain' => ['M12 3v6', 'M12 3 v6'],
    'chained decimals' => ['M.5.5l.25.75', 'M0.5 0.5 l0.25 0.75'],
    'chained negatives' => ['M-1-2l-3-4', 'M-1 -2 l-3 -4'],
    'commas' => ['M1,2 L3,4', 'M1 2 L3 4'],
    'commas between groups' => ['M1,2,3,4', 'M1 2 L3 4'],
    'exponents' => ['M1e1 2E-1l1.5e+2 -2e0', 'M10 0.2 l150 -2'],
    'implicit lineto after moveto' => ['M1 2 3 4 5 6', 'M1 2 L3 4 L5 6'],
    'implicit relative lineto after relative moveto' => ['m1 2 3 4', 'm1 2 l3 4'],
    'implicit repeats keep their command' => ['M0 0h1 2 3v4 5', 'M0 0 h1 h2 h3 v4 v5'],
    'whitespace of every kind' => ["  M 1\t2\n\rL\f3 4  ", 'M1 2 L3 4'],
    'closepath then moveto' => ['M0 0L1 1zM2 2Z', 'M0 0 L1 1 z M2 2 Z'],
    'every command' => ['M0 0L1 1H2V3C1 2 3 4 5 6S7 8 9 10Q1 2 3 4T5 6A1 2 3 1 0 4 5Z', 'M0 0 L1 1 H2 V3 C1 2 3 4 5 6 S7 8 9 10 Q1 2 3 4 T5 6 A1 2 3 1 0 4 5 Z'],
    'empty' => ['', ''],
]);

it('parses compact arc flags packed against the next number', function () {
    expect(normalisePath('M0 0a1 1 0 012 2'))->toBe('M0 0 a1 1 0 0 1 2 2')
        ->and(normalisePath('M0 0a1 1 0 1112 2'))->toBe('M0 0 a1 1 0 1 1 12 2')
        ->and(normalisePath('M0 0a1,1,0,0,1,2,2'))->toBe('M0 0 a1 1 0 0 1 2 2')
        ->and(normalisePath('M0 0a1 1 0 0 1-2-2'))->toBe('M0 0 a1 1 0 0 1 -2 -2');
});

it('draws negative arc radii with their absolute value', function () {
    expect(normalisePath('M0 0A-2 -3 0 0 1 4 4'))->toBe('M0 0 A2 3 0 0 1 4 4');
});

it('writes numbers without exponents or trailing zeros', function (float $number, string $expected) {
    expect(PathData::number($number))->toBe($expected);
})->with([
    [2.0, '2'],
    [2.5, '2.5'],
    [-0.75, '-0.75'],
    [0.1 + 0.2, '0.3'],
    [1.0e-7, '0'],
    [-1.0e-7, '0'],
    [1.5e21, '1500000000000000000000'],
    [960.0, '960'],
]);

it('rejects malformed path data', function (string $data, string $message) {
    expect(fn () => PathData::parse($data))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no leading moveto' => ['L1 2', 'must start with a moveto'],
    'number before any command' => ['1 2', 'must start with a moveto'],
    'missing argument' => ['M1', 'expected a number, found the end of the data'],
    'incomplete repeat' => ['M1 2 3', 'expected a number, found the end of the data'],
    'unknown command' => ['M1 2 X3 4', 'expected a number at offset 5, found "X"'],
    'bad arc flag' => ['M0 0 A1 1 0 2 1 3 3', 'expected an arc flag (0 or 1) at offset 12, found "2"'],
    'numbers after closepath' => ['M0 0 Z 1 2', 'unexpected "1" at offset 7'],
    'dangling exponent' => ['M1e 2', 'expected a number at offset 2, found "e"'],
    'double comma' => ['M1,,2', 'expected a number at offset 3, found ","'],
    'comma after command' => ['M,1 2', 'expected a number at offset 1, found ","'],
    'trailing comma' => ['M1 2,', 'expected a number, found the end of the data'],
    'out of range' => ['M1e999 0', 'out of range'],
]);

it('parses number lists for points and viewBox', function () {
    expect(PathData::numbers('0 -960 960,960'))->toBe([0.0, -960.0, 960.0, 960.0])
        ->and(PathData::numbers('1,2 3,4'))->toBe([1.0, 2.0, 3.0, 4.0])
        ->and(PathData::numbers('  '))->toBe([]);

    expect(fn () => PathData::numbers('1 2 three'))->toThrow(InvalidArgumentException::class, 'expected a number at offset 4');
});
