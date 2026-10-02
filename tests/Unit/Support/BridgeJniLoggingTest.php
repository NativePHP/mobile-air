<?php

/**
 * The Android JNI bridge must not write bridge payloads to logcat in release
 * builds. Parameters and results can carry tokens, keys and user data, and
 * logcat is readable over adb and in bug reports.
 *
 * Per-call tracing goes through LOGT, which is a no-op when NDEBUG is defined
 * (CMake's Release and RelWithDebInfo configurations, i.e. Gradle release
 * builds) and LOGI otherwise.
 */
function bridgeJniSource(): string
{
    return file_get_contents(dirname(__DIR__, 3).'/resources/androidstudio/app/src/main/cpp/bridge_jni.cpp');
}

function bridgeJniFunctionBody(string $source, string $signature): string
{
    $start = strpos($source, $signature);
    expect($start)->not->toBeFalse("{$signature} not found in bridge_jni.cpp");

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

it('compiles per-call tracing out of release builds', function () {
    expect(bridgeJniSource())->toMatch(
        '/#ifdef\s+NDEBUG\s+#define\s+LOGT\(\.\.\.\)\s+\(\(void\)\s*0\)\s+#else\s+#define\s+LOGT\(\.\.\.\)\s+__android_log_print\(/'
    );
});

it('only traces NativePHPCan and NativePHPCall through LOGT', function (string $signature) {
    expect(bridgeJniFunctionBody(bridgeJniSource(), $signature))->not->toContain('LOGI(');
})->with([
    'extern "C" int NativePHPCan(',
    'extern "C" const char* NativePHPCall(',
]);

it('never passes bridge parameters or results to an always-on log', function () {
    preg_match_all('/\bLOG[IE]\((.*?)\);/s', bridgeJniSource(), $calls);

    foreach ($calls[1] as $arguments) {
        expect($arguments)
            ->not->toContain('parametersJSON')
            ->not->toContain('resultStr');
    }
});
