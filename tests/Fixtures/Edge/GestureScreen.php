<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\GestureArea;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\SharedValue;

/**
 * Fixture for the gesture-area discrete callbacks: three-finger swipe
 * (direction string) and pinch-end (final scale float).
 */
class GestureScreen extends NativeComponent
{
    public string $swiped = 'none';

    public float $zoom = 1.0;

    public function handleSwipe(string $direction): void
    {
        $this->swiped = $direction;
    }

    public function zoomEnded(float $scale): void
    {
        $this->zoom = $scale;
    }

    public function render(): Element|View
    {
        $pinch = SharedValue::make(1.0);

        $area = GestureArea::make()
            ->onSwipe('handleSwipe')
            ->onPinchEnd('zoomEnded')
            ->ref('gesture-surface');
        $area->applyAttributes([
            'pinch' => $pinch,
            'swipe-fingers' => 3,
        ]);
        $area->setProp('a11y_label', 'Gesture surface');

        $area->addChild(Text::make("Swiped: {$this->swiped} / Zoom: {$this->zoom}"));

        return Column::make($area);
    }
}
