<?php

use Native\Mobile\Support\EnvValue;

/**
 * Round-trip through the real phpdotenv parser — the same code path that
 * reads .env at bootstrap. If phpdotenv's quoting rules ever change, this
 * is the test that catches it.
 */
function parseEnvLine(string $line): array
{
    $dir = sys_get_temp_dir().'/env-value-test-'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($dir.'/.env', $line.PHP_EOL);

    try {
        return Dotenv\Dotenv::createArrayBacked($dir)->load();
    } finally {
        unlink($dir.'/.env');
        rmdir($dir);
    }
}

it('round-trips adversarial values through real phpdotenv', function (string $value) {
    $parsed = parseEnvLine('TEST_VALUE='.EnvValue::quote($value));

    expect($parsed['TEST_VALUE'])->toBe($value);
})->with(function () {
    $values = [];

    // Every ASCII punctuation character embedded in a password
    foreach (str_split('!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~') as $char) {
        $values["punctuation {$char}"] = "ab{$char}cd";
    }

    $values['interpolation braces'] = 'pre${HOME}post';
    $values['interpolation bare'] = '$HOME';
    $values['self-reference'] = '${ANDROID_KEYSTORE_PASSWORD}';
    $values['backslash runs'] = 'a\\\\b\\c\\';
    $values['mixed quotes'] = 'he said "hi" and \'bye\'';
    $values['leading/trailing spaces'] = '  padded  ';
    $values['hash heavy'] = '#a#b#c#';
    $values['unicode'] = 'pässwörd→日本語';
    $values['long mixed'] = str_repeat('aB3#$" \\', 25);
    $values['original ios repro'] = 'p@ss#w0rd';
    $values['plain alphanumeric'] = 'abc123XYZ';
    $values['file path'] = 'credentials/upload-keystore.jks';

    return $values;
});

it('flags values that need quoting', function () {
    expect(EnvValue::needsQuoting('p@ss#w0rd'))->toBeTrue()
        ->and(EnvValue::needsQuoting('has space'))->toBeTrue()
        ->and(EnvValue::needsQuoting('dollar$sign'))->toBeTrue()
        ->and(EnvValue::needsQuoting('back\\slash'))->toBeTrue()
        ->and(EnvValue::needsQuoting('exclaim!'))->toBeTrue()
        ->and(EnvValue::needsQuoting('abc123XYZ@%^&*()-_=+'))->toBeFalse();
});
