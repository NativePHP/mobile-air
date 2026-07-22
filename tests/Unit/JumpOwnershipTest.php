<?php

use Native\Mobile\Commands\JumpCommand;

/**
 * isJumpOwnedProcess() is the identity gate for every kill in teardown — a
 * wrong `true` executes an innocent process, a wrong `false` strands ports
 * (the ppid===1 regression left the whole PHP_CLI_SERVER_WORKERS pool holding
 * 8000 on every shutdown). Pin the command-line classification here.
 *
 * The JUMP_BRIDGE_PORT environment branch needs a live process to read the
 * env of (ps -Ewww / /proc), so it is exercised by the end-to-end flow, not
 * here; pid=0 skips it, which is also the safe default the method must keep.
 */
function isJumpOwned(string $command, int $ppid, int $pid = 0): bool
{
    $method = new ReflectionMethod(JumpCommand::class, 'isJumpOwnedProcess');

    return $method->invoke(new JumpCommand, $command, $ppid, $pid);
}

it('owns the jump router and bridge scripts', function (string $command) {
    expect(isJumpOwned($command, 42))->toBeTrue();
})->with([
    'router' => 'php -S 0.0.0.0:3000 /app/vendor/nativephp/mobile/resources/jump/router.php',
    'bridge' => 'php /app/vendor/nativephp/mobile/resources/jump/websocket-server.php /app 3001 3002 3003 start',
    'mdns advertiser' => '/usr/bin/dns-sd -R native _jump._tcp local 3000 host=192.168.1.10',
]);

it('owns Workerman workers carrying the Jump prefix despite the rewritten process title', function () {
    expect(isJumpOwned('WorkerMan: worker process JumpBridge websocket://0.0.0.0:3001', 1))->toBeTrue()
        ->and(isJumpOwned('WorkerMan: worker process JumpViteProxy websocket://0.0.0.0:3003', 1))->toBeTrue()
        ->and(isJumpOwned('WorkerMan: worker process SomeoneElses websocket://0.0.0.0:9000', 1))->toBeFalse();
});

it('owns our artisan serve (spawned with --no-reload) but not a plain one', function () {
    expect(isJumpOwned("php artisan serve --port=8000 --host=127.0.0.1 --no-interaction --no-reload", 42))->toBeTrue()
        ->and(isJumpOwned('php artisan serve --port=8000', 42))->toBeFalse();
});

it('owns an orphaned loopback php -S server.php as the no-env fallback only', function () {
    $worker = '/usr/bin/php84 -S 127.0.0.1:8000 /app/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';

    // Orphaned to init: recognisable even without reading its environment.
    expect(isJumpOwned($worker, 1))->toBeTrue();

    // Parented (the PHP_CLI_SERVER_WORKERS pool shape) with NO pid to read the
    // environment from: must stay false — never kill on the command line alone,
    // it is identical for a user's own artisan serve.
    expect(isJumpOwned($worker, 4419))->toBeFalse();
});

it('never owns unrelated processes', function (string $command) {
    expect(isJumpOwned($command, 1))->toBeFalse();
})->with([
    'random server' => 'node /usr/local/bin/vite --port 3000',
    'docker proxy' => '/usr/bin/docker-proxy -proto tcp -host-port 8000',
    'boost mcp' => 'php artisan boost:mcp',
]);
