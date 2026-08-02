<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Fixture for the web update endpoint. mount() bumps a static counter so
 * tests can pin the mount-once contract: updates rehydrate from the
 * sealed snapshot and must never re-run mount().
 */
class WebProbeScreen extends NativeComponent
{
    public static int $mounts = 0;

    public int $count = 0;

    public int $ticks = 0;

    public function mount(): void
    {
        static::$mounts++;
    }

    public function increment(): void
    {
        $this->count++;
    }

    #[Poll(500)]
    public function tick(): void
    {
        $this->ticks++;
    }

    #[Computed]
    public function doubled(): int
    {
        return $this->count * 2;
    }

    public function render(): Element|View
    {
        return Column::make(
            Text::make("Count: {$this->count}"),
            Text::make("Doubled: {$this->doubled}"),
            Text::make("Ticks: {$this->ticks}"),
            Button::make('Increment')->onPress('increment'),
        );
    }
}
