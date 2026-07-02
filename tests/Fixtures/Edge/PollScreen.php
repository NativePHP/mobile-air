<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

class PollScreen extends NativeComponent
{
    public int $ticks = 0;

    public int $slowTicks = 0;

    #[Poll(1000)]
    public function tick(): void
    {
        $this->ticks++;
    }

    #[Poll(60000)]
    public function slowTick(): void
    {
        $this->slowTicks++;
    }

    public function render(): Element|View
    {
        return Column::make(
            Text::make("Ticks: {$this->ticks}"),
            Text::make("Slow: {$this->slowTicks}"),
        );
    }
}
