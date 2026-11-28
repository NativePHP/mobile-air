<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/** Root screen: pushes the detail screen on mount, leaves the router once resumed. */
class ScreenTokenListScreen extends NativeComponent
{
    public function mount(): void
    {
        ScreenTokenTrace::record('list.mount', $this->screenToken(), $this->nativeRouter?->stackScreenTokens() ?? []);
        $this->navigate('/tokens/detail');
    }

    public function onResume(): void
    {
        ScreenTokenTrace::record('list.resume', $this->screenToken(), $this->nativeRouter?->stackScreenTokens() ?? []);
        $this->exitToWeb('/tokens-done');
    }

    public function render(): Element
    {
        return Column::make(Text::make('List'));
    }
}
