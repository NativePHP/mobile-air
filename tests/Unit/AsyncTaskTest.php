<?php

use Native\Mobile\AsyncTask;
use Native\Mobile\Exceptions\AsyncTaskException;
use Native\Mobile\PendingAsyncTask;
use Native\Mobile\Support\AsyncTaskRunner;

/**
 * A task subclass used to exercise the Job-like dispatch form.
 */
class BuildReportTask extends AsyncTask
{
    public function handle(int $a, int $b): int
    {
        return $a + $b;
    }
}

/**
 * Produces a closure auto-bound to $this — exactly what happens when a closure
 * is written inside a component method.
 */
class BoundClosureFactory
{
    public function make(): Closure
    {
        return fn () => 'nope';
    }
}

it('rejects a non-static (bound) work closure at dispatch time', function () {
    // Inside a component method a closure is auto-bound to $this; reflect on that
    // and reject so the footgun fails loud, in the handler.
    $boundToObject = (new BoundClosureFactory)->make();

    expect(fn () => PendingAsyncTask::forClosure($boundToObject))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts a static work closure', function () {
    AsyncTask::fake(); // keep auto-start on the inline path, never the bridge

    $pending = PendingAsyncTask::forClosure(static fn () => 'ok');

    expect($pending)->toBeInstanceOf(PendingAsyncTask::class)
        ->and($pending->getId())->toBeString()->not->toBeEmpty();
});

it('requires a closure on the base AsyncTask::dispatch()', function () {
    AsyncTask::fake();

    expect(fn () => AsyncTask::dispatch('not a closure'))
        ->toThrow(InvalidArgumentException::class);
});

it('runs a faked task inline and passes the result to finished()', function () {
    AsyncTask::fake();

    $captured = null;
    AsyncTask::dispatch(static fn () => ['answer' => 42])
        ->finished(function ($result) use (&$captured) {
            $captured = $result;
        });

    expect($captured)->toBe(['answer' => 42]);
});

it('passes an AsyncTaskException carrying the original message to failed()', function () {
    AsyncTask::fake();

    $error = null;
    AsyncTask::dispatch(static function () {
        throw new RuntimeException('boom');
    })->failed(function ($e) use (&$error) {
        $error = $e;
    });

    expect($error)->toBeInstanceOf(AsyncTaskException::class)
        ->and($error->getMessage())->toBe('boom')
        ->and($error->originalClass())->toBe(RuntimeException::class);
});

it('dispatches an AsyncTask subclass by running its handle() in the background context', function () {
    AsyncTask::fake();

    $sum = null;
    BuildReportTask::dispatch(2, 3)->finished(function ($result) use (&$sum) {
        $sum = $result;
    });

    expect($sum)->toBe(5);
});

it('records dispatches on the fake for assertions', function () {
    $fake = AsyncTask::fake();

    AsyncTask::dispatch(static fn () => 1)->shared('one-ready');
    AsyncTask::dispatch(static fn () => 2);

    $fake->assertDispatched()
        ->assertDispatchedTimes(2)
        ->assertShared('one-ready');
});

it('asserts nothing dispatched when idle', function () {
    $fake = AsyncTask::fake();

    $fake->assertNotDispatched();
});

it('invokes a closure work envelope directly', function () {
    $result = AsyncTaskRunner::invoke([
        'kind' => 'closure',
        'closure' => new \Laravel\SerializableClosure\SerializableClosure(static fn () => 'hi'),
    ]);

    expect($result)->toBe('hi');
});

it('invokes a task work envelope by calling handle()', function () {
    $result = AsyncTaskRunner::invoke([
        'kind' => 'task',
        'task' => BuildReportTask::class,
        'args' => [4, 5],
    ]);

    expect($result)->toBe(9);
});
