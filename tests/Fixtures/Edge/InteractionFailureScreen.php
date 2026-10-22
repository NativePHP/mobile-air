<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

class InteractionFailureScreen extends NativeComponent
{
    public function onBackPressed(): void
    {
        $this->dispatch('interaction-failed');
    }

    #[Poll(0)]
    public function dispatchFromPoll(): void
    {
        $this->dispatch('interaction-failed');
    }

    #[On('interaction-failed')]
    public function failInteraction(): void
    {
        throw new \RuntimeException('Component-event listener failed');
    }

    public function hasErrorState(): bool
    {
        return $this->nativeHasError;
    }

    public function render(): Element
    {
        return Column::make(Text::make('Interaction failure probe'));
    }
}
