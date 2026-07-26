<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Pressable;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Child fixture whose render() returns a programmatic Element tree
 * instead of a Blade view — its callbacks must still dispatch back to
 * this instance via the pinned registry.
 */
class ProgrammaticChild extends NativeComponent
{
    public int $taps = 0;

    public function tapIt(): void
    {
        $this->taps++;
    }

    public function render(): Element
    {
        return Pressable::make(Text::make("Programmatic taps: {$this->taps}"))
            ->ref('prog-tap')
            ->onPress('tapIt');
    }
}
