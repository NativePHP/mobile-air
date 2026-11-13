<?php

namespace Native\Mobile\Testing;

use Native\Mobile\Edge\Asgard\ActionRequest;
use Native\Mobile\Edge\Asgard\Contracts\FragmentSource;
use Native\Mobile\Edge\Asgard\RegionBatch;
use Native\Mobile\Edge\Asgard\RegionRequest;
use Native\Mobile\Edge\Asgard\ServerRegion;
use PHPUnit\Framework\Assert;

/**
 * In-process stand-in for whatever fetches `@server` fragments, for tests.
 *
 *     $source = Native::fakeFragmentSource();
 *
 *     $screen = Native::test(Dashboard::class);
 *
 *     $source->assertRequested('sales-summary');
 *     $source->respond('sales-summary', ['type' => 'text', 'props' => ['text' => '£12,400']]);
 *
 *     $screen->call('refresh')->assertSee('£12,400');
 *
 * request() only records. Nothing answers on its own, so a test decides
 * when an answer lands and whether it ever does. respond() goes through
 * ServerRegion::deliver(), the same path a real source takes, so a stale
 * or orphaned answer is dropped here exactly as it would be on a device.
 *
 * A tap on a fragment callback that targets the server is recorded the
 * same way, as an action, and answered with respondToAction():
 *
 *     $screen->tap('Shuffle');
 *
 *     $source->assertActionSent('sales-summary', 'shuffle');
 *     $source->respondToAction('sales-summary', ['type' => 'text', 'props' => ['text' => 'Shuffled']]);
 *
 * Several regions of one component asked for together arrive as a batch.
 * Each region in it is recorded as a request too, so assertRequested() and
 * respond() work the same. respondToBatch() answers the whole batch:
 *
 *     $source->assertBatched(['sales-summary', 'chart']);
 *     $source->respondToBatch(fragments: ['sales-summary' => ['fragment' => [...]]]);
 *
 * A failure is reported with fail() or failAction(), through
 * ServerRegion::failed(), as a real source reports one. The screen acts
 * on it at its next turn, which wait() takes:
 *
 *     $source->failAction('sales-summary', ServerRegion::SERVER, 500);
 *     $screen->wait();
 */
class FakeFragmentSource implements FragmentSource
{
    /** @var list<RegionRequest> oldest first */
    protected array $requests = [];

    /** @var list<ActionRequest> oldest first */
    protected array $actions = [];

    /** @var list<RegionBatch> oldest first */
    protected array $batches = [];

    /**
     * Every set handed to watch(), oldest first.
     *
     * @var list<list<array{id: string, component: string, regions: list<string>, state: array, versions: array<string, string>}>>
     */
    protected array $watches = [];

    /** Bind a fake source for this test (idempotent) and return it. */
    public static function enable(): static
    {
        $existing = static::current();

        if ($existing !== null) {
            return $existing;
        }

        ServerRegion::flush();

        $source = new static;
        app()->instance(FragmentSource::class, $source);

        return $source;
    }

    public static function current(): ?static
    {
        if (! function_exists('app') || ! app()->bound(FragmentSource::class)) {
            return null;
        }

        $bound = app(FragmentSource::class);

        return $bound instanceof static ? $bound : null;
    }

    public function request(RegionRequest $request): void
    {
        $this->requests[] = $request;
    }

    public function action(ActionRequest $request): void
    {
        $this->actions[] = $request;
    }

    public function batch(RegionBatch $batch): void
    {
        $this->batches[] = $batch;

        foreach ($batch->requests as $request) {
            $this->requests[] = $request;
        }
    }

    public function watch(array $subscriptions, string $platform): void
    {
        $this->watches[] = $subscriptions;
    }

    // ── Inspection ──────────────────────────────────

    /** @return list<RegionRequest> */
    public function requests(?string $region = null): array
    {
        return $region === null
            ? $this->requests
            : array_values(array_filter($this->requests, fn (RegionRequest $r) => $r->region === $region));
    }

    public function lastRequest(?string $region = null): ?RegionRequest
    {
        $requests = $this->requests($region);

        return $requests === [] ? null : $requests[array_key_last($requests)];
    }

    public function assertRequested(string $region, ?int $times = null): static
    {
        $count = count($this->requests($region));

        if ($times === null) {
            Assert::assertGreaterThan(0, $count, "Region [{$region}] was never requested.");
        } else {
            Assert::assertSame($times, $count, "Region [{$region}] was requested {$count} times, not {$times}.");
        }

        return $this;
    }

    public function assertNothingRequested(): static
    {
        Assert::assertSame([], array_map(fn (RegionRequest $r) => $r->region, $this->requests));

        return $this;
    }

    /** @return list<RegionBatch> */
    public function batches(): array
    {
        return $this->batches;
    }

    public function lastBatch(): ?RegionBatch
    {
        return $this->batches === [] ? null : $this->batches[array_key_last($this->batches)];
    }

    /** Assert one batch asked for exactly these regions, in this order, and how many times. */
    public function assertBatched(array $regions, ?int $times = null): static
    {
        $count = count(array_filter($this->batches, fn (RegionBatch $b) => $b->regions() === array_values($regions)));
        $what = 'A batch of ['.implode(', ', $regions).']';

        if ($times === null) {
            Assert::assertGreaterThan(0, $count, "{$what} was never sent.");
        } else {
            Assert::assertSame($times, $count, "{$what} was sent {$count} times, not {$times}.");
        }

        return $this;
    }

