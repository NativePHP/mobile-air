<?php

use Native\Mobile\Http\Bridge\BridgeDispatcher;
use Native\Mobile\Http\Bridge\RequestBodyParser;
use SupaNative\Core\Http\Bridge\RequestBodyParser as CoreRequestBodyParser;
use Symfony\Component\Process\Process;

// The classes that read a request body live in supanative/core
// now, and their old names are aliases. BridgeDispatcher is
// still mobile's own, as the shells call it by its name.

it('answers to an old name with the class in core', function () {
    expect((new ReflectionClass(RequestBodyParser::class))->getName())->toBe(CoreRequestBodyParser::class);
});

it('keeps BridgeDispatcher as a class of its own', function () {
    expect((new ReflectionClass(BridgeDispatcher::class))->getName())->toBe('Native\Mobile\Http\Bridge\BridgeDispatcher');
});

it('passes a type check on an old name nothing asked for, once another old name was used', function () {
    $autoload = var_export(dirname(__DIR__, 4).'/vendor/autoload.php', true);

    $process = new Process([PHP_BINARY, '-r', "require {$autoload}; \$parsed = (new Native\\Mobile\\Http\\Bridge\\RequestBodyParser)->parse('POST', 'application/x-www-form-urlencoded', 'a=1'); echo (fn (Native\\Mobile\\Http\\Bridge\\ParsedBody \$body) => \$body::class)(\$parsed);"]);
    $process->mustRun();

    expect($process->getOutput())->toBe('SupaNative\Core\Http\Bridge\ParsedBody');
});
