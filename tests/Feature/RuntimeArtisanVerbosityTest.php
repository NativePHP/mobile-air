<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Native\Mobile\Runtime;

beforeEach(function () {
    $this->verbosity = [$_ENV['SHELL_VERBOSITY'] ?? null, $_SERVER['SHELL_VERBOSITY'] ?? null, getenv('SHELL_VERBOSITY')];

    Runtime::boot($this->app);

    Artisan::command('say-hello', function () {
        /** @var Command $this */
        $this->line('Hello');
    });
});

afterEach(function () {
    [$_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY'], $env] = $this->verbosity;
    putenv($env === false ? 'SHELL_VERBOSITY' : "SHELL_VERBOSITY={$env}");
});

/**
 * The state a lane finds when another lane is running `queue:work --quiet`, or has run it:
 * Symfony Console keeps verbosity in the process environment, which every lane shares, while
 * each lane's thread has its own $_ENV and $_SERVER.
 */
function quietFromAnotherLane(): void
{
    unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);
    putenv('SHELL_VERBOSITY=-1');
}

it('is not silenced by a quiet command on another lane', function () {
    quietFromAnotherLane();

    expect(Runtime::artisan('say-hello'))->toBe('Hello'.PHP_EOL);
});

it('still honours its own verbosity flags', function () {
    expect(Runtime::artisan('say-hello --quiet'))->toBe('');

    quietFromAnotherLane();

    expect(Runtime::artisan('say-hello --verbose'))->toBe('Hello'.PHP_EOL);
});

it('leaves this lane and the process as it found them', function () {
    quietFromAnotherLane();

    Runtime::artisan('say-hello');

    expect($_ENV)->not->toHaveKey('SHELL_VERBOSITY')
        ->and($_SERVER)->not->toHaveKey('SHELL_VERBOSITY')
        ->and(getenv('SHELL_VERBOSITY'))->toBe('-1');
});
