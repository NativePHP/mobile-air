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
 * Pan — drag translation into a SharedValue:
 *
 *     $drag = SharedValue::make();
 *
 *     <native:gesture-area :pan-y="$drag" @drag-end="onRelease">
 *         <native:column :translate-y="$drag" ...>
 *             content
 *         </native:column>
 *     </native:gesture-area>
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
    private ?int $panYId = null;

    private float $panYInitial = 0.0;

    private ?int $pinchId = null;

    private float $pinchInitial = 1.0;

    /** Source-side scale bounds. Null = unbounded on that side. */
    private ?float $pinchMin = null;

    private ?float $pinchMax = null;

    private int $swipeFingers = 1;

    protected ?string $swipeMethod = null;

    protected ?string $pinchEndMethod = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
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

        if (isset($attrs['swipe-fingers'])) {
            $this->swipeFingers = max(1, (int) $attrs['swipe-fingers']);
        }
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

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = [];
        if ($this->panYId !== null) {
            $props['pan-y-id'] = $this->panYId;
            $props['pan-y-initial'] = $this->panYInitial;
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

        return $props;
    }
}
