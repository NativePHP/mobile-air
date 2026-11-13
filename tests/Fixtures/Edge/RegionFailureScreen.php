<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Asgard\ServerRegionFailed;

/**
 * Two `@server` regions and an optional child with one of its own,
 * listening for failed regions: every one, and only those of `stats`.
 */
class RegionFailureScreen extends NativeComponent
{
    public bool $withChild = false;

    /** Seconds; null uses the config. */
    public ?float $timeout = null;

    /** @var list<array> payloads heard by the catch-all listener */
    public array $failures = [];

    /** @var list<bool> `placeholder` heard by the `stats` listener */
    public array $statsFailures = [];

    public function serverRegionTimeout(): float
    {
        return $this->timeout ?? parent::serverRegionTimeout();
    }

    #[On(ServerRegionFailed::class)]
    public function regionFailed(string $region, string $component, string $reason, ?int $status, string $requestId, bool $placeholder): void
    {
        $this->failures[] = compact('region', 'component', 'reason', 'status', 'requestId', 'placeholder');
    }

    #[On(ServerRegionFailed::class, when: ['region' => 'stats'])]
    public function statsFailed(bool $placeholder): void
    {
        $this->statsFailures[] = $placeholder;
    }

    public function retry(): void
    {
        $this->refreshServerRegions('stats');
    }

    public function render(): View
    {
        return view('region-failure-screen');
    }
}
