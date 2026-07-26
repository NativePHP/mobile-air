<?php

namespace Native\Mobile\Testing;

use Closure;
use Native\Mobile\AsyncTask;
use Native\Mobile\Support\AsyncTaskRunner;
use PHPUnit\Framework\Assert;

/**
 * In-process stand-in for the async lane, enabled with {@see AsyncTask::fake()}.
 *
 * While active, every `AsyncTask::dispatch()` runs its work **inline and
 * synchronously** and its `finished()`/`failed()` callback fires immediately —
 * so tests exercise the whole flow with no threads, bridge, or device — and each
 * dispatch is recorded for assertions.
 *
 * The result still goes through the same JSON normalization the device transport
 * applies ({@see AsyncTaskRunner::normalizeResult()}), so a test can't pass on a
 * value that wouldn't survive the real hop: objects arrive as arrays, and a
 * non-encodable result fails the task here exactly as it would on a device.
 *
 *   $fake = AsyncTask::fake();
 *   $component->tap('generateReport');
 *   $fake->assertDispatched();
 */
class FakeAsyncTask
{
    /**
     * Recorded dispatches, oldest first.
     *
     * @var array<int, array{id: string, work: array, shared: string|null}>
     */
    public array $dispatched = [];

    public function record(string $id, array $work, ?string $shared): void
    {
        $this->dispatched[] = ['id' => $id, 'work' => $work, 'shared' => $shared];
    }

    public function count(): int
    {
        return count($this->dispatched);
    }

    // ── Assertions ──────────────────────────────────

    /**
     * Assert at least one task was dispatched. The optional callback receives
     * each recorded dispatch and can narrow the match by returning true.
     */
    public function assertDispatched(?Closure $filter = null): static
    {
        if ($filter === null) {
            Assert::assertNotEmpty($this->dispatched, 'Expected an async task to be dispatched, but none were.');

            return $this;
        }

        $matched = array_filter($this->dispatched, fn (array $d) => $filter($d) === true);
        Assert::assertNotEmpty($matched, 'No dispatched async task matched the given filter.');

        return $this;
    }

    public function assertNotDispatched(): static
    {
        Assert::assertEmpty(
            $this->dispatched,
            'Expected no async tasks to be dispatched, but '.count($this->dispatched).' were.'
        );

        return $this;
    }

    public function assertDispatchedTimes(int $times): static
    {
        Assert::assertCount(
            $times,
            $this->dispatched,
            "Expected {$times} async task(s) to be dispatched, got ".count($this->dispatched).'.'
        );

        return $this;
    }

    /** Assert a task was dispatched with the given shared-event alias. */
    public function assertShared(string $alias): static
    {
        $matched = array_filter($this->dispatched, fn (array $d) => $d['shared'] === $alias);
        Assert::assertNotEmpty($matched, "No async task was dispatched as shared('{$alias}').");

        return $this;
    }
}
