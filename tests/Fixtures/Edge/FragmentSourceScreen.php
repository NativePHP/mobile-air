<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Asgard\Concerns\UsesServer;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture for region requests: one `@server` region, a child
 * component with a region of its own, and public props of every shape
 * the JSON-safe state filter has to decide about.
 */
class FragmentSourceScreen extends NativeComponent
{
    use UsesServer;

    public string $range = '7d';

    public int $taps = 0;

    public ?string $note = null;

    public array $filters = ['region' => 'eu', 'tags' => ['a', 'b']];

    /** Not JSON: dropped from the request. */
    public float $ratio = NAN;

    /** An object, so dropped. */
    public ?\stdClass $box = null;

    /** An array holding an object, so dropped whole. */
    public array $mixed = [];

    public bool $withChild = false;

    public function mount(): void
    {
        $this->box = (object) ['value' => 1];
        $this->mixed = ['ok', new \stdClass];
    }

    public function tapped(): void
    {
        $this->taps++;
    }

    public function refresh(string $range): void
    {
        $this->range = $range;
        $this->refreshServerRegions();
    }

    public function refreshSummary(): void
    {
        $this->refreshServerRegions('sales-summary');
    }

    /** Draw one frame through the streaming path. */
    public function streamFrame(): void
    {
        $this->streamView('fragment-source-stream');
    }

    public function render(): View
    {
        return view('fragment-source-screen');
    }
}
