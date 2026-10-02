<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Attributes\Lazy;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\FakeBridge;

/**
 * A #[Lazy] screen whose mount() is slow. Native has already pushed the
 * placeholder, so the user can pop it while mount() is still running;
 * `$eventsDuringMount` are what native sends in that window.
 */
#[Lazy]
class SlowChatScreen extends NativeComponent
{
    /** @var list<array> */
    public static array $eventsDuringMount = [];

    /** @var list<string> */
    public static array $log = [];

    public function mount(): void
    {
        static::$log[] = 'mount';

        foreach (static::$eventsDuringMount as $event) {
            app(FakeBridge::class)->post($event);
        }

        // Only the first visit races; a later one mounts quietly.
        static::$eventsDuringMount = [];
    }

    #[On('ping')]
    public function ping(): void
    {
        static::$log[] = 'ping';
    }

    public function onBackPressed(): void
    {
        static::$log[] = 'back';

        parent::onBackPressed();
    }

    public function render(): Element
    {
        return $this->wrapWithChrome(Column::make(Text::make('Chat loaded')));
    }
}
