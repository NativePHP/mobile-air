<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Device\ThermalStateChanged;
use Native\Mobile\ThermalState;

/**
 * Screen fixture for events that PHP fires with event(). Its methods
 * fire them the way an app does, and each #[On] listener appends
 * its own name to $log, so a test reads what ran and in order.
 */
class GlobalEventsScreen extends NativeComponent
{
    /** @var list<string> */
    public array $log = [];

    /**
     * Fires an event while the screen mounts, when its route names
     * an order to ship. The route /orders/{ship} of the tests
     * opens this same screen a second time, on top of it.
     */
    public function mount(): void
    {
        if ($this->param('ship') !== null) {
            event(new OrderShipped((int) $this->param('ship'), 'mounted'));
        }
    }

    // ── Firing ──────────────────────────────────────────

    #[On('ship-requested')]
    public function ship(int $orderId = 42, string $status = 'shipped'): void
    {
        event(new OrderShipped($orderId, $status));

        $this->log[] = 'ship:returned';
    }

    public function shipBurst(int $count): void
    {
        foreach (range(1, $count) as $orderId) {
            event(new OrderShipped($orderId, 'burst'));
        }
    }

    #[On('pair-requested')]
    public function shipPair(string $first): void
    {
        event(new OrderShipped(1, $first));
        event(new OrderShipped(2, 'shipped'));
    }

    public function pack(): void
    {
        event(new OrderPacked(42));
    }

    #[On('leave-requested')]
    public function shipThenLeave(): void
    {
        event(new OrderShipped(42, 'shipped'));

        $this->navigate('/orders/7');
    }

    // ── Listening ───────────────────────────────────────

    #[On(OrderShipped::class)]
    public function shipped(int $orderId, string $status): void
    {
        $this->log[] = "shipped:{$orderId}:{$status}";
    }

    #[On(OrderShipped::class, when: ['status' => 'relay'])]
    public function relay(int $orderId): void
    {
        event(new OrderDelivered($orderId));

        $this->log[] = 'relay:returned';
    }

    #[On(OrderShipped::class, when: ['status' => 'leave'])]
    public function leave(): void
    {
        $this->navigate('/orders/7');
    }

    #[On(OrderShipped::class, when: ['status' => 'explode'])]
    public function explode(): void
    {
        throw new \RuntimeException('Shipping failed');
    }

    /**
     * Fires the same event again, for the order after this one, so
     * that every turn of the screen leaves an event for the next.
     */
    #[On(OrderShipped::class, when: ['status' => 'again'])]
    public function again(int $orderId): void
    {
        event(new OrderShipped($orderId + 1, 'again'));
    }

    #[On(OrderDelivered::class, when: ['orderId' => 42])]
    public function delivered(int $orderId): void
    {
        $this->log[] = "delivered:{$orderId}";
    }

    /**
     * Answers a marked event with a component event, which
     * noted() hears once the flush has delivered it.
     */
    #[On(OrderDelivered::class, when: ['orderId' => 5])]
    public function note(int $orderId): void
    {
        $this->dispatch('order-noted', orderId: $orderId);
    }

    #[On('order-noted')]
    public function noted(int $orderId): void
    {
        $this->log[] = "noted:{$orderId}";
    }

    #[On(OrderPaid::class)]
    public function paid(int $orderId): void
    {
        $this->log[] = "paid:{$orderId}";
    }

    #[On(OrderReviewed::class)]
    public function reviewed(?int $orderId, ?string $comment, ?bool $recommended, ?float $stars): void
    {
        $this->log[] = 'reviewed:'.json_encode([$orderId, $comment, $recommended, $stars]);
    }

    #[On(OrderPacked::class)]
    public function packed(): void
    {
        $this->log[] = 'packed';
    }

    #[On(OrderChanged::class, when: ['order.id' => 42])]
    public function thisOrderChanged(): void
    {
        $this->log[] = 'thisOrderChanged';
    }

    #[On(OrderChanged::class, when: ['lines.0.sku' => 'A'])]
    public function firstLineIsA(): void
    {
        $this->log[] = 'firstLineIsA';
    }

    #[On(OrderRefunded::class)]
    public function refunded(Order $order): void
    {
        $this->log[] = "refunded:{$order->getKey()}:{$order->status}";
    }

    #[On(OrderAudited::class)]
    public function audited(int $orderId): void
    {
        $this->log[] = "audited:{$orderId}";
    }

    #[On(ThermalStateChanged::class)]
    public function thermal(ThermalState $state, string $previous): void
    {
        $this->log[] = "thermal:{$state->name}:{$previous}";
    }

    #[On(ThermalStateChanged::class, when: ['state' => ThermalState::Critical])]
    public function tooHot(): void
    {
        $this->log[] = 'tooHot';
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('Log: '.implode(',', $this->log)));
    }
}
