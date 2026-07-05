<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\SharedValue;

/**
 * Captures a pan / drag gesture and writes the translation to a bound
 * `SharedValue`. Per-frame values flow on the UI thread; PHP only
 * learns about the gesture via discrete callbacks.
 *
 * Children render normally — gesture detection wraps the whole content
 * frame.
 *
 *     $drag = SharedValue::make();
 *
 *     <native:gesture-area :pan-y="$drag" @drag-end="onRelease">
 *         <native:column :translate-y="$drag" ...>
 *             content
 *         </native:column>
 *     </native:gesture-area>
 *
 * On drag-end the native side fires `@drag-end` with `{value: float}`
 * in the payload, so PHP can decide commit / revert behavior.
 */
class GestureArea extends Element
{
    protected string $type = 'gesture_area';

    /** Initial value of the bound shared value (set at element build
     *  time so the renderer can seed its store before the gesture
     *  starts). */
    private ?int $panYId = null;

    private float $panYInitial = 0.0;

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
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = [];
        if ($this->panYId !== null) {
            $props['pan-y-id'] = $this->panYId;
            $props['pan-y-initial'] = $this->panYInitial;
        }

        return $props;
    }
}
