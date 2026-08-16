<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

class RouterBindingFailureScreen extends NativeComponent
{
    public static int $runCount = 0;

    public static string $intent = 'navigate';

    public function runLoop(): void
    {
        static::$runCount++;

        if (static::$runCount === 1) {
            static::$intent === 'replace'
                ? $this->replace('/binding-failure/missing')
                : $this->navigate('/binding-failure/missing');

            return;
        }

        $this->exitToWeb('/recovered');
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('Binding failure source'));
    }
}
