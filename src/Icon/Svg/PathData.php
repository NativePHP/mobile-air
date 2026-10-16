<?php

namespace Native\Mobile\Icon\Svg;

use InvalidArgumentException;

/**
 * SVG path data tokenizer and writer.
 *
 * Parses the full path grammar (implicit command repetition, `.5.5`,
 * `-1-2`, exponents, arc flags packed against the next number as in
 * `a1 1 0 012 2`) into segments, and writes segments back out with a
 * command letter on every segment and a space between every number.
 * Android's and CoreSVG's path parsers each have their own gaps with the
 * compact forms, so neither ever sees them.
 *
 * Numbers are written without exponents and without trailing zeros.
 */
final class PathData
{
    /** Arguments per segment, keyed by command letter. */
    private const ARITY = [
        'M' => 2, 'm' => 2, 'L' => 2, 'l' => 2, 'T' => 2, 't' => 2,
        'H' => 1, 'h' => 1, 'V' => 1, 'v' => 1,
        'C' => 6, 'c' => 6, 'S' => 4, 's' => 4, 'Q' => 4, 'q' => 4,
        'A' => 7, 'a' => 7,
        'Z' => 0, 'z' => 0,
    ];

    private const NUMBER = '/\G[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/';

    private const WHITESPACE = " \t\n\r\f";

    private int $position = 0;

    private int $length;

    private function __construct(private readonly string $data)
    {
        $this->length = strlen($data);
    }

    /**
     * @return list<array{0: string, 1: list<float>}>
     *
     * @throws InvalidArgumentException on malformed path data
     */
    public static function parse(string $data): array
    {
        return (new self($data))->segments();
    }

    /**
     * Parse a whitespace and/or comma separated list of numbers, as used by
     * `points`, `viewBox` and friends.
     *
     * @return list<float>
     *
     * @throws InvalidArgumentException on anything that isn't a number
     */
    public static function numbers(string $list): array
    {
        $scanner = new self($list);
        $numbers = [];

        $scanner->skipWhitespace();
        while (! $scanner->atEnd()) {
            if ($numbers !== []) {
                $scanner->skipCommaWhitespace();
            }
            $numbers[] = $scanner->readNumber();
            $scanner->skipWhitespace();
        }

        return $numbers;
    }

    /**
     * @param  list<array{0: string, 1: list<float>}>  $segments
     */
    public static function format(array $segments): string
    {
        return implode(' ', array_map(
            fn (array $segment) => $segment[0].implode(' ', array_map(self::number(...), $segment[1])),
            $segments,
        ));
    }

    public static function number(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * @return list<array{0: string, 1: list<float>}>
     */
    private function segments(): array
    {
        $segments = [];
        $command = null;

        while (true) {
            $this->skipWhitespace();
            if ($this->atEnd()) {
                break;
            }

            $char = $this->data[$this->position];

            if (isset(self::ARITY[$char])) {
                if ($command === null && $char !== 'M' && $char !== 'm') {
                    throw new InvalidArgumentException('path data must start with a moveto (M or m)');
                }
                $command = $char;
                $this->position++;
                $this->skipWhitespace();
            } elseif ($command === null) {
                throw new InvalidArgumentException('path data must start with a moveto (M or m)');
            } elseif (self::ARITY[$command] === 0) {
                throw $this->unexpected();
            } elseif ($char === ',') {
                // A comma may separate repeated argument groups ("L1,2,3,4").
                $this->position++;
                $this->skipWhitespace();
            }

            $arity = self::ARITY[$command];
            $arguments = [];
            for ($i = 0; $i < $arity; $i++) {
                if ($i > 0) {
                    $this->skipCommaWhitespace();
                }
                $isFlag = ($command === 'A' || $command === 'a') && ($i === 3 || $i === 4);
                $arguments[] = $isFlag ? $this->readFlag() : $this->readNumber();
            }

            if ($command === 'A' || $command === 'a') {
                // Negative radii are drawn with their absolute value.
                $arguments[0] = abs($arguments[0]);
                $arguments[1] = abs($arguments[1]);
            }

            $segments[] = [$command, $arguments];

            // Pairs after a moveto are implicit linetos; write them as such.
            if ($command === 'M') {
                $command = 'L';
            } elseif ($command === 'm') {
                $command = 'l';
            }
        }

        return $segments;
    }

    private function readNumber(): float
    {
        if (! preg_match(self::NUMBER, $this->data, $match, 0, $this->position)) {
            throw $this->unexpected('a number');
        }

        $this->position += strlen($match[0]);
        $value = (float) $match[0];

        if (! is_finite($value)) {
            throw new InvalidArgumentException("number {$match[0]} is out of range");
        }

        return $value;
    }

    private function readFlag(): float
    {
        $char = $this->data[$this->position] ?? '';

        if ($char !== '0' && $char !== '1') {
            throw $this->unexpected('an arc flag (0 or 1)');
        }

        $this->position++;

        return (float) $char;
    }

    private function skipWhitespace(): void
    {
        $this->position += strspn($this->data, self::WHITESPACE, $this->position);
    }

    private function skipCommaWhitespace(): void
    {
        $this->skipWhitespace();
        if (($this->data[$this->position] ?? '') === ',') {
            $this->position++;
            $this->skipWhitespace();
        }
    }

    private function atEnd(): bool
    {
        return $this->position >= $this->length;
    }

    private function unexpected(string $expected = ''): InvalidArgumentException
    {
        $found = $this->atEnd() ? 'the end of the data' : '"'.$this->data[$this->position].'"';
        $at = $this->atEnd() ? '' : " at offset {$this->position}";

        return new InvalidArgumentException(
            $expected !== '' ? "expected {$expected}{$at}, found {$found}" : "unexpected {$found}{$at}"
        );
    }
}
