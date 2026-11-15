<?php

/**
 * Symfony Console reads $_SERVER['PHP_SELF'] as it runs a command. On iOS only a request sets it
 * on the persistent thread, so every artisan handler sets it itself: otherwise a command run before
 * the first screen, such as from an App Intent that launched the app in the background, fails with
 * "Undefined array key PHP_SELF".
 */
function iosArtisanHandlerBody(string $signature): string
{
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/xcode/Include/Bridge/PHP.c');
    $start = strpos($source, $signature.' {');
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

it('sets PHP_SELF before every iOS artisan call', function (string $handler) {
    expect(iosArtisanHandlerBody($handler))->toContain("\$_SERVER['PHP_SELF'] = 'artisan.php';");
})->with([
    'persistent' => 'static void do_artisan(const char *command)',
    'queue worker' => 'static void do_worker_artisan(const char *command)',
    'ephemeral' => 'static void do_ephemeral_artisan(const char *command)',
]);
