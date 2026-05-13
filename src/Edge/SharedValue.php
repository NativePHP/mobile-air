<?php

namespace Native\Mobile\Edge;

/**
 * Reanimated-style shared value.
 *
 * Represents a numeric value that lives on the **native side** and is
 * mutated on the UI thread (by gestures, animations, or platform
 * callbacks). PHP only holds an opaque handle (`id`) — per-frame values
 * never cross the PHP/native boundary.
 *
 * Created in PHP via `SharedValue::make($initial)`. Bound to a UI
 * gesture via `<native:gesture-area :pan-y="$value">`. Read from any
 * other element's animatable prop, optionally through a formula:
 *
 *     $drag = SharedValue::make();
 *
 *     <native:gesture-area :pan-y="$drag">
 *         <native:column
 *             :translate-y="$drag"
 *             :opacity="$drag->interpolate([0, 200], [1, 0])"
 *             :scale="$drag->interpolate([0, 200], [1, 0.7])">
 *             Pull me down
 *         </native:column>
 *     </native:gesture-area>
 *
 * The wire format: every SharedValue serializes (via `__toString`) to
 * a compact string with the `__sv:` prefix. `NativeElementCollector`
 * detects this in animation-prop attributes and writes a companion
 * `{prop}_sv` key alongside the literal initial value so the native
 * renderer can subscribe.
 */
class SharedValue
{
    private static int $nextId = 1;

    public readonly int $id;

    /** PHP-side snapshot — updated via `setValue()` when PHP gets a
     *  callback (e.g. drag-end). Per-frame values during gestures DO
     *  NOT round-trip through PHP. */
    private float $value;

    /** Formula chain: list of operations applied to the base shared
     *  value. Empty array == raw binding. */
    private array $formula;

    private function __construct(float $initial, array $formula = [])
    {
        $this->id = self::$nextId++;
        $this->value = $initial;
        $this->formula = $formula;
    }

    public static function make(float $initial = 0.0): self
    {
        return new self($initial);
    }

    /**
     * Linearly map this value from `$input` range to `$output` range.
     * Below input[0] clamps to output[0]; above input[1] clamps to
     * output[1].
     *
     *     $drag->interpolate([0, 200], [1, 0])   // fade out as drag rises
     */
    public function interpolate(array $input, array $output): self
    {
        $derived = clone $this;
        $derived->formula = [
            ...$this->formula,
            ['op' => 'interp', 'in' => $input, 'out' => $output],
        ];

        return $derived;
    }

    /** Clamp the value into `[min, max]`. */
    public function clamp(float $min, float $max): self
    {
        $derived = clone $this;
        $derived->formula = [
            ...$this->formula,
            ['op' => 'clamp', 'min' => $min, 'max' => $max],
        ];

        return $derived;
    }

    /** Multiply the value by a constant. */
    public function multiply(float $by): self
    {
        $derived = clone $this;
        $derived->formula = [
            ...$this->formula,
            ['op' => 'mul', 'by' => $by],
        ];

        return $derived;
    }

    /** Add a constant. */
    public function add(float $offset): self
    {
        $derived = clone $this;
        $derived->formula = [
            ...$this->formula,
            ['op' => 'add', 'by' => $offset],
        ];

        return $derived;
    }

    public function value(): float
    {
        // Evaluate the formula chain on the PHP-side snapshot. This is
        // mostly useful for `@drag-end` callbacks where PHP needs to
        // make a decision based on where the user released.
        $v = $this->value;
        foreach ($this->formula as $step) {
            $v = match ($step['op']) {
                'interp' => self::evalInterp($v, $step['in'], $step['out']),
                'clamp'  => max($step['min'], min($step['max'], $v)),
                'mul'    => $v * $step['by'],
                'add'    => $v + $step['by'],
                default  => $v,
            };
        }

        return $v;
    }

    /** Called by the framework when the native side reports a snapshot
     *  (e.g. on drag-end). Not part of the dev-facing API. */
    public function setValue(float $value): void
    {
        $this->value = $value;
    }

    /**
     * Wire format: `__sv:{id}` for a raw binding, or
     * `__sv:{id}|{op}:{args}|{op}:{args}…` for a formula chain.
     * Designed to fit in a plain string prop slot — no schema changes.
     */
    public function __toString(): string
    {
        $parts = ['__sv:'.$this->id];
        foreach ($this->formula as $step) {
            $parts[] = match ($step['op']) {
                'interp' => 'interp:'.implode(',', $step['in']).':'.implode(',', $step['out']),
                'clamp'  => 'clamp:'.$step['min'].','.$step['max'],
                'mul'    => 'mul:'.$step['by'],
                'add'    => 'add:'.$step['by'],
                default  => '',
            };
        }

        return implode('|', $parts);
    }

    private static function evalInterp(float $v, array $input, array $output): float
    {
        [$inLow, $inHigh] = [$input[0], $input[1]];
        [$outLow, $outHigh] = [$output[0], $output[1]];
        if ($v <= $inLow)  return $outLow;
        if ($v >= $inHigh) return $outHigh;
        $t = ($v - $inLow) / ($inHigh - $inLow);

        return $outLow + $t * ($outHigh - $outLow);
    }
}
