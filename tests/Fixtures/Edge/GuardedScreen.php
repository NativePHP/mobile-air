<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Records whether it ever mounted, so a guarded navigation can assert the
 * screen performed none of its data loading before being turned away.
 */
class GuardedScreen extends NativeComponent
{
    public static int $mounts = 0;

    public static function reset(): void
    {
        static::$mounts = 0;
    }

    public function mount(): void
    {
        static::$mounts++;
    }

    public function render(): Element
    {
        return Column::make(
            Text::make('Guarded screen content'),
        );
    }
}
