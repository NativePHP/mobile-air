<?php

// The device-side reporting helpers are dependency-free plain functions in
// bootstrap/shared. We exercise them against a temp storage dir and by
// running child PHP processes so the real shutdown / exception handlers fire.

beforeEach(function () {
    require_once __DIR__.'/../../../bootstrap/shared/devtools-boot-report.php';

    $this->storage = sys_get_temp_dir().'/devtools-test-'.bin2hex(random_bytes(4));
    mkdir($this->storage.'/framework', 0755, true);
    $this->spool = $this->storage.'/framework/devtools/spool.jsonl';
});

afterEach(function () {
    if (is_dir($this->storage)) {
        exec('rm -rf '.escapeshellarg($this->storage));
    }
});

function runDevtoolsChild(string $storage, string $body): void
{
    $script = <<<PHP
    <?php
    require '{$_SERVER['DEVTOOLS_BOOTSTRAP']}';
    nativephp_devtools_install_handlers('{$storage}');
    {$body}
    PHP;

    $file = tempnam(sys_get_temp_dir(), 'devtools-child').'.php';
    file_put_contents($file, $script);
    exec('php '.escapeshellarg($file).' 2>/dev/null');
    @unlink($file);
}

beforeEach(function () {
    $_SERVER['DEVTOOLS_BOOTSTRAP'] = realpath(__DIR__.'/../../../bootstrap/shared/devtools-boot-report.php');
});

it('spools an event with the given kind and mode', function () {
    nativephp_devtools_write_event('exception', 'edge', [
        'class' => 'RuntimeException',
        'message' => 'boom',
        'file' => 'app/Foo.php',
        'line' => 7,
    ], $this->storage);

    $line = json_decode(trim(file_get_contents($this->spool)), true);

    expect($line['kind'])->toBe('exception')
        ->and($line['mode'])->toBe('edge')
        ->and($line['exception']['message'])->toBe('boom')
        ->and($line)->toHaveKeys(['v', 'id', 'ts', 'platform']);
});

it('reports an uncaught throwable via set_exception_handler', function () {
    runDevtoolsChild($this->storage, "throw new RuntimeException('uncaught in child');");

    $line = json_decode(trim(file_get_contents($this->spool)), true);

    expect($line['kind'])->toBe('exception')
        ->and($line['mode'])->toBe('uncaught')
        ->and($line['exception']['class'])->toBe('RuntimeException')
        ->and($line['exception']['message'])->toBe('uncaught in child');
});

it('reports a true PHP fatal via the shutdown handler', function () {
    runDevtoolsChild(
        $this->storage,
        "ini_set('memory_limit', (string)(memory_get_usage(true) + 6*1024*1024)); \$a = []; while (true) { \$a[] = str_repeat('x', 1024*1024); }"
    );

    $line = json_decode(trim(file_get_contents($this->spool)), true);

    expect($line['kind'])->toBe('fatal')
        ->and($line['mode'])->toBe('shutdown')
        ->and($line['exception']['message'])->toContain('memory');
});

it('does not double-report: one uncaught throwable yields one event', function () {
    runDevtoolsChild($this->storage, "throw new LogicException('once');");

    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->spool))));

    expect($lines)->toHaveCount(1);
});

it('writes nothing when the storage dir does not exist', function () {
    nativephp_devtools_write_event('fatal', 'shutdown', ['class' => 'X', 'message' => 'y', 'file' => 'z', 'line' => 1], '/no/such/dir');

    expect(is_file($this->spool))->toBeFalse();
});

it('stops appending once the spool passes its cap', function () {
    mkdir(dirname($this->spool), 0755, true);
    file_put_contents($this->spool, str_repeat('x', 5 * 1024 * 1024 + 1));

    $before = filesize($this->spool);

    // These handlers run in release builds too, so a crash-looping app must
    // not be able to fill the device's disk.
    nativephp_devtools_write_event('fatal', 'shutdown', [
        'class' => 'X', 'message' => 'over cap', 'file' => 'f', 'line' => 1,
    ], $this->storage);

    clearstatcache(true, $this->spool);

    expect(filesize($this->spool))->toBe($before);
});

it('picks the first non-vendor frame as the app frame', function () {
    // The throwable itself originates in vendor; the app frame must come
    // from the trace, which is what makes the event readable to a developer.
    $e = new RuntimeException('from vendor');

    $frame = nativephp_devtools_app_frame($e);

    // This test file is the app-side caller, and it is not under vendor/.
    expect($frame)->toContain('DevtoolsHandlersTest.php');
});

it('skips vendor frames and points at the caller in app code', function () {
    // A throwable raised inside vendor/ must not report the vendor file —
    // that's the framework's problem, not the line the developer can fix.
    $vendorFile = $this->storage.'/vendor/acme/thrower.php';
    mkdir(dirname($vendorFile), 0755, true);
    file_put_contents($vendorFile, '<?php function acme_throw() { throw new RuntimeException("deep"); }');

    require $vendorFile;

    try {
        acme_throw();
    } catch (RuntimeException $e) {
        $frame = nativephp_devtools_app_frame($e);
    }

    expect($frame)->not->toContain(DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
        ->and($frame)->toContain('DevtoolsHandlersTest.php');
});

it('carries the app frame into the spooled event', function () {
    nativephp_devtools_report_throwable(new RuntimeException('boom'), 'exception', 'edge', $this->storage);

    $line = json_decode(trim(file_get_contents($this->spool)), true);

    expect($line['exception']['app_frame'])->toContain('DevtoolsHandlersTest.php');
});

it('re-installs the exception handler without double-reporting', function () {
    // Laravel's HandleExceptions replaces our handler during bootstrap, so
    // the provider re-installs afterwards. Re-installing when ours is already
    // outermost must not stack a second reporter.
    runDevtoolsChild($this->storage, <<<PHP
        nativephp_devtools_install_exception_handler('{$this->storage}');
        nativephp_devtools_install_exception_handler('{$this->storage}');
        throw new LogicException('once only');
    PHP);

    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->spool))));

    expect($lines)->toHaveCount(1)
        ->and(json_decode($lines[0], true)['exception']['message'])->toBe('once only');
});

it('reports again after a replacing handler is installed over ours', function () {
    // The real sequence: we install, Laravel replaces us, we re-install.
    runDevtoolsChild($this->storage, <<<PHP
        set_exception_handler(function (\$e) { /* stand-in for Laravel */ });
        nativephp_devtools_install_exception_handler('{$this->storage}');
        throw new LogicException('after reinstall');
    PHP);

    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->spool))));

    expect($lines)->toHaveCount(1)
        ->and(json_decode($lines[0], true)['exception']['message'])->toBe('after reinstall');
});
