<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Top screen that performs an ordinary single back() — the control
 * case: even with a popTo-able URI below, plain back pops exactly one.
 */
class PlainBackScreen extends NativeComponent
{
    public static int $unmounts = 0;

    public static function resetCounters(): void
    {
        self::$unmounts = 0;
    }

    public function mount(): void
    {
        PopToTrace::record('plain-back.mount');
        $this->back();
    }

    public function unmount(): void
    {
        self::$unmounts++;
        PopToTrace::record('plain-back.unmount');
        parent::unmount();
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('plain back'));
    }
}
