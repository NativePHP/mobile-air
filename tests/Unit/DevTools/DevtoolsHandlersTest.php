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
