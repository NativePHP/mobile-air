<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Device\ThermalStateChanged;
use Native\Mobile\Support\NativeCallbacks;
use Native\Mobile\ThermalState;

/**
 * Screen fixture whose #[On] methods have a `when` filter.
 * Each listener appends its own name to $log, so a test
 * can read which listeners a payload got through to.
 */
class FilteredListenersScreen extends NativeComponent
{
    /** @var list<string> */
    public array $log = [];

    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    #[On('OrderShipped', when: ['orderId' => 42])]
    public function thisOrderShipped(int $orderId, string $status = ''): void
    {
        $this->log[] = "thisOrderShipped:{$status}";
    }

    #[On('ProgressMade', when: ['ratio' => 1.0])]
    public function done(): void
    {
        $this->log[] = 'done';
    }

    #[On('DraftSaved', when: ['id' => null])]
    public function unsavedDraft(): void
    {
        $this->log[] = 'unsavedDraft';
    }

    #[On('ListCleared', when: [])]
    public function emptyFilter(): void
    {
        $this->log[] = 'emptyFilter';
    }

    #[On('JobFinished', when: ['queue' => 'mail', 'failed' => true])]
    public function mailJobFailed(): void
    {
        $this->log[] = 'mailJobFailed';
    }

    #[On('MessageReceived', when: ['sender.id' => 7])]
    public function fromSeven(string $body = ''): void
    {
        $this->log[] = "fromSeven:{$body}";
    }

    #[On(ThermalStateChanged::class, when: ['state' => ThermalState::Critical])]
    public function tooHot(): void
    {
        $this->log[] = 'tooHot';
    }

    #[On(ThermalStateChanged::class, when: ['state' => 'normal'])]
    public function cooledDown(): void
    {
        $this->log[] = 'cooledDown';
    }

    /**
     * Listens for a marked event that nothing in Laravel listens for,
     * unlike ThermalStateChanged, which the package itself hears.
     * A test adds a listener of its own when it needs one.
     */
    #[On(OrderDelivered::class, when: ['orderId' => 42])]
    public function thisOrderDelivered(): void
    {
        $this->log[] = 'thisOrderDelivered';
    }

    #[On('LineReceived', when: ['alias' => 'queue'])]
    public function fromQueue(string $data = ''): void
    {
        $this->log[] = "fromQueue:{$data}";
    }

    #[On('LineReceived', when: ['alias' => 'encode'])]
    public function fromEncoder(string $data = ''): void
    {
        $this->log[] = "fromEncoder:{$data}";
    }

    #[On('TaskUpdated', when: ['urgent' => true])]
    public function urgentTask(): void
    {
        $this->log[] = 'urgentTask';
    }

    #[On('TaskUpdated')]
    public function everyTask(): void
    {
        $this->log[] = 'everyTask';
    }

    #[On('StockChanged', when: ['sku' => 'A'])]
    #[On('StockChanged', when: ['low' => true])]
    public function restock(string $sku = ''): void
    {
        $this->log[] = "restock:{$sku}";
    }

    #[On(PingReceived::class, when: ['message' => 'filtered'])]
    public function filteredPing(): void
    {
        $this->log[] = 'filteredPing';
    }

    /**
     * Registers a one-shot fluent callback for PingReceived in
     * the shape the Pending* builders use. It fires for any
     * payload, whatever the filter of filteredPing says.
     */
    public function awaitPing(): void
    {
        NativeCallbacks::register(
            'ping-capture',
            PingReceived::class,
            function ($event) {
                $this->log[] = "callback:{$event->message}";
            }
        );
    }

    #[On('report-ready', when: ['status' => 'failed'])]
    public function reportFailed(?string $message = null): void
    {
        $this->log[] = "reportFailed:{$message}";
    }

    public function render(): Element|View
    {
        return Column::make(
            Text::make('Log: '.implode(',', $this->log)),
            Text::make("Count: {$this->count}"),
            Button::make('Increment')->onPress('increment'),
        );
    }
}
