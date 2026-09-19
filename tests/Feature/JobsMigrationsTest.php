<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The package ships its own create_jobs_table for apps that deleted theirs.
 * Named 0001_01_01_000001 it sorted ahead of the app's
 * 0001_01_01_000002_create_jobs_table, which then failed with "table jobs
 * already exists" and took every later app migration down with it.
 *
 * These run the real migrations against SQLite, the only database the
 * package's migrations ever meet on device. The app's migration is a
 * verbatim copy of laravel/laravel's current default.
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
    return require packageMigrationsPath('0001_01_01_000004_reshape_failed_jobs_table.php');
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

    runMigrations(appMigrationsPath(), packageMigrationsPath());

    expect(app('migrator')->getRepository()->getRan())->toBe([
        '0001_01_01_000002_create_jobs_table',
        '0001_01_01_000003_create_jobs_table',
        '0001_01_01_000004_reshape_failed_jobs_table',
    ]);

    expectLaravelQueueTables($laravel);
});

it('brings tables an older install created up to Laravel\'s shape', function () {
    $laravel = laravelQueueTablesSchema();

    runMigrations(packageMigrationsPath('0001_01_01_000003_create_jobs_table.php'));

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

    runMigrations(packageMigrationsPath('0001_01_01_000004_reshape_failed_jobs_table.php'));

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
