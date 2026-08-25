<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

class ThrowingPollScreen extends NativeComponent
{
    public static int $ticks = 0;

    #[Poll(0)]
    public function explode(): void
    {
        static::$ticks++;
        throw new \RuntimeException('poll blew up');
    }

    public function render(): Element
    {
        return Column::make(Text::make('Throwing poll'));
    }
}
