<?php

namespace Native\Mobile\Edge\Asgard\Concerns;

use Native\Mobile\Edge\Asgard\RegionRequest;
use Native\Mobile\Edge\Asgard\ServerMethods;
use Native\Mobile\Edge\Asgard\ServerRegion;

/**
 * The Asgard helpers a component can call from its own code. Opt in with
 * `use UsesServer;` on a NativeComponent.
 *
 * A component does not need this to render `@server` regions. Without it
 * the regions still fetch, paint, stream and fail as normal, and the
 * timeouts come from config. It does need it to declare `#[Server]`
 * methods (ASGARD-SERVER-METHODS.md §1).
 *
 * None of these methods can be called from a template or a fragment. The
 * interaction guard refuses every method of this trait by name, even
 * though the trait puts them on the app's own class
 * (ComponentMethodInvoker::ensureCallable()).
 *
 * @mixin \Native\Mobile\Edge\NativeComponent
 */
trait UsesServer
{
    /**
     * Fetch this component's `@server` regions again: one by name, or all
     * of them. Call it from your own action:
     *
     *     public function refresh(): void
     *     {
     *         $this->refreshServerRegions();
     *     }
     *
     * The requests go out after the next frame, so they carry the state
     * the action leaves behind. The fragment on screen stays until the
     * new one lands.
     */
    public function refreshServerRegions(?string $region = null): void
    {
        ServerRegion::refresh($this, $region);
    }

    /**
     * Seconds a server action or `#[Server]` method sent from this
     * component waits for its answer. After that it fails, and
     * ServerActionFailed fires on the screen with reason `timeout`. From
     * `nativephp.asgard.action_timeout`, 15 by default. Override it to
     * set it for one component. 0 or less waits for ever.
     */
    public function serverActionTimeout(): float
    {
        return (float) config('nativephp.asgard.action_timeout', 15);
    }

    /**
     * Seconds a request for one of this component's `@server` regions
     * waits for its answer. After that it fails, and ServerRegionFailed
     * fires on the screen with reason `timeout`. From
     * `nativephp.asgard.region_timeout`, 15 by default. Override it to set
     * it for one component. 0 or less waits for ever.
     */
    public function serverRegionTimeout(): float
    {
        return (float) config('nativephp.asgard.region_timeout', 15);
    }

    /**
     * Paint a fragment that a FragmentSource fetched. Made to be called
     * from an AsyncTask `->finished()` callback, which runs on the UI
     * thread bound to the screen:
     *
     *     ->finished(fn ($tree) => $this->receiveFragment($request, $tree))
     *
     * Pass the RegionRequest the source was handed. It names the
     * component that rendered the region, which may be a child of the
     * screen this runs on, and lets a late answer to a superseded request
     * be dropped. A bare region name applies to this component directly.
     *
     * `$tree` is the decoded fragment or the raw `tree/json` body.
     * Returns whether it was applied. The screen repaints either way it
     * arrives: an AsyncTask completion is an event the runloop renders
     * after, and anything else is caught by the runloop's repaint check.
     */
    public function receiveFragment(RegionRequest|string $region, array|string $tree): bool
    {
        return $region instanceof RegionRequest
            ? ServerRegion::deliver($region, $tree)
            : ServerRegion::applyFragment($region, $tree, $this);
    }

    /**
     * Call one of this component's `#[Server]` methods from code. This is
     * the path a `@tap` bound to one takes (ASGARD-SERVER-METHODS.md §2).
     * The body runs here, the request goes to the transport, and the
     * closure it returned is held until the answer arrives.
     *
     * Returns the request id, or null when nothing is in flight because
     * the send failed (the closure has already run with a failed response).
     *
     * Calling the method directly, as `$this->refreshChart('30d')`, runs
     * the client body and hands back its closure with nothing to resolve
     * it. Route through here instead.
     */
    public function callServerMethod(string $method, mixed ...$args): ?string
    {
        return ServerMethods::call($this, $method, $args);
    }
}
