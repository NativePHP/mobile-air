<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture that listens through the legacy #[OnNative] attribute,
 * which needs Livewire and knows no `when` filter. A filtered #[On]
 * method sits next to its two methods, on exactly the same event.
 */
class LegacyListenersScreen extends NativeComponent
{
    /** @var list<string> */
    public array $log = [];

    #[OnNative(PingReceived::class)]
    public function updateBadge(string $message): void
    {
        $this->log[] = "updateBadge:{$message}";
    }

    #[OnNative(PingReceived::class)]
    public function reloadList(string $message): void
    {
        $this->log[] = "reloadList:{$message}";
    }

    #[On(PingReceived::class, when: ['message' => 'filtered'])]
    public function filteredPing(string $message): void
    {
        $this->log[] = "filteredPing:{$message}";
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('Log: '.implode(',', $this->log)));
    }
}
