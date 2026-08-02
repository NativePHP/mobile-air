<?php

namespace Native\Mobile\Testing;

use Illuminate\Support\Traits\Macroable;
use Native\Mobile\Support\NativeCallbacks;
use PHPUnit\Framework\Assert;

/**
 * In-process stand-in for the native bridge, used by the component
 * testing suite (`Native::test(...)`).
 *
 * The `nativephp_*` PHP polyfills in `jump_bridge_functions.php` consult
 * `FakeBridge::current()` before falling through to the Jump TCP bridge.
 * While a FakeBridge is bound, every publish is captured in-process and
 * every `nativephp_call()` is recorded (and optionally answered from a
 * scripted response) — no device, simulator, or Jump session required.
 *
 * The instance is bound into the Laravel container, so each test's fresh
 * application gets a fresh bridge and state can never leak across tests.
 *
 * Plugins can teach the bridge their own test vocabulary via macros, so
 * app tests read in domain terms instead of raw bridge method strings:
 *
 *     FakeBridge::macro('assertCopied', function (?string $text = null) {
 *         return $this->assertCalled('Clipboard.WriteText',
 *             fn ($p) => $text === null || $p['text'] === $text);
 *     });
 *
 *     Native::test(ShareSheet::class)->tap('copy')->assertCopied('https://…');
 *
 * TestableComponent forwards unknown methods here, so macros (and the
 * built-in helpers) chain straight off the harness.
 */
class FakeBridge
{
    use Macroable;

    /** Every tree passed to nativephp_element_publish(), oldest first. */
    public array $publishes = [];

    /**
     * Every nativephp_call() made, oldest first.
     * Each entry: ['method' => string, 'params' => array (decoded JSON)].
     */
    public array $calls = [];

    /** Scripted responses: bridge method → response (string|array|Closure). */
    protected array $responses = [];

    /** Value returned by nativephp_runtime_flags(). 0 = all features off. */
    public int $runtimeFlags = 0;

    /** Counts of element-region lifecycle calls, for completeness. */
    public int $initCount = 0;

    public int $resetCount = 0;

    public int $shutdownCount = 0;

    // ── Binding ─────────────────────────────────────

    /**
     * Bind a FakeBridge into the container (idempotent) and return it.
     */
    public static function enable(): static
    {
        $existing = static::current();
        if ($existing !== null) {
            return $existing;
        }

        // First bridge of this test: drop pending fluent callbacks left in
        // NativeCallbacks' static tier by earlier tests in this process.
        NativeCallbacks::flush();

        $bridge = new static;
        app()->instance(static::class, $bridge);

        return $bridge;
    }

    public static function disable(): void
    {
        if (function_exists('app') && app()->bound(static::class)) {
            app()->forgetInstance(static::class);
        }
    }

