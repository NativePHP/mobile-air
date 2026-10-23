<?php

/**
 * Every PHP context gets php.ini's 128M. Only the persistent runtime raises its
 * own limit, to 512M, because the native session runs in it as a single
 * request that lives as long as the app. The queue worker, the async lane and
 * webview contexts keep 128M, so a runaway there fails on its own instead of
 * growing alongside the UI until the OS kills the whole app.
 */
function memoryLimitSource(string $path): string
{
    return file_get_contents(dirname(__DIR__, 3).'/resources/'.$path);
}

function memoryLimitFunctionBody(string $source, string $signature): string
{
    $start = strpos($source, $signature);
    expect($start)->not->toBeFalse("{$signature} not found");

    $open = strpos($source, '{', $start);
    $depth = 0;

    for ($i = $open, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}' && --$depth === 0) {
            return substr($source, $open, $i - $open + 1);
        }
    }

    throw new RuntimeException("Unbalanced braces after {$signature}");
}

it('gives every context 128M in php.ini', function (string $writer) {
    expect(memoryLimitSource($writer))
        ->toContain('memory_limit=128M')
        ->not->toContain('memory_limit=512M');
})->with([
    'xcode/NativePHP/Bridge/PersistentPHPRuntime.swift',
    'xcode/NativePHP/NativePHPApp.swift',
]);

it('raises the limit to 512M in the persistent runtime only', function (string $bridge, string $persistentBoot) {
    $source = memoryLimitSource($bridge);

    expect(memoryLimitFunctionBody($source, 'static void raise_persistent_memory_limit(void)'))->toContain('"512M"')
        ->and(substr_count($source, 'raise_persistent_memory_limit();'))->toBe(1)
        ->and(memoryLimitFunctionBody($source, $persistentBoot))->toContain('raise_persistent_memory_limit();');
})->with([
    'iOS' => ['xcode/Include/Bridge/PHP.c', 'static void *php_worker_main(void *arg)'],
]);
