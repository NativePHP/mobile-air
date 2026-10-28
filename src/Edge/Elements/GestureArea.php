<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\SharedValue;

/**
 * Captures touch gestures over its content frame and either writes
 * per-frame values to a bound `SharedValue` (continuous gestures) or
 * fires a discrete callback into PHP (one-shot gestures). Children
 * render normally — gesture detection wraps the whole content frame.
 *
 * Pan — drag translation into a SharedValue, one per axis. Bind either
 * or both; an unbound axis drives nothing. `@dragEnd` fires when the
 * finger lifts, with the final translation of each axis as two floats
 * (a bound axis reports its SharedValue, an unbound one the raw gesture
 * translation), so PHP can decide what the release means — a
 * swipe-to-decide card, a snap-to-position, a dismiss threshold:
 *
 *     $dx = SharedValue::make();
 *
 *     <native:gesture-area :pan-x="$dx" @dragEnd="onRelease">
 *         <native:column :translate-x="$dx" :rotate="$dx->interpolate([-300, 300], [-15, 15])">
 *             content
 *         </native:column>
 *     </native:gesture-area>
 *
 *     public function onRelease(float $x, float $y): void
 *
 * Per-frame values never reach PHP. A fresh `SharedValue::make()` each
 * render re-seeds the store from the initial, so a re-render after
 * `@dragEnd` puts the content back at rest without any write-back.
 *
 * Pinch — zoom scale factor into a SharedValue (1.0 = identity). The
 * optional `pinch-min` / `pinch-max` bounds clamp the value AT THE
 * SOURCE, per gesture step — without them the raw value compounds
 * unbounded past any display clamp, and reversing direction has to
 * unwind the overshoot before anything visibly moves. The optional
 * `@pinchEnd` callback fires when the fingers lift, with the final
 * (bounded) scale as a float argument:
 *
 *     $zoom = SharedValue::make(1.0);
 *
 *     <native:gesture-area :pinch="$zoom" pinch-min="0.5" pinch-max="3" @pinchEnd="onZoomEnd">
 *         <native:image :scale="$zoom" ... />
 *     </native:gesture-area>
 *
 * Bounded — a zoomable picture that can't be lost off screen. With
 * `bounded`, pan stops where the zoomed content's edges meet the area's
 * edges, and an axis on which the zoomed content fits stays centred, so
 * at 1x the content can't be moved at all. A drag past an edge moves
 * with resistance and springs back into place when released, and so
 * does a pinch that ends with the content out of place. A pinch zooms
 * around the point between the fingers. The content is the area itself,
 * or, with `content-ratio` (width over height), that ratio fitted
 * inside the area, which is where an image with `fit="contain"` draws.
 * Bind both pan axes and the pinch, and bind the content's transform to
 * the same values:
 *
 *     <native:gesture-area :pinch="$zoom" pinch-min="1" pinch-max="4" :pan-x="$x" :pan-y="$y"
 *         bounded :content-ratio="$width / $height">
 *         <native:image :scale="$zoom" :translate-x="$x" :translate-y="$y" fit="contain" ... />
 *     </native:gesture-area>
 *
 * Snapping — a pan that settles on set points, the swipe-to-reveal
 * pattern. With `snap-x` (or `snap-y`) the bound axis moves with the
 * finger between the lowest and highest point, with growing resistance
 * past them, and on release springs to the point nearest where the
 * fling would carry it. `@dragEnd` reports the point it settles on, so
 * PHP learns the outcome without deciding it. Setting the SharedValue
 * from PHP (`setValue()`) on a snapping axis springs it there too, the
 * way a tap elsewhere closes a revealed row:
 *
 *     $dx = SharedValue::make();   // kept on the component across renders
 *
 *     <native:gesture-area :pan-x="$dx" :snap-x="[-96, 0]" @dragEnd="settled">
 *         <native:row :translate-x="$dx">…</native:row>
 *     </native:gesture-area>
 *
 *     public function settled(float $x, float $y): void   // $x is -96 or 0
 *
 * A pan bound on one axis only takes drags along that axis, so an
 * enclosing scroll view still scrolls the other way.
 *
 * Swipe — discrete directional flick. `swipe-fingers` sets how many
 * touches must participate (e.g. 3 for a Jump-style three-finger
 * swipe; defaults to 1). The callback receives the direction as a
 * string: "left", "right", "up" or "down":
 *
 *     <native:gesture-area @swipe="onSwipe" swipe-fingers="3">
 *         content
 *     </native:gesture-area>
 *
 * Per-frame values flow on the UI thread; PHP only learns about a
 * gesture via the discrete callbacks.
 */
class GestureArea extends Element
{
    protected string $type = 'gesture_area';

    /** Initial value of the bound shared value (set at element build
     *  time so the renderer can seed its store before the gesture
     *  starts). */
    private ?int $panXId = null;

    private float $panXInitial = 0.0;

    private ?int $panYId = null;

    private float $panYInitial = 0.0;

    private ?int $pinchId = null;

    private float $pinchInitial = 1.0;

    /** Source-side scale bounds. Null = unbounded on that side. */
    private ?float $pinchMin = null;

    private ?float $pinchMax = null;

    private int $swipeFingers = 1;

    /** The points a snapping pan settles on, per axis (see the class
     *  docblock). Null = the pan rests wherever the finger leaves it. */
    private ?array $snapX = null;

    private ?array $snapY = null;

    /** Keep the zoomed content inside the area's edges. */
    private bool $bounded = false;

    /** The content's width over height, fitted inside the area. Null =
     *  the content fills the area. */
    private ?float $contentRatio = null;

    protected ?string $swipeMethod = null;

    protected ?string $pinchEndMethod = null;

