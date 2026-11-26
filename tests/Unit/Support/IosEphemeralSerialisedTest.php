<?php

/**
 * iOS's ephemeral runtime has one work slot: the command, and the result its thread writes back.
 * Callers overlap (a BGTask, an App Intent, a plugin's bridge call), so each holds g_ephemeral_mutex
 * for its whole round trip, and takes its result out of the slot. Without that, overlapping callers
 * run each other's commands, and two can be handed the same buffer and both free it (#433).
 */
function iosEphemeralBody(string $signature): string
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

it('holds the ephemeral mutex for every call into the ephemeral thread', function (string $signature) {
    expect(iosEphemeralBody($signature))->toContain('pthread_mutex_lock(&g_ephemeral_mutex);');
})->with([
    'boot' => 'int ephemeral_php_boot(const char *bootstrapPath)',
    'artisan' => 'const char *ephemeral_php_artisan(const char *command)',
    'shutdown' => 'void ephemeral_php_shutdown(void)',
]);

it('takes the artisan result out of the shared slot before releasing the mutex', function () {
    $body = iosEphemeralBody('const char *ephemeral_php_artisan(const char *command)');

    $taken = strpos($body, 'ephemeral_work_str_result = NULL;', strpos($body, 'dispatch_semaphore_wait'));

    expect($taken)->not->toBeFalse()
        ->and($taken)->toBeLessThan(strrpos($body, 'pthread_mutex_unlock(&g_ephemeral_mutex);'))
        ->and($body)->not->toContain('return ephemeral_work_str_result;');
});

it('does not start a second ephemeral thread when one is already booted', function () {
    expect(iosEphemeralBody('int ephemeral_php_boot(const char *bootstrapPath)'))
        ->toMatch('/pthread_mutex_lock\(&g_ephemeral_mutex\);\s+if \(ephemeral_initialized\) \{/');
});