    /**
     * The currently bound FakeBridge, or null when tests aren't driving.
     * Called from the global bridge polyfills on every invocation, so it
     * must be cheap and must never throw outside a booted app.
     */
    public static function current(): ?static
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            $app = app();
        } catch (\Throwable) {
            return null;
        }

        if ($app === null || ! $app->bound(static::class)) {
            return null;
        }

        return $app->make(static::class);
    }

    // ── Hooks called by the nativephp_* polyfills ───

    /** @var array<int, string> Bridge functions a test has marked unavailable. */
    protected array $unavailable = [];

    /** @var array<int, string> Every capability probe made, in order. */
    protected array $capabilityChecks = [];

    public function call(string $method, string $params = '{}'): ?string
    {
        $decoded = json_decode($params, true);

        $this->calls[] = [
            'method' => $method,
            'params' => is_array($decoded) ? $decoded : [],
        ];

        $response = $this->responses[$method] ?? null;

        if ($response instanceof \Closure) {
            $response = $response(is_array($decoded) ? $decoded : []);
        }

        if (is_array($response)) {
            return json_encode($response);
        }

        return $response;
    }

    /**
     * Answer a capability probe. Everything is available unless a test has
     * said otherwise via {@see cannot()}.
     *
     * Without this, capability-gated branches are untestable: the polyfill's
     * nativephp_can() hardcodes `true`, so the fallback arm of every
     * `nativephp_can(...)` check is dead code as far as the suite is
     * concerned. That is how an iOS-only bridge function reached main
     * swallowing Android toasts (#237).
     */
    public function can(string $method): bool
    {
        $this->capabilityChecks[] = $method;

        return ! in_array($method, $this->unavailable, true);
    }

    /**
     * Mark bridge functions as absent, so `nativephp_can()` reports false for
     * them and the caller's fallback path runs.
     */
    public function cannot(string ...$methods): static
    {
        foreach ($methods as $method) {
            if (! in_array($method, $this->unavailable, true)) {
                $this->unavailable[] = $method;
            }
        }

        return $this;
    }

    /** Assert the code asked whether a bridge function was available. */
    public function assertCapabilityChecked(string $method): static
    {
        Assert::assertContains(
            $method,
            $this->capabilityChecks,
            "Expected a capability check for [{$method}], but none was made."
        );

        return $this;
    }

    public function elementInit(): void
    {
        $this->initCount++;
    }

    public function elementPublish(array $tree): void
    {
        $this->publishes[] = $tree;
    }

    /**
     * The runloop never blocks under test — the harness dispatches events
     * directly — so a wait always behaves as an instant idle tick.
     */
    public function elementWaitEvent(int $timeoutMs): ?array
    {
        return null;
    }

    public function elementReset(): void
    {
        $this->resetCount++;
    }

    public function elementShutdown(): void
    {
        $this->shutdownCount++;
    }

    public function runtimeFlags(): int
    {
        return $this->runtimeFlags;
    }

    // ── Scripting ───────────────────────────────────

    /**
     * Script the response for a bridge method. Arrays are JSON-encoded on
     * the way out; a Closure receives the decoded call params and may
     * return a string, array, or null.
     *
     *   FakeBridge::enable()->respondTo('Geolocation.GetCurrentPosition', [
     *       'latitude' => 40.7, 'longitude' => -74.0,
     *   ]);
     */
    public function respondTo(string $method, string|array|\Closure|null $response): static
    {
        $this->responses[$method] = $response;

        return $this;
    }

    // ── Inspection ──────────────────────────────────

    public function lastPublish(): ?array
    {
        return $this->publishes === [] ? null : end($this->publishes);
    }

    /** All recorded calls to a given bridge method. */
    public function callsTo(string $method): array
    {
        return array_values(array_filter(
            $this->calls,
            fn (array $call) => $call['method'] === $method
        ));
    }

    // ── Assertions ──────────────────────────────────

    /**
     * Assert the component called a native bridge method. The optional
     * callback receives the decoded params of each matching call and can
     * narrow the match by returning true/false.
     */
    public function assertCalled(string $method, ?callable $paramsFilter = null): static
    {
        $calls = $this->callsTo($method);

        Assert::assertNotEmpty(
            $calls,
            "Expected native bridge call [{$method}] was not made. ".
            'Calls made: '.($this->calls === [] ? '(none)' : implode(', ', array_column($this->calls, 'method')))
        );

        if ($paramsFilter !== null) {
            $matched = array_filter($calls, fn (array $call) => $paramsFilter($call['params']) === true);

            Assert::assertNotEmpty(
                $matched,
                "Native bridge call [{$method}] was made, but no call matched the given params filter."
            );
        }

        return $this;
    }

    public function assertNotCalled(string $method): static
    {
        Assert::assertEmpty(
            $this->callsTo($method),
            "Unexpected native bridge call [{$method}] was made."
        );

        return $this;
    }

    public function assertCalledTimes(string $method, int $times): static
    {
        $actual = count($this->callsTo($method));

        Assert::assertSame(
            $times,
            $actual,
            "Expected [{$method}] to be called {$times} time(s), got {$actual}."
        );

        return $this;
    }

    /**
     * Assert the given bridge methods were called in this relative order.
     * Other calls may be interleaved — the sequence just has to appear.
     */
    public function assertCallOrder(array $methods): static
    {
        $made = array_column($this->calls, 'method');
        $cursor = 0;

        foreach ($made as $method) {
            if ($cursor < count($methods) && $method === $methods[$cursor]) {
                $cursor++;
            }
        }

        Assert::assertSame(
            count($methods),
            $cursor,
            'Bridge calls did not occur in the expected order. Expected sequence: '
            .implode(' → ', $methods).'. Calls made: '.(implode(', ', $made) ?: '(none)')
        );

        return $this;
    }

    public function assertNothingCalled(): static
    {
        Assert::assertEmpty(
            $this->calls,
            'Expected no native bridge calls, but got: '.implode(', ', array_column($this->calls, 'method'))
        );

        return $this;
    }
}
