<?php

/**
 * The Android template pins the JDK the Gradle daemon runs on, so whatever
 * JAVA_HOME is, the build runs on a JDK its Gradle, AGP and Kotlin support.
 * The pin only works where that JDK is missing if Gradle can download it,
 * so every platform people build on needs a download URL.
 */
function daemonJvmCriteria(): array
{
    $path = dirname(__DIR__, 3).'/resources/androidstudio/gradle/gradle-daemon-jvm.properties';
    $properties = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $properties[trim($key)] = str_replace('\\:', ':', trim($value));
    }

    return $properties;
}

it('pins the Gradle daemon to a JDK the template supports', function () {
    expect((int) daemonJvmCriteria()['toolchainVersion'])->toBeGreaterThanOrEqual(17);
});

it('can download the pinned JDK on every platform people build on', function (string $platform) {
    expect(daemonJvmCriteria())->toHaveKey("toolchainUrl.{$platform}")
        ->and(daemonJvmCriteria()["toolchainUrl.{$platform}"])->toStartWith('https://');
})->with([
    'MAC_OS.AARCH64',
    'MAC_OS.X86_64',
    'LINUX.X86_64',
    'LINUX.AARCH64',
    'WINDOWS.X86_64',
]);
