<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Edge\NativeRouter;

/**
 * When PHP dies part-way through a native session (a fatal error, most often
 * the memory limit), the app reboots it and restarts the session, and leaves
 * what PHP printed in storage for the new session to log. PHP can't log it as
 * it dies: Laravel reports fatal errors from a shutdown function, and the
 * persistent runtime's request never shuts down.
 */
beforeEach(function () {
    $this->crashOutput = storage_path(NativeRouter::CRASH_OUTPUT_PATH);
    File::ensureDirectoryExists(dirname($this->crashOutput));
    File::delete($this->crashOutput);

    $this->spool = storage_path('framework/devtools/spool.jsonl');
    File::delete($this->spool);
});

afterEach(function () {
    File::delete($this->spool);
});

it('logs why the last native session died, once', function () {
    Log::spy();
    File::put($this->crashOutput, "\nPHP Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 1310720 bytes) in Element.php on line 856\n");

    NativeRouter::logRestartAfterCrash();
    NativeRouter::logRestartAfterCrash();

    Log::shouldHaveReceived('error')->once()->with(
        'The native session died part-way and was restarted',
        ['output' => 'PHP Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 1310720 bytes) in Element.php on line 856'],
    );
    expect($this->crashOutput)->not->toBeFile();
});

it('hands the fatal to the devtools spool', function () {
    require_once __DIR__.'/../../bootstrap/shared/devtools-boot-report.php';
    Log::spy();
    File::put($this->crashOutput, "<partial render>\nPHP Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 1310720 bytes) in /app/Devnull/CachedRow.php on line 66\n");

    NativeRouter::logRestartAfterCrash();

    $event = json_decode(trim(File::get($this->spool)), true);

    expect($event['kind'])->toBe('fatal')
        ->and($event['exception'])->toMatchArray([
            'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 1310720 bytes)',
            'file' => '/app/Devnull/CachedRow.php',
            'line' => 66,
        ]);
});

it('reads the file iOS writes', function () {
    $swift = File::get(__DIR__.'/../../resources/xcode/NativePHP/Bridge/NativeSession.swift');

    expect($swift)->toContain('static let crashOutputPath = "storage/'.NativeRouter::CRASH_OUTPUT_PATH.'"');
});

it('reads the file Android writes', function () {
    $kotlin = File::get(__DIR__.'/../../resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/NativeSession.kt');

    expect($kotlin)->toContain('const val CRASH_OUTPUT_PATH = "persisted_data/storage/'.NativeRouter::CRASH_OUTPUT_PATH.'"');
});
