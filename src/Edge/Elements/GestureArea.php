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
 * Zoom — a pinch writes its scale factor into a SharedValue (1.0 =
 * identity). The optional `zoom-min` / `zoom-max` bounds clamp the value
 * AT THE SOURCE, per gesture step — without them the raw value compounds
 * unbounded past any display clamp, and reversing direction has to
 * unwind the overshoot before anything visibly moves. The optional
 * `@zoomEnd` callback fires when a zoom finishes, with the final
 * (bounded) scale as a float argument:
 *
 *     $zoom = SharedValue::make(1.0);
 *
 *     <native:gesture-area :zoom="$zoom" zoom-min="0.5" zoom-max="3" @zoomEnd="onZoomEnd">
 *         <native:image :scale="$zoom" ... />
 *     </native:gesture-area>
 *
 * `pinch`, `pinch-min`, `pinch-max` and `@pinchEnd` are older names for
 * the same four attributes, and keep working.
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
 * Bind both pan axes and the zoom, and bind the content's transform to
 * the same values:
 *
 *     <native:gesture-area :zoom="$zoom" zoom-min="1" zoom-max="4" :pan-x="$x" :pan-y="$y"
 *         bounded :content-ratio="$width / $height">
 *         <native:image :scale="$zoom" :translate-x="$x" :translate-y="$y" fit="contain" ... />
 *     </native:gesture-area>
 *
 * Double tap to zoom — `@doubleTap.zoom="2"` zooms content at its whole
 * size to that scale on a double tap, bringing the tapped point to the
 * middle of the area (as far as `bounded` lets it go), and a double tap
 * on zoomed content takes it back to its whole size. The `.zoom`
 * modifier makes the value the scale rather than a handler: the phone
 * zooms on its own and PHP isn't asked. Both ways animate, and
 * `@zoomEnd` fires with the new scale once the zoom settles, as it does
 * after a pinch, so one handler hears every zoom.
 *
 * Aiming a zoom moves the pan, so the zoom needs the pan's SharedValues
 * even while the area isn't panning. A photo pager binds `pan-x` and
 * `pan-y` only once the picture is zoomed, because a bound pan takes
 * every one-finger drag and the pager would stop paging. `zoom-pan-x`
 * and `zoom-pan-y` give the zoom those same values without taking any
 * drags: a pinch keeps the point between the fingers where it is, and a
 * double tap aims at the tapped point, while drags at whole size are
 * left to whatever is around the area. Once `pan-x` / `pan-y` are bound
 * as well, to the same values, everything carries on from where the zoom
 * left it:
 *
 *     <native:gesture-area :zoom="$zoom" zoom-min="1" zoom-max="4" @doubleTap.zoom="2"
 *         :pan-x="$zoomed ? $x : null" :pan-y="$zoomed ? $y : null" :zoom-pan-x="$x" :zoom-pan-y="$y"
 *         bounded :content-ratio="$ratio" @zoomEnd="zoomEnded">
 *         <native:image :scale="$zoom" :translate-x="$x" :translate-y="$y" fit="contain" ... />
 *     </native:gesture-area>
 *
 *     public function zoomEnded(float $scale): void   // $zoomed = $scale > 1
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
 * Trigger — a pull that acts when let go far enough, the swipe-to-reply
 * pattern. With `trigger-x` (or `trigger-y`) the bound axis moves with
 * the finger from where it rests toward the trigger point only, as far
 * as the point and then with growing resistance, and springs back to
 * rest on release. The point is a distance from rest, and its sign is
 * the way the pull goes. Each time the pull goes out past the point a
 * light haptic plays; let go past it and `@trigger` fires, with no
 * arguments. Drive whatever comes in behind from the same SharedValue,
 * so it follows the finger and is in place at the point:
 *
 *     $dx = SharedValue::make();   // kept on the component across renders
 *
 *     <native:gesture-area :pan-x="$dx" trigger-x="64" @trigger="reply(5)">
 *         <native:icon … :opacity="$dx->interpolate([0, 64], [0, 1])" />
 *         <native:row :translate-x="$dx">…</native:row>
 *     </native:gesture-area>
 *
 * The pull only starts when the finger moves the trigger's way, so a
 * drag the other way is left to whatever else wants it, such as iOS's
 * swipe back. A trigger needs a pan bound on its axis alone, and takes
 * the place of snap points there.
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

    /** How far from rest a pull on each axis goes to trigger, and which
     *  way (see the class docblock). Null = the axis doesn't trigger. */
    private ?float $triggerX = null;

    private ?float $triggerY = null;

    /** The scale a double tap zooms to (see the class docblock). Null =
     *  a double tap does nothing. */
    private ?float $doubleTapZoom = null;

    /** The pan a zoom moves when the area isn't panning, per axis (see
     *  the class docblock). */
    private ?int $zoomPanXId = null;

    private float $zoomPanXInitial = 0.0;

    private ?int $zoomPanYId = null;

    private float $zoomPanYInitial = 0.0;

    /** Keep the zoomed content inside the area's edges. */
    private bool $bounded = false;

    /** The content's width over height, fitted inside the area. Null =
     *  the content fills the area. */
    private ?float $contentRatio = null;

    protected ?string $swipeMethod = null;

    protected ?string $pinchEndMethod = null;

    protected ?string $dragEndMethod = null;

    protected ?string $triggerMethod = null;

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

        // `zoom` and `pinch` are two names for one binding (see the class
        // docblock); the wire keeps the pinch names. Whichever is a
        // SharedValue wins, so a stray `zoom` can't hide a bound `pinch`.
        foreach (['zoom', 'pinch'] as $name) {
            if (($attrs[$name] ?? null) instanceof SharedValue) {
                $this->pinchId = $attrs[$name]->id;
                $this->pinchInitial = $attrs[$name]->value();

                break;
            }
        }

        if (isset($attrs['zoom-pan-x']) && $attrs['zoom-pan-x'] instanceof SharedValue) {
            $this->zoomPanX($attrs['zoom-pan-x']);
        }

        if (isset($attrs['zoom-pan-y']) && $attrs['zoom-pan-y'] instanceof SharedValue) {
            $this->zoomPanY($attrs['zoom-pan-y']);
        }

        // `@doubleTap.zoom="2"`, which the precompiler leaves as this attr
        // rather than a handler.
        if (isset($attrs['_doubleTap.zoom'])) {
            $this->doubleTapZoom((float) $attrs['_doubleTap.zoom']);
        }

        $min = $attrs['zoom-min'] ?? $attrs['pinch-min'] ?? null;
        if ($min !== null) {
            $this->pinchMin = (float) $min;
        }
        $max = $attrs['zoom-max'] ?? $attrs['pinch-max'] ?? null;
        if ($max !== null) {
            $this->pinchMax = (float) $max;
        }

        if (isset($attrs['snap-x'])) {
            $this->snapX(self::points($attrs['snap-x']));
        }

        if (isset($attrs['snap-y'])) {
            $this->snapY(self::points($attrs['snap-y']));
        }

        if (isset($attrs['trigger-x'])) {
            $this->triggerX((float) $attrs['trigger-x']);
        }

        if (isset($attrs['trigger-y'])) {
            $this->triggerY((float) $attrs['trigger-y']);
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

    /** How far from rest a horizontal pull goes to trigger, negative for
     *  a pull to the left (see the class docblock). Zero means none. */
    public function triggerX(float $distance): static
    {
        $this->triggerX = $distance != 0.0 ? $distance : null;

        return $this;
    }

    /** How far from rest a vertical pull goes to trigger, negative for a
     *  pull upward. */
    public function triggerY(float $distance): static
    {
        $this->triggerY = $distance != 0.0 ? $distance : null;

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

    /** The scale a double tap zooms to, from content at its whole size:
     *  `@doubleTap.zoom` in Blade (see the class docblock). One or less
     *  means a double tap does nothing. */
    public function doubleTapZoom(float $scale): static
    {
        $this->doubleTapZoom = $scale > 1 ? $scale : null;

        return $this;
    }

    /** The horizontal pan a pinch or a double tap moves to aim the zoom,
     *  without the area taking drags (see the class docblock). */
    public function zoomPanX(SharedValue $value): static
    {
        $this->zoomPanXId = $value->id;
        $this->zoomPanXInitial = $value->value();

        return $this;
    }

    /** The vertical pan a pinch or a double tap moves to aim the zoom. */
    public function zoomPanY(SharedValue $value): static
    {
        $this->zoomPanYId = $value->id;
        $this->zoomPanYInitial = $value->value();

        return $this;
    }

    /** Keep the zoomed content inside the area's edges (see the class
     *  docblock). */
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

    /** Zoom-end handler, run when a pinch or a double tap's zoom
     *  finishes. Receives the final scale factor as a float. */
    public function onZoomEnd(string $method): static
    {
        $this->pinchEndMethod = $method;

        return $this;
    }

    /** The older name of onZoomEnd(). */
    public function onPinchEnd(string $method): static
    {
        return $this->onZoomEnd($method);
    }

    /** Drag-end handler. Receives the final pan translation as two
     *  floats, `(x, y)` in points: a bound axis reports its SharedValue,
     *  an unbound one the raw gesture translation. */
    public function onDragEnd(string $method): static
    {
        $this->dragEndMethod = $method;

        return $this;
    }

    /** Trigger handler, fired with no arguments when a pull is let go
     *  past its trigger point (see `triggerX()`). */
    public function onTrigger(string $method): static
    {
        $this->triggerMethod = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = [];
        if ($this->panXId !== null) {
            $props['pan-x-id'] = $this->panXId;
            $props['pan-x-initial'] = $this->panXInitial;

            // A trigger axis always springs back to rest, so it doesn't
            // snap. Snap points go as a comma-separated string, so both
            // platforms read them with their plain string getter.
            if ($this->triggerX !== null) {
                $props['pan-x-trigger'] = $this->triggerX;
            } elseif ($this->snapX !== null) {
                $props['pan-x-snap'] = implode(',', $this->snapX);
            }
        }

        if ($this->panYId !== null) {
            $props['pan-y-id'] = $this->panYId;
            $props['pan-y-initial'] = $this->panYInitial;

            if ($this->triggerY !== null) {
                $props['pan-y-trigger'] = $this->triggerY;
            } elseif ($this->snapY !== null) {
                $props['pan-y-snap'] = implode(',', $this->snapY);
            }
        }

        if ($this->pinchId !== null) {
            $props['pinch-id'] = $this->pinchId;
            $props['pinch-initial'] = $this->pinchInitial;
        }

        // The zoom's own pan. Seeded like a pan, so a zoom aimed before
        // anything else has touched the value starts from where PHP put it.
        if ($this->zoomPanXId !== null) {
            $props['zoom-pan-x-id'] = $this->zoomPanXId;
            $props['zoom-pan-x-initial'] = $this->zoomPanXInitial;
        }

        if ($this->zoomPanYId !== null) {
            $props['zoom-pan-y-id'] = $this->zoomPanYId;
            $props['zoom-pan-y-initial'] = $this->zoomPanYInitial;
        }

        if ($this->doubleTapZoom !== null) {
            $props['double-tap-zoom'] = $this->doubleTapZoom;
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

        if ($this->triggerMethod !== null) {
            // Rides the PRESS event format, which carries nothing.
            $props['on_trigger'] = $registry->register($this->triggerMethod);
        }

        return $props;
    }
}
