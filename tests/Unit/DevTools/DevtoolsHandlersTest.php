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

it('rotates the spool past its cap instead of going silent', function () {
    mkdir(dirname($this->spool), 0755, true);
    file_put_contents($this->spool, str_repeat('x', 5 * 1024 * 1024 + 1));
    file_put_contents(dirname($this->spool).'/spool.cursor', '1234');

    // These handlers run in release builds too, so a crash-looping app must
    // not fill the disk — but it must also not disable reporting forever,
    // which is what refusing to append did (nothing ever truncates this).
    nativephp_devtools_write_event('fatal', 'shutdown', [
        'class' => 'X', 'message' => 'after rotation', 'file' => 'f', 'line' => 1,
    ], $this->storage);

    clearstatcache(true, $this->spool);

    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->spool))));

    expect($lines)->toHaveCount(1)
        ->and(json_decode($lines[0], true)['exception']['message'])->toBe('after rotation')
        ->and(is_file($this->spool.'.1'))->toBeTrue()
        // The cursor indexed the rotated-away file; leaving it would make the
        // drainer resume at a byte offset into unrelated content.
        ->and(is_file(dirname($this->spool).'/spool.cursor'))->toBeFalse();
});

it('leaves the spool alone below the cap', function () {
    nativephp_devtools_write_event('fatal', 'shutdown', [
        'class' => 'X', 'message' => 'first', 'file' => 'f', 'line' => 1,
    ], $this->storage);
    nativephp_devtools_write_event('fatal', 'shutdown', [
        'class' => 'X', 'message' => 'second', 'file' => 'f', 'line' => 1,
    ], $this->storage);

    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->spool))));

    expect($lines)->toHaveCount(2)
        ->and(is_file($this->spool.'.1'))->toBeFalse();
});

it('uses the throwable own file when that is already app code', function () {
    // Constructed here, and this file is not under vendor/ — so the first
    // branch answers and the trace is never consulted.
    $frame = nativephp_devtools_app_frame(new RuntimeException('from app'));

    expect($frame)->toBeString()
        ->and($frame)->toContain('DevtoolsHandlersTest.php');
});

it('walks past vendor frames to the caller in app code', function () {
    // The throwable ORIGINATES under vendor/, so getFile() is a vendor path
    // and only the trace walk can produce a useful frame. Deleting that walk
    // must fail this test — the earlier version of it passed either way,
    // because the exception was constructed in this file.
    $vendorFile = $this->storage.'/vendor/acme/thrower.php';
    mkdir(dirname($vendorFile), 0755, true);
    file_put_contents($vendorFile, '<?php function acme_throw() { throw new RuntimeException("deep"); }');

    require $vendorFile;

    $frame = null;

    try {
        acme_throw();
    } catch (RuntimeException $e) {
        expect($e->getFile())->toContain(DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR);

        $frame = nativephp_devtools_app_frame($e);
    }

    expect($frame)->toBeString()
        ->and($frame)->not->toContain(DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
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
