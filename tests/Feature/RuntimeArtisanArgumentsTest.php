<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Native\Mobile\Runtime;

beforeEach(function () {
    Runtime::boot($this->app);

    Artisan::command('greet {name} {greeting=Hello} {--shout}', function (string $name, string $greeting) {
        /** @var Command $this */
        $line = "{$greeting}, {$name}";
        $this->line($this->option('shout') ? strtoupper($line) : $line);
    });
});

it('binds positional arguments of a command run through the in-app runtimes', function (string $command, string $printed) {
    expect(Runtime::artisan($command))->toBe($printed.PHP_EOL);
})->with([
    'one argument' => ['greet Ada', 'Hello, Ada'],
    'two arguments and an option' => ['greet Ada Goodbye --shout', 'GOODBYE, ADA'],
    'a quoted argument' => ['greet "Ada Lovelace"', 'Hello, Ada Lovelace'],
]);

it('still passes options with values', function () {
    Artisan::command('echo-option {--value=}', function () {
        /** @var Command $this */
        $this->line((string) $this->option('value'));
    });

    expect(Runtime::artisan('echo-option --value=a=b'))->toBe('a=b'.PHP_EOL);
});
