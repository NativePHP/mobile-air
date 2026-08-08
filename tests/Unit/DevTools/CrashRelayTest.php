<?php

use Native\Mobile\Contracts\ExceptionReporter;
use Native\Mobile\DevTools\CrashRelay;

it('does nothing when no reporter is bound', function () {
    expect(app()->bound(ExceptionReporter::class))->toBeFalse();

    CrashRelay::report(new RuntimeException('unreported'));

    // Reaching this line without an exception is the assertion.
    expect(true)->toBeTrue();
});

it('passes the throwable and context to the bound reporter', function () {
    $spy = new class implements ExceptionReporter
    {
        public array $reports = [];

        public function report(Throwable $e, array $context = []): void
        {
            $this->reports[] = [$e, $context];
        }
    };

    app()->instance(ExceptionReporter::class, $spy);

    $exception = new RuntimeException('boom');
    CrashRelay::report($exception, ['mode' => 'edge', 'screen' => 'App\\Screens\\Home']);

    expect($spy->reports)->toHaveCount(1)
        ->and($spy->reports[0][0])->toBe($exception)
        ->and($spy->reports[0][1])->toBe(['mode' => 'edge', 'screen' => 'App\\Screens\\Home']);
});

it('swallows exceptions thrown by the reporter', function () {
    app()->instance(ExceptionReporter::class, new class implements ExceptionReporter
    {
        public function report(Throwable $e, array $context = []): void
        {
            throw new LogicException('reporter is broken');
        }
    });

    CrashRelay::report(new RuntimeException('boom'));

    expect(true)->toBeTrue();
});

it('drops re-entrant reports instead of recursing', function () {
    $spy = new class implements ExceptionReporter
    {
        public int $calls = 0;

        public function report(Throwable $e, array $context = []): void
        {
            $this->calls++;
            CrashRelay::report(new RuntimeException('nested'));
        }
    };

    app()->instance(ExceptionReporter::class, $spy);

    CrashRelay::report(new RuntimeException('outer'));

    expect($spy->calls)->toBe(1);
});
