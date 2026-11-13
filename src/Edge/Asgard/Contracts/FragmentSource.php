<?php

namespace Native\Mobile\Edge\Asgard\Contracts;

use Native\Mobile\Edge\Asgard\ActionRequest;
use Native\Mobile\Edge\Asgard\RegionBatch;
use Native\Mobile\Edge\Asgard\RegionRequest;

/**
 * Where a region's fragment comes from (docs/ASGARD-PROTOCOL.md §3, §8).
 *
 * The client asks once a region has painted its placeholder, and again
 * when a component calls refreshServerRegions() (from UsesServer).
 * request() is fire and forget: it must return quickly, because it runs
 * on the UI thread at the end of a frame. An implementation does its
 * work elsewhere (an AsyncTask, typically) and delivers later with
 * `ServerRegion::deliver($request, $tree)`, from an AsyncTask
 * `->finished()` callback or anywhere else on the UI thread. Do not call
 * `$this->receiveFragment()` there: it comes from the UsesServer trait,
 * and the screen the callback is bound to may not use it.
 *
 * A request that fails without an answer the client can use is reported
 * with `ServerRegion::failed($request, $reason, $status)`, where the
 * reason is ServerRegion::NETWORK, SERVER or INVALID. A region keeps its
 * placeholder, or the last fragment it painted, which is the intended
 * offline state rather than an error. A server screen still waiting for
 * its body gives up and leaves, and an action fires ServerActionFailed.
 * Never answering is allowed too: a server screen and an action then
 * fail when their timeout passes.
 *
 * action() is a tap inside a fragment whose callback targets the server
 * (§10). It is fire and forget in the same way: send it, and deliver the
 * fragment the server answers with through the same
 * `ServerRegion::deliver($request, $tree)`. An ActionRequest is a
 * RegionRequest, so the answer path is the same one.
 *
 * batch() is several regions of one component instance at once: the
 * first frame of a screen with several regions, a refreshServerRegions()
 * of all of them, or several hints and signals landing together. Send one
 * request with `regions`, and answer with
 * `ServerRegion::deliverBatch($batch, $fragments)`, where `$fragments` is
 * the answer's `{region: {fragment, version?, chrome?}}`. A batch that
 * fails as a whole is `ServerRegion::failedBatch($batch, $reason, $status)`.
 * Each region in it still has its own request, timer and failure.
 *
 * An action's answer can carry other regions of the same component and
 * refresh hints for other components. Hand them to deliver() with the
 * action's fragment: `deliver($request, $tree, $chrome, $version,
 * $fragments, $refresh)`.
 *
 * watch() is the push stream (§8.1). It gets the whole set of
 * subscriptions the device wants, every time the set changes: an empty
 * list means close the stream. Each is
 * `['id', 'component', 'regions', 'state', 'versions']`. A source that
 * streams opens one stream for the set. It hands each signal to
 * `ServerRegion::signal()` and each shared region's fragment to
 * `ServerRegion::push()`, with the subscription's address and state. A
 * source that does not stream ignores it.
 */
interface FragmentSource
{
    public function request(RegionRequest $request): void;

    public function action(ActionRequest $request): void;

    public function batch(RegionBatch $batch): void;

    /**
     * @param  list<array{id: string, component: string, regions: list<string>, state: array, versions: array<string, string>}>  $subscriptions
     */
    public function watch(array $subscriptions, string $platform): void;
}
