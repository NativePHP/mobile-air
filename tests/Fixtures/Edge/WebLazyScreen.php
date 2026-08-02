<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\Lazy;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * #[Lazy] fixture for the web boot flow: the placeholder GET must not
 * mount; the {type:'lazy'} update is the deferred initial mount and must
 * run it exactly once. `value` proves mount's state survives later
 * updates through the snapshot alone.
 */
#[Lazy]
class WebLazyScreen extends NativeComponent
{
    public static int $mounts = 0;

    public string $value = 'unset';

    public int $count = 0;

    public function mount(): void
    {
        static::$mounts++;
        $this->value = 'mounted-value';
    }

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): Element|View
    {
        return Column::make(
            Text::make("Value: {$this->value}"),
            Text::make("Count: {$this->count}"),
            Button::make('Increment')->onPress('increment'),
        );
    }
}
