<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/** Pushed screen: optionally replaces itself with a fresh mount of its own URI, then goes back. */
class ScreenTokenDetailScreen extends NativeComponent
{
    public function mount(): void
    {
        ScreenTokenTrace::record('detail.mount', $this->screenToken(), $this->nativeRouter?->stackScreenTokens() ?? []);

        if (ScreenTokenTrace::$replaceDetailOnce) {
            ScreenTokenTrace::$replaceDetailOnce = false;
            $this->replace('/tokens/detail');

            return;
        }

        $this->back();
    }

    public function render(): Element
    {
        return Column::make(Text::make('Detail'));
    }
}
