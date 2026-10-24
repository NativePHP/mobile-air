<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture with several #[On] methods per native event.
 * Each listener appends its own name to $log, so a test
 * can read which listeners ran and in which order.
 */
class SeveralListenersScreen extends NativeComponent
{
    /** @var list<string> */
    public array $log = [];

    /** @var array<string, mixed> what the listeners for a reading were given */
    public array $reading = [];

    #[On(PingReceived::class)]
    public function updateBadge(string $message): void
    {
        $this->log[] = "updateBadge:{$message}";
    }

    #[On(PingReceived::class)]
    public function reloadList(string $message): void
    {
        $this->log[] = "reloadList:{$message}";
    }

    #[On('ReadingReceived')]
    public function showCount(int $count): void
    {
        $this->reading += compact('count');
    }

    #[On('ReadingReceived')]
    public function showLabel(string $label, bool $enabled): void
    {
        $this->reading += compact('label', 'enabled');
    }

    #[On('PartialReadingReceived')]
    public function showPartial(?int $count, ?string $label, ?bool $enabled, ?float $level): void
    {
        $this->reading += compact('count', 'label', 'enabled', 'level');
    }

    #[On('DoorOpened')]
    #[On('DoorClosed')]
    public function trackDoor(string $state): void
    {
        $this->log[] = "trackDoor:{$state}";
    }

    #[OnSignal('SignalReceived')]
    public function plotSignal(): void
    {
        $this->log[] = 'plotSignal';
    }

    #[OnSignal('SignalReceived')]
    public function storeSignal(): void
    {
        $this->log[] = 'storeSignal';
    }

    #[On('SessionExpired')]
    public function leaveScreen(): void
    {
        $this->log[] = 'leaveScreen';

        $this->navigate('/login');
    }

    #[On('SessionExpired')]
    public function clearDraft(): void
    {
        $this->log[] = 'clearDraft';
    }

    #[On('SyncFailed')]
    public function beforeFailure(): void
    {
        $this->log[] = 'beforeFailure';
    }

    #[On('SyncFailed')]
    public function raiseFailure(): void
    {
        throw new \RuntimeException('Listener failed');
    }

    #[On('SyncFailed')]
    public function afterFailure(): void
    {
        $this->log[] = 'afterFailure';
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('Log: '.implode(',', $this->log)));
    }
}