    protected ?string $dragEndMethod = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['pan-x']) && $attrs['pan-x'] instanceof SharedValue) {
            $this->panXId = $attrs['pan-x']->id;
            $this->panXInitial = $attrs['pan-x']->value();
        }

        if (isset($attrs['pan-y']) && $attrs['pan-y'] instanceof SharedValue) {
            $this->panYId = $attrs['pan-y']->id;
            $this->panYInitial = $attrs['pan-y']->value();
        }

        if (isset($attrs['pinch']) && $attrs['pinch'] instanceof SharedValue) {
            $this->pinchId = $attrs['pinch']->id;
            $this->pinchInitial = $attrs['pinch']->value();
        }

        if (isset($attrs['pinch-min'])) {
            $this->pinchMin = (float) $attrs['pinch-min'];
        }
        if (isset($attrs['pinch-max'])) {
            $this->pinchMax = (float) $attrs['pinch-max'];
        }

        if (isset($attrs['snap-x'])) {
            $this->snapX(self::points($attrs['snap-x']));
        }

        if (isset($attrs['snap-y'])) {
            $this->snapY(self::points($attrs['snap-y']));
        }

        if (isset($attrs['swipe-fingers'])) {
            $this->swipeFingers = max(1, (int) $attrs['swipe-fingers']);
        }

        if (isset($attrs['bounded'])) {
            $this->bounded = filter_var($attrs['bounded'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($attrs['content-ratio'])) {
            $this->contentRatio((float) $attrs['content-ratio']);
        }
    }

    /** Keep the zoomed content inside the area's edges (see the class
     *  docblock). */
    /** Points the horizontal pan settles on (see the class docblock).
     *  Fewer than two points means no snapping. */
    public function snapX(array $points): static
    {
        $this->snapX = self::sortedPoints($points);

        return $this;
    }

    /** Points the vertical pan settles on. */
    public function snapY(array $points): static
    {
        $this->snapY = self::sortedPoints($points);

        return $this;
    }

    /**
     * @return list<float>|null
     */
    private static function sortedPoints(array $points): ?array
    {
        $points = array_values(array_unique(array_map(floatval(...), $points), SORT_REGULAR));
        sort($points);

        return count($points) >= 2 ? $points : null;
    }

    /**
     * Snap points from Blade: an array, or a comma-separated string.
     *
     * @return list<mixed>
     */
    private static function points(mixed $value): array
    {
        return is_array($value) ? $value : array_filter(array_map(trim(...), explode(',', (string) $value)), fn (string $point): bool => $point !== '');
    }

    public function bounded(bool $bounded = true): static
    {
        $this->bounded = $bounded;

        return $this;
    }

    /** The content's width over height, fitted inside the area, for
     *  `bounded`. Zero or less means the content fills the area. */
    public function contentRatio(float $ratio): static
    {
        $this->contentRatio = $ratio > 0 ? $ratio : null;

        return $this;
    }

    /** Swipe handler. Receives the direction ("left" / "right" / "up" /
     *  "down") as a string argument. */
    public function onSwipe(string $method): static
    {
        $this->swipeMethod = $method;

        return $this;
    }

    /** Pinch-end handler. Receives the final scale factor as a float. */
    public function onPinchEnd(string $method): static
    {
        $this->pinchEndMethod = $method;

        return $this;
    }

    /** Drag-end handler. Receives the final pan translation as two
     *  floats, `(x, y)` in points: a bound axis reports its SharedValue,
     *  an unbound one the raw gesture translation. */
    public function onDragEnd(string $method): static
    {
        $this->dragEndMethod = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = [];
        if ($this->panXId !== null) {
            $props['pan-x-id'] = $this->panXId;
            $props['pan-x-initial'] = $this->panXInitial;

            // A comma-separated string, so both platforms read it with
            // their plain string getter.
            if ($this->snapX !== null) {
                $props['pan-x-snap'] = implode(',', $this->snapX);
            }
        }

        if ($this->panYId !== null) {
            $props['pan-y-id'] = $this->panYId;
            $props['pan-y-initial'] = $this->panYInitial;

            if ($this->snapY !== null) {
                $props['pan-y-snap'] = implode(',', $this->snapY);
            }
        }

        if ($this->pinchId !== null) {
            $props['pinch-id'] = $this->pinchId;
            $props['pinch-initial'] = $this->pinchInitial;
        }

        // Bounds ride independently of the SharedValue binding — a
        // callback-only pinch (`@pinchEnd` without `:pinch`) still
        // wants its running scale bounded. 0 on the wire = unbounded
        // (scale is always positive, so 0 is a safe sentinel).
        if ($this->pinchMin !== null) {
            $props['pinch-min'] = $this->pinchMin;
        }
        if ($this->pinchMax !== null) {
            $props['pinch-max'] = $this->pinchMax;
        }

        if ($this->bounded) {
            $props['bounded'] = true;

            if ($this->contentRatio !== null) {
                $props['content-ratio'] = $this->contentRatio;
            }
        }

        if ($this->swipeMethod !== null) {
            // Fired natively via the TEXT_CHANGE event format (the C
            // extension parses payloads by event type), carrying the
            // direction string — no wire-format change needed.
            $props['on_swipe'] = $registry->register($this->swipeMethod);
            $props['swipe-fingers'] = $this->swipeFingers;
        }

        if ($this->pinchEndMethod !== null) {
            // Rides the SLIDER_CHANGE event format: float payload = the
            // final scale factor.
            $props['on_pinch_end'] = $registry->register($this->pinchEndMethod);
        }

        if ($this->dragEndMethod !== null) {
            // Rides the TEXT_CHANGE event format as "x,y"; the `drag_end`
            // kind tells dispatch to decode it into two floats.
            $props['on_drag_end'] = $registry->register($this->dragEndMethod, 'drag_end');
        }

        return $props;
    }
}
