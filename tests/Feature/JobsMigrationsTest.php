<?php

use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/**
 * The package ships its own create_jobs_table for apps that deleted theirs.
 * Named 0001_01_01_000001 it sorted ahead of the app's
 * 0001_01_01_000002_create_jobs_table, which then failed with "table jobs
 * already exists" and took every later app migration down with it. Dated
 * app migrations, like Laravel 10's, sorted after 0001_01_01_000003 too, so
 * the package's are now dated 9999 to run after anything an app has.
 *
 * These run the real migrations against SQLite, the only database the
 * package's migrations ever meet on device. The app's migrations are
 * verbatim copies of laravel/laravel's current default and Laravel 10's
 * skeleton and queue:table stub.
 */
function packageMigrationsPath(string $file = ''): string
{
    return rtrim(__DIR__.'/../../database/migrations/'.$file, '/');
}

function appMigrationsPath(string $file = ''): string
{
    return rtrim(__DIR__.'/../Fixtures/migrations/'.$file, '/');
}

function runMigrations(string ...$paths): void
{
    $migrator = app('migrator');

    if (! $migrator->repositoryExists()) {
        $migrator->getRepository()->createRepository();
    }

    $migrator->run($paths);
}

function reshapeMigration(): object
{
    return require packageMigrationsPath('9999_12_31_000001_reshape_failed_jobs_table.php');
}

/**
 * The tables each migration created, keyed by migration in the order they ran.
 */
function tablesCreatedByEachMigration(Closure $run): array
{
    $created = [];
    $running = null;

    Event::listen(MigrationStarted::class, function (MigrationStarted $event) use (&$created, &$running) {
        $running = basename((new ReflectionObject($event->migration))->getFileName(), '.php');
        $created[$running] = [];
    });

    DB::listen(function (QueryExecuted $query) use (&$created, &$running) {
        if ($running && preg_match('/^create table "(\w+)"/', $query->sql, $match)) {
            $created[$running][] = $match[1];
        }
    });

    $run();

    return $created;
}

/**
 * The CREATE statements SQLite holds for the queue tables and their indexes.
 */
function queueTablesSchema(): array
{
    return collect(DB::select(
        "select name, sql from sqlite_master where tbl_name in ('jobs', 'job_batches', 'failed_jobs') order by name"
    ))->pluck('sql', 'name')->all();
}

/**
 * What the app's own migration produces on an empty database, captured and
 * then dropped again so the test starts from nothing.
 */
function laravelQueueTablesSchema(): array
{
    $migration = require appMigrationsPath('0001_01_01_000002_create_jobs_table.php');

    $migration->up();
    $schema = queueTablesSchema();
    $migration->down();

    return $schema;
}

function expectLaravelQueueTables(array $laravel): void
{
    expect(Schema::getColumnType('jobs', 'attempts'))->toBe('integer')
        ->and(Schema::getColumnType('failed_jobs', 'connection'))->toBe('varchar')
        ->and(Schema::getColumnType('failed_jobs', 'queue'))->toBe('varchar')
        ->and(Schema::hasIndex('failed_jobs', ['connection', 'queue', 'failed_at']))->toBeTrue()
        ->and(queueTablesSchema())->toBe($laravel);
}

it('runs after the app\'s own jobs migration instead of clashing with it', function () {
    $laravel = laravelQueueTablesSchema();

    runMigrations(appMigrationsPath('0001_01_01_000002_create_jobs_table.php'), packageMigrationsPath());

    expect(app('migrator')->getRepository()->getRan())->toBe([
        '0001_01_01_000002_create_jobs_table',
        '9999_12_31_000000_create_jobs_table',
        '9999_12_31_000001_reshape_failed_jobs_table',
    ]);

    expectLaravelQueueTables($laravel);
});

it('runs after an app\'s dated queue migrations in the same migrate', function () {
    $laravel = laravelQueueTablesSchema();

    // Laravel 10's skeleton ships failed_jobs; jobs comes from queue:table.
    $created = tablesCreatedByEachMigration(fn () => runMigrations(
        appMigrationsPath('2019_08_19_000000_create_failed_jobs_table.php'),
        appMigrationsPath('2023_06_14_093012_create_jobs_table.php'),
        packageMigrationsPath(),
    ));

    expect($created)->toBe([
        '2019_08_19_000000_create_failed_jobs_table' => ['failed_jobs'],
        '2023_06_14_093012_create_jobs_table' => ['jobs'],
        '9999_12_31_000000_create_jobs_table' => ['job_batches'],
        '9999_12_31_000001_reshape_failed_jobs_table' => ['failed_jobs'],
    ]);

    expectLaravelQueueTables($laravel);
});

it('brings tables an older install created up to Laravel\'s shape', function () {
    $laravel = laravelQueueTablesSchema();

    runMigrations(packageMigrationsPath('9999_12_31_000000_create_jobs_table.php'));

    expect(Schema::getColumnType('failed_jobs', 'connection'))->toBe('text')
        ->and(Schema::hasIndex('failed_jobs', ['connection', 'queue', 'failed_at']))->toBeFalse();

    $failedJob = [
        'id' => 7,
        'uuid' => 'a0b1c2d3-e4f5-4a6b-8c7d-9e0f1a2b3c4d',
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"SendReminder"}',
        'exception' => 'RuntimeException: offline',
        'failed_at' => '2026-09-01 12:00:00',
    ];
    DB::table('failed_jobs')->insert($failedJob);

    runMigrations(packageMigrationsPath('9999_12_31_000001_reshape_failed_jobs_table.php'));

    expectLaravelQueueTables($laravel);
    expect((array) DB::table('failed_jobs')->first())->toBe($failedJob);
});

it('creates the tables in Laravel\'s shape when the app has no jobs migration', function () {
    $laravel = laravelQueueTablesSchema();

    runMigrations(packageMigrationsPath());

    expectLaravelQueueTables($laravel);
});

it('leaves tables alone when the reshape runs again', function () {
    $laravel = laravelQueueTablesSchema();

    runMigrations(packageMigrationsPath());

    DB::enableQueryLog();
    reshapeMigration()->up();

    expect(collect(DB::getQueryLog())->pluck('query')->implode("\n"))->not->toContain('failed_jobs_old');
    expectLaravelQueueTables($laravel);
});

it('does nothing when there are no queue tables', function () {
    reshapeMigration()->up();

    expect(queueTablesSchema())->toBe([]);
});
