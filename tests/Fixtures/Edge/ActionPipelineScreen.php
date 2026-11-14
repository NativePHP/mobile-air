<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Enums\ActionSource;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture for the action pipeline. It overrides callAction() to
 * record every framework-initiated call, and can refuse to delegate — which
 * is how the tests prove there is no route to a handler that bypasses the
 * chokepoint.
 */
class ActionPipelineScreen extends NativeComponent
{
    /** @var list<array{0:string,1:string,2:array}> [source, method, args], in order */
    public array $actions = [];

    /** When true, callAction() records the call but does not run the method. */
    public bool $blockActions = false;

    /**
     * When true, callAction() calls the method itself instead of calling
     * parent, the way a careless override would.
     */
    public bool $bypassParent = false;

    /** @var list<string> the handlers that actually ran */
    public array $ran = [];

    public int $count = 0;

    protected function callAction(string $method, array $args, ActionSource $source): mixed
    {
        $this->actions[] = [$source->value, $method, $args];

        if ($this->blockActions) {
            return null;
        }

        if ($this->bypassParent) {
            return $this->{$method}(...$args);
        }

        return parent::callAction($method, $args, $source);
    }

    /** Every ActionSource seen so far, in order. */
    public function sources(): array
    {
        return array_column($this->actions, 0);
    }

    // ── the developer's handlers ────────────────────

    public function bump(): void
    {
        $this->ran[] = 'bump';
        $this->count++;
    }

    public function onSearchQuery(string $query): array
    {
        $this->ran[] = 'onSearchQuery';

        return ['hit:'.$query];
    }

    public function loadRange(string $tag, int $from, int $to): void
    {
        $this->ran[] = "loadRange:{$tag}:{$from}-{$to}";
    }

    public function selectionChanged(string $tag, string $text, int $start, int $end): void
    {
        $this->ran[] = "selectionChanged:{$tag}:{$text}:{$start}-{$end}";
    }

    /** Matches the hydrate* lifecycle pattern, so the guard must refuse it although it is public. */
    public function hydrateThing(): void
    {
        $this->ran[] = 'hydrateThing';
    }

    public function dragged(string $tag, float $x, float $y): void
    {
        $this->ran[] = "dragged:{$tag}:{$x},{$y}";
    }

    /** Not an action. Nothing a template or the device names may reach it. */
    protected function secret(mixed ...$args): void
    {
        $this->ran[] = 'secret';
    }

    /** Attribute-declared, so it may stay protected and still run. */
    #[Poll(5000)]
    protected function protectedTick(): void
    {
        $this->ran[] = 'protectedTick';
    }

    #[Poll(1000)]
    public function tick(): void
    {
        $this->ran[] = 'tick';
    }

    #[On('pinged')]
    public function onPinged(string $who): void
    {
        $this->ran[] = 'onPinged:'.$who;
    }

    #[On('scalar-ping')]
    public function onScalarPing(mixed $payload): void
    {
        $this->ran[] = 'onScalarPing:'.$payload;
    }

    #[On('child-said')]
    public function onChildSaid(string $word): void
    {
        $this->ran[] = 'onChildSaid:'.$word;
    }

    public function markSaved(string $bound, string $word): void
    {
        $this->ran[] = "markSaved:{$bound}:{$word}";
    }

    public function render(): View
    {
        return view('action-pipeline-screen');
    }
}
