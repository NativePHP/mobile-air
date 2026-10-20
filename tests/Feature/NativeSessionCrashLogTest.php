<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Edge\NativeRouter;

/**
 * When PHP dies part-way through a native session (a fatal error, most often
 * the memory limit), iOS reboots it and restarts the session, and leaves what
 * PHP printed in storage for the new session to log. PHP can't log it as it
 * dies: Laravel reports fatal errors from a shutdown function, and the
 * persistent runtime's request never shuts down.
 */
beforeEach(function () {
    $this->crashOutput = storage_path(NativeRouter::CRASH_OUTPUT_PATH);
    File::ensureDirectoryExists(dirname($this->crashOutput));
    File::delete($this->crashOutput);
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

it('reads the file iOS writes', function () {
    $swift = File::get(__DIR__.'/../../resources/xcode/NativePHP/Bridge/NativeSession.swift');

    expect($swift)->toContain('static let crashOutputPath = "storage/'.NativeRouter::CRASH_OUTPUT_PATH.'"');
});
