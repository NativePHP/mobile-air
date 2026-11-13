<?php

namespace Native\Mobile\Testing;

use Native\Mobile\Edge\Asgard\ActionRequest;
use Native\Mobile\Edge\Asgard\Contracts\FragmentSource;
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
 */
class FakeFragmentSource implements FragmentSource
{
    /** @var list<RegionRequest> oldest first */
    protected array $requests = [];

    /** @var list<ActionRequest> oldest first */
    protected array $actions = [];

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
    public function respondToAction(ActionRequest|string $action, array|string $tree): bool
    {
        if (is_string($action)) {
            $region = $action;
            $action = $this->lastAction($region);

            Assert::assertNotNull($action, "Region [{$region}] never sent an action, so there is nothing to answer.");
        }

        return ServerRegion::deliver($action, $tree);
    }
}
