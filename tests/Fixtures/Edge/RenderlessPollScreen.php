<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Attributes\Poll;
use Native\Mobile\Attributes\Renderless;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

class RenderlessPollScreen extends NativeComponent
{
    public int $ticks = 0;

    #[Poll(30000)]
    #[Renderless]
    protected function tickWithoutRendering(RenderlessPollDependency $dependency): void
    {
        $this->ticks += $dependency->amount;
    }

    public function render(): Element
    {
        return Column::make(Text::make("Ticks: {$this->ticks}"));
    }
}

class RenderlessPollDependency
{
    public int $amount = 3;
}