    public function assertNoBatches(): static
    {
        Assert::assertSame([], array_map(fn (RegionBatch $b) => $b->regions(), $this->batches));

        return $this;
    }

    /** @return list<ActionRequest> */
    public function actions(?string $region = null): array
    {
        return $region === null
            ? $this->actions
            : array_values(array_filter($this->actions, fn (ActionRequest $a) => $a->region === $region));
    }

    public function lastAction(?string $region = null): ?ActionRequest
    {
        $actions = $this->actions($region);

        return $actions === [] ? null : $actions[array_key_last($actions)];
    }

    /** Assert a region sent a server action, optionally of one `call` and a number of times. */
    public function assertActionSent(string $region, ?string $call = null, ?int $times = null): static
    {
        $actions = array_filter(
            $this->actions($region),
            fn (ActionRequest $a) => $call === null || $a->call === $call,
        );

        $what = $call === null ? "Region [{$region}]" : "Region [{$region}] action [{$call}]";
        $count = count($actions);

        if ($times === null) {
            Assert::assertGreaterThan(0, $count, "{$what} was never sent.");
        } else {
            Assert::assertSame($times, $count, "{$what} was sent {$count} times, not {$times}.");
        }

        return $this;
    }

    public function assertNoActionsSent(): static
    {
        Assert::assertSame([], array_map(fn (ActionRequest $a) => $a->region.':'.$a->call, $this->actions));

        return $this;
    }

    /**
     * Every set the push stream was told to watch, oldest first.
     *
     * @return list<list<array{id: string, component: string, regions: list<string>, state: array, versions: array<string, string>}>>
     */
    public function watches(): array
    {
        return $this->watches;
    }

    /**
     * The set the push stream watches now: the last one handed over, or
     * null when none ever was. An empty list means the stream is closed.
     */
    public function watching(): ?array
    {
        return $this->watches === [] ? null : $this->watches[array_key_last($this->watches)];
    }

    /** Assert how many times the push stream set was handed over. */
    public function assertWatched(int $times): static
    {
        $count = count($this->watches);

        Assert::assertSame($times, $count, "The push stream set was handed over {$count} times, not {$times}.");

        return $this;
    }

    // ── Answering ───────────────────────────────────

    /**
     * Answer a request: the RegionRequest itself, or a region name for the
     * latest request made for it. Returns whether the fragment was applied.
     *
     * The screen paints it on its next frame, as on a device, so follow
     * with an interaction (or emitNative()) to see it. `$version` is the
     * answer's version token, as a server would send it.
     */
    public function respond(RegionRequest|string $request, array|string $tree, ?string $version = null): bool
    {
        if (is_string($request)) {
            $region = $request;
            $request = $this->lastRequest($region);

            Assert::assertNotNull($request, "Region [{$region}] was never requested, so there is nothing to answer.");
        }

        return ServerRegion::deliver($request, $tree, null, $version);
    }

    /**
     * Answer a server action: the ActionRequest itself, or a region name
     * for the latest action sent from it. Same rules as respond().
     */
    public function respondToAction(ActionRequest|string $action, array|string $tree, array $fragments = [], array $refresh = []): bool
    {
        if (is_string($action)) {
            $region = $action;
            $action = $this->lastAction($region);

            Assert::assertNotNull($action, "Region [{$region}] never sent an action, so there is nothing to answer.");
        }

        return ServerRegion::deliver($action, $tree, null, null, $fragments, $refresh);
    }

    /**
     * Answer a batch, the last one sent when none is given, with
     * `{region: {fragment, version?, chrome?}}`. Returns how many regions
     * were painted. A region left out fails as the device would fail it.
     */
    public function respondToBatch(?RegionBatch $batch = null, array $fragments = []): int
    {
        $batch ??= $this->lastBatch();

        Assert::assertNotNull($batch, 'No batch was sent, so there is nothing to answer.');

        return ServerRegion::deliverBatch($batch, $fragments);
    }

    /** Report a whole batch as failed, the last one sent when none is given. */
    public function failBatch(?RegionBatch $batch = null, string $reason = ServerRegion::NETWORK, ?int $status = null, ?string $message = null): int
    {
        $batch ??= $this->lastBatch();

        Assert::assertNotNull($batch, 'No batch was sent, so there is nothing to fail.');

        return ServerRegion::failedBatch($batch, $reason, $status, $message);
    }

    /**
     * Report a request as failed: the RegionRequest itself, or a region
     * name for the latest request made for it. Returns whether the
     * failure was acted on (see ServerRegion::failed()).
     */
    public function fail(RegionRequest|string $request, string $reason = ServerRegion::NETWORK, ?int $status = null, ?string $message = null): bool
    {
        if (is_string($request)) {
            $region = $request;
            $request = $this->lastRequest($region);

            Assert::assertNotNull($request, "Region [{$region}] was never requested, so there is nothing to fail.");
        }

        return ServerRegion::failed($request, $reason, $status, $message);
    }

    /** Report a server action as failed. Same rules as fail(). */
    public function failAction(ActionRequest|string $action, string $reason = ServerRegion::NETWORK, ?int $status = null, ?string $message = null): bool
    {
        if (is_string($action)) {
            $region = $action;
            $action = $this->lastAction($region);

            Assert::assertNotNull($action, "Region [{$region}] never sent an action, so there is nothing to fail.");
        }

        return ServerRegion::failed($action, $reason, $status, $message);
    }
}
