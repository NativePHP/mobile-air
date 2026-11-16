<?php

/**
 * The device queue worker boots only when there is work for it. PHP tells
 * native in two ways: a `QueueWorker.Wake` bridge call on every push, and a
 * marker file that survives the app closing. The worker asks
 * `native:queue:idle` before it goes back to sleep.
 */

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Native\Mobile\Support\AsyncPoolConfig;
use Native\Mobile\Support\QueueWorkerSignal;
use Native\Mobile\Testing\FakeBridge;

class QueueWorkerSignalTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void {}
}

beforeEach(function () {
    config([
        'nativephp-internal.running' => true,
        'database.default' => 'testing',
        'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
    ]);

    Schema::connection('testing')->create('jobs', function ($table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    QueueWorkerSignal::clearPending();
    FakeBridge::enable();
});

afterEach(function () {
    QueueWorkerSignal::clearPending();
    FakeBridge::disable();
});

it('wakes the worker and leaves the marker when a job is pushed', function () {
    QueueWorkerSignalTestJob::dispatch();

    FakeBridge::current()->assertCalled('QueueWorker.Wake');
    expect(QueueWorkerSignal::hasPending())->toBeTrue()
        ->and(DB::connection('testing')->table('jobs')->count())->toBe(1);
});

it('does not wake the worker for a sync queue', function () {
    config(['queue.default' => 'sync', 'queue.connections.sync' => ['driver' => 'sync']]);

    QueueWorkerSignalTestJob::dispatch();

    expect(FakeBridge::current()->callsTo('QueueWorker.Wake'))->toBeEmpty()
        ->and(QueueWorkerSignal::hasPending())->toBeFalse();
});

it('does not wake the worker for a connection it does not work', function () {
    config(['queue.connections.other' => config('queue.connections.database')]);

    QueueWorkerSignalTestJob::dispatch()->onConnection('other');

    expect(FakeBridge::current()->callsTo('QueueWorker.Wake'))->toBeEmpty()
        ->and(QueueWorkerSignal::hasPending())->toBeFalse();
});

it('does not wake anything off device', function () {
    config(['nativephp-internal.running' => false]);

    QueueWorkerSignalTestJob::dispatch();

    expect(FakeBridge::current()->callsTo('QueueWorker.Wake'))->toBeEmpty()
        ->and(QueueWorkerSignal::hasPending())->toBeFalse();
});

it('reports an empty queue and removes the marker so the next launch boots nothing', function () {
    QueueWorkerSignal::markPending();

    expect(QueueWorkerSignal::idleStatus())->toBe(['pending' => 0, 'next' => null])
        ->and(QueueWorkerSignal::hasPending())->toBeFalse();
});

it('keeps the marker and says a job is due now', function () {
    QueueWorkerSignalTestJob::dispatch();

    expect(QueueWorkerSignal::idleStatus())->toBe(['pending' => 1, 'next' => 0])
        ->and(QueueWorkerSignal::hasPending())->toBeTrue();
});

it('says when a delayed job is due', function () {
    QueueWorkerSignalTestJob::dispatch()->delay(now()->addMinutes(10));

    $status = QueueWorkerSignal::idleStatus();

    expect($status['pending'])->toBe(1)
        ->and($status['next'])->toBeGreaterThan(590)->toBeLessThanOrEqual(600)
        ->and(QueueWorkerSignal::hasPending())->toBeTrue();
});

it('counts a reserved job as due when its retry_after runs out', function () {
    DB::connection('testing')->table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 1,
        'reserved_at' => time() - 30,
        'available_at' => time() - 30,
        'created_at' => time() - 30,
    ]);

    $status = QueueWorkerSignal::idleStatus();

    expect($status['pending'])->toBe(1)
        ->and($status['next'])->toBeGreaterThan(55)->toBeLessThanOrEqual(60);
});

it('keeps the marker when the queue cannot be read', function () {
    Schema::connection('testing')->drop('jobs');
    QueueWorkerSignal::markPending();

    expect(QueueWorkerSignal::idleStatus())->toBe(['pending' => null, 'next' => null])
        ->and(QueueWorkerSignal::hasPending())->toBeTrue();
});

it('prints the idle status as one line of JSON for the worker', function () {
    QueueWorkerSignalTestJob::dispatch();

    Artisan::call('native:queue:idle');

    expect(json_decode(trim(Artisan::output()), true))->toBe(['pending' => 1, 'next' => 0]);
});

it('writes the marker where both native workers look for it', function () {
    $ios = file_get_contents(dirname(__DIR__, 2).'/resources/xcode/NativePHP/Bridge/PHPQueueWorker.swift');
    $android = file_get_contents(dirname(__DIR__, 2).'/resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/PHPQueueWorker.kt');

    expect(QueueWorkerSignal::markerPath())->toBe(storage_path('framework/queue_pending'))
        // iOS storage is Application Support/storage; Android's is app_storage/persisted_data/storage.
        ->and($ios)->toContain('"storage/framework/queue_pending"')
        ->and($android)->toContain('"persisted_data/storage/framework/queue_pending"');
});

it('registers QueueWorker.Wake on both platforms', function () {
    $root = dirname(__DIR__, 2).'/resources/';

    expect(file_get_contents($root.'xcode/NativePHP/Bridge/BridgeFunctionRegistration.swift'))
        ->toContain('registry.register("QueueWorker.Wake", function: QueueWorkerFunctions.Wake())')
        ->and(file_get_contents($root.'androidstudio/app/src/main/java/com/nativephp/mobile/bridge/BridgeFunctionRegistration.kt'))
        ->toContain('registry.register("QueueWorker.Wake", QueueWorkerFunctions.Wake())');
});

it('clamps the async pool size between one and the C lane ceiling', function (mixed $configured, int $expected) {
    config(['nativephp.async.max_slots' => $configured]);

    expect(AsyncPoolConfig::maxSlots())->toBe($expected);
})->with([
    'default' => [null, 2],
    'one' => [1, 1],
    'too many' => [16, 4],
    'zero' => [0, 1],
]);

it('defaults the async pool to two slots and a 60 second idle timeout', function () {
    $config = require dirname(__DIR__, 2).'/config/nativephp.php';

    expect($config['async'])->toBe(['max_slots' => 2, 'idle_timeout' => 60]);
});

/**
 * Jobs queued by a version that wrote no marker would sit until the next push.
 * Both platforms write the marker where they already run migrations for a newly
 * extracted bundle, so the worker runs once after an upgrade.
 */
it('marks jobs as pending after a new bundle is extracted on both platforms', function () {
    $root = dirname(__DIR__, 2).'/resources/';

    $ios = file_get_contents($root.'xcode/NativePHP/AppUpdateManager.swift');
    $iosMigrate = substr($ios, strpos($ios, 'private func runMigrationsAndClearCaches()'));

    $android = file_get_contents($root.'androidstudio/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt');
    $androidMigrate = substr($android, strpos($android, 'private fun runBaseArtisanCommands()'));

    expect(strpos($iosMigrate, 'PHPQueueWorker.markPending()'))->toBeGreaterThan(strpos($iosMigrate, '"migrate", "--force"'))
        ->and(strpos($androidMigrate, 'PHPQueueWorker.markPending(context)'))->toBeGreaterThan(strpos($androidMigrate, 'migrate --force'));
});
