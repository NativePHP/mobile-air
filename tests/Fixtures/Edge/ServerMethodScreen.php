<?php

namespace Tests\Fixtures\Edge;

use Closure;
use Illuminate\View\View;
use Native\Mobile\Attributes\Server;
use Native\Mobile\Edge\Asgard\Concerns\UsesServer;
use Native\Mobile\Edge\Asgard\ServerResponse;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture for `#[Server]` methods
 * (docs/ASGARD-SERVER-METHODS.md §1).
 *
 * `refreshChart()` is the shape the doc describes: a client body that
 * sets a loading flag and an optimistic value, and a closure that runs
 * when the answer comes back. Everything the closure learns is recorded
 * on public props so a test can read it without reaching inside.
 */
class ServerMethodScreen extends NativeComponent
{
    use UsesServer;

    public bool $loading = false;

    public string $range = '7d';

    /** Stands in for a field the user can type into mid-flight. */
    public string $note = '';

    public string $total = '—';

    public string $error = '';

    /** An object prop, to pin down what "the value has not moved" means. */
    public ?\stdClass $box = null;

    // ── What the closure saw ────────────────────────

    public int $completions = 0;

    public int $closureThis = 0;

    public array $refused = [];

    public array $refusedFragments = [];

    public function mount(): void
    {
        $this->box = (object) ['value' => 1];
    }

    #[Server]
    public function refreshChart(string $range): Closure
    {
        // Runs on the CLIENT, before anything is sent.
        $this->loading = true;
        $this->range = $range;

        // Runs on the CLIENT, when the server answers.
        return function (ServerResponse $response) {
            $this->loading = false;
            $this->completions++;
            $this->closureThis = spl_object_id($this);
            $this->refused = array_keys($response->refused);
            $this->refusedFragments = array_keys($response->refusedFragments);

            if ($response->failed()) {
                $this->error = $response->error ?? 'Could not refresh.';
            }
        };
    }

    /** A `#[Server]` method with no continuation (§9.3 of the doc). */
    #[Server]
    public function ping(): void
    {
        $this->note = 'pinged';
    }

    /**
     * Marked, but named like a lifecycle hook. The attribute narrows what
     * an interaction may call; it must not widen it past the
     * ComponentMethodInvoker guard.
     */
    #[Server]
    public function dehydrateChart(): void
    {
        $this->note = 'dehydrated';
    }

    /** Not marked, so not callable through the server path (§8). */
    public function deleteAccount(): void
    {
        $this->note = 'deleted';
    }

    public function render(): View
    {
        return view('server-method-screen');
    }
}
