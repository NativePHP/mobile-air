<?php

namespace Native\Mobile\Testing;

use Native\Mobile\Edge\Asgard\Contracts\ServerTransport;
use Native\Mobile\Edge\Asgard\ServerMethods;
use Native\Mobile\Edge\Asgard\ServerRequest;
use PHPUnit\Framework\Assert;

/**
 * In-process stand-in for whatever performs `#[Server]` calls
 * (docs/ASGARD-SERVER-METHODS.md §3), for tests.
 *
 *     $server = Native::fakeServerTransport();
 *
 *     $screen = Native::test(Dashboard::class)->tap('Refresh');
 *
 *     expect($server->lastRequest()->state['loading'])->toBeTrue();
 *
 *     $screen->emitNative(ServerMethods::RESPONSE_EVENT, $server->payload(
 *         delta: ['chartData' => [1, 2, 3]],
 *     ));
 *
 * Nothing is sent and nothing resolves on its own: `send()` records the
 * request, hands back an id, and the test decides when — and whether —
 * an answer arrives. That is the whole reason the transport is an
 * interface, and it is what makes the awkward cases testable: a response
 * that overtakes an older one, a response for a screen the user has
 * already left, one that never comes at all.
 *
 * Two ways to answer, both valid:
 *
 *  - `resolve()` / `fail()` hand the frame straight to
 *    `ServerMethods::deliver()`, which is the unit-level path.
 *  - `payload()` / `failurePayload()` build the frame so a test can push
 *    it through `TestableComponent::emitNative()` instead — the real
 *    route, event channel and re-render included.
 */
class FakeServerTransport implements ServerTransport
{
    /** Requests sent, oldest first, keyed by the id handed back. */
    protected array $sent = [];

    protected int $sequence = 0;

    /** When set, send() throws it — the offline path of §7. */
    protected ?string $sendFailure = null;

    // ── Binding ─────────────────────────────────────

    /**
     * Bind a fake transport for this test (idempotent) and return it.
     *
     * Also clears the pending-request table, which is static and would
     * otherwise carry requests left in flight by an earlier test in the
     * same process.
     */
    public static function enable(): static
    {
        $existing = static::current();

        if ($existing !== null) {
            return $existing;
        }

        ServerMethods::flush();

        $transport = new static;
        app()->instance(ServerTransport::class, $transport);

        return $transport;
    }

    public static function current(): ?static
    {
        if (! function_exists('app') || ! app()->bound(ServerTransport::class)) {
            return null;
        }

        $bound = app(ServerTransport::class);

        return $bound instanceof static ? $bound : null;
    }

    // ── Transport ───────────────────────────────────

    public function send(ServerRequest $request): string
    {
        if ($this->sendFailure !== null) {
            throw new \RuntimeException($this->sendFailure);
        }

        $id = 'asgard-'.(++$this->sequence);

        $this->sent[$id] = $request;

        return $id;
    }

    /** Make every subsequent send() fail, as an offline device does. */
    public function failSending(string $message = 'Network unreachable.'): static
    {
        $this->sendFailure = $message;

        return $this;
    }

    public function succeedSending(): static
    {
        $this->sendFailure = null;

        return $this;
    }

    // ── Inspection ──────────────────────────────────

    /** @return array<string, ServerRequest> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function lastRequest(): ?ServerRequest
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)];
    }

    public function request(string $id): ?ServerRequest
    {
        return $this->sent[$id] ?? null;
    }

    /** The id of the first request sent for a method, oldest first. */
    public function idFor(string $method): ?string
    {
        foreach ($this->sent as $id => $request) {
            if ($request->method === $method) {
                return $id;
            }
        }

        return null;
    }

    public function lastId(): ?string
    {
        return $this->sent === [] ? null : array_key_last($this->sent);
    }

    public function assertSent(string $method, ?callable $filter = null): static
    {
        foreach ($this->sent as $request) {
            if ($request->method === $method && ($filter === null || $filter($request))) {
                Assert::assertTrue(true);

                return $this;
            }
        }

        Assert::fail(
            "No #[Server] request for [{$method}] was sent. Sent: ".
            (implode(', ', array_map(fn (ServerRequest $r) => $r->method, $this->sent)) ?: '(none)')
        );
    }

    public function assertNothingSent(): static
    {
        Assert::assertSame([], array_map(fn (ServerRequest $r) => $r->method, $this->sent));

        return $this;
    }

    // ── Answering ───────────────────────────────────

    /**
     * A success frame for a request. `delta` is only the props the server
     * changed (§5.1) — a full state snapshot is exactly what the protocol
     * tells servers not to send.
     */
    public function payload(?string $id = null, array $delta = [], array $fragments = []): array
    {
        return array_filter([
            'id' => $this->resolveId($id),
            'ok' => true,
            'delta' => $delta,
            'fragments' => $fragments,
        ], fn ($value) => $value !== []);
    }

    public function failurePayload(?string $id = null, string $error = 'The server could not be reached.', int|string|null $code = null): array
    {
        return [
            'id' => $this->resolveId($id),
            'ok' => false,
            'error' => $error,
            'code' => $code,
        ];
    }

    /** Answer a request successfully, through the delivery path. */
    public function resolve(?string $id = null, array $delta = [], array $fragments = []): bool
    {
        return ServerMethods::deliver($this->payload($id, $delta, $fragments));
    }

    /** Fail a request, through the delivery path. */
    public function fail(?string $id = null, string $error = 'The server could not be reached.', int|string|null $code = null): bool
    {
        return ServerMethods::deliver($this->failurePayload($id, $error, $code));
    }

    /** Default to the most recent request, which is the common case. */
    protected function resolveId(?string $id): string
    {
        return $id ?? $this->lastId() ?? '';
    }
}
