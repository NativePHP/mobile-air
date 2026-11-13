<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\NativeAction;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Server;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Asgard\ServerRegionFailed;

/**
 * A screen with an `@server` region and a `#[Server]` method that does
 * not use UsesServer. The region must still work. The method is a
 * mistake the framework reports.
 */
class TraitlessServerScreen extends NativeComponent
{
    public int $refreshes = 0;

    public string $note = '';

    /** @var list<string> reasons heard for failed regions */
    public array $failures = [];

    #[NativeAction]
    public function refresh(): void
    {
        $this->refreshes++;
    }

    #[Server]
    public function ping(): void
    {
        $this->note = 'pinged';
    }

    #[On(ServerRegionFailed::class)]
    public function regionFailed(string $reason): void
    {
        $this->failures[] = $reason;
    }

    public function render(): View
    {
        return view('server-region-screen');
    }
}
