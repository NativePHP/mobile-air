<?php

use Illuminate\Config\Repository;
use Native\Mobile\Support\SqliteDefaults;

const SQLITE_DEFAULTS_MANAGED_DB = '/data/app/persisted_data/database/database.sqlite';

function sqliteDefaultsConfig(array $connections, string $default = 'sqlite'): Repository
{
    return new Repository(['database' => ['default' => $default, 'connections' => $connections]]);
}

function sqliteDefaultsConnection(array $overrides = []): array
{
    // Mirrors Laravel 11+'s stock config/database.php sqlite connection.
    return array_merge([
        'driver' => 'sqlite',
        'url' => null,
        'database' => SQLITE_DEFAULTS_MANAGED_DB,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => null,
        'journal_mode' => null,
        'synchronous' => null,
    ], $overrides);
}

it('fills journal_mode and synchronous on the managed connection when they are null', function () {
    $config = sqliteDefaultsConfig(['sqlite' => sqliteDefaultsConnection()]);

    expect(SqliteDefaults::apply($config, SQLITE_DEFAULTS_MANAGED_DB))->toBe(['sqlite']);

    expect($config->get('database.connections.sqlite.journal_mode'))->toBe('wal')
        ->and($config->get('database.connections.sqlite.synchronous'))->toBe('normal');
});

it('fills the keys when the connection does not define them at all (Laravel 10 style config)', function () {
    $config = sqliteDefaultsConfig(['sqlite' => ['driver' => 'sqlite', 'database' => SQLITE_DEFAULTS_MANAGED_DB, 'prefix' => '']]);

    SqliteDefaults::apply($config, SQLITE_DEFAULTS_MANAGED_DB);

    expect($config->get('database.connections.sqlite'))
        ->toMatchArray(['journal_mode' => 'wal', 'synchronous' => 'normal']);
});

it('never sets busy_timeout, leaving pdo_sqlite\'s 60s default in place', function () {
    $config = sqliteDefaultsConfig(['sqlite' => sqliteDefaultsConnection()]);

    SqliteDefaults::apply($config, SQLITE_DEFAULTS_MANAGED_DB);

    expect($config->get('database.connections.sqlite.busy_timeout'))->toBeNull();
});

it('leaves values the developer configured alone', function () {
    $config = sqliteDefaultsConfig(['sqlite' => sqliteDefaultsConnection(['journal_mode' => 'delete', 'synchronous' => 'full', 'busy_timeout' => 1000])]);

    expect(SqliteDefaults::apply($config, SQLITE_DEFAULTS_MANAGED_DB))->toBe([]);

    expect($config->get('database.connections.sqlite'))
        ->toMatchArray(['journal_mode' => 'delete', 'synchronous' => 'full', 'busy_timeout' => 1000]);
});

it('fills only the key the developer left unset', function () {
    $config = sqliteDefaultsConfig(['sqlite' => sqliteDefaultsConnection(['journal_mode' => 'truncate'])]);

    SqliteDefaults::apply($config, SQLITE_DEFAULTS_MANAGED_DB);

    expect($config->get('database.connections.sqlite'))
        ->toMatchArray(['journal_mode' => 'truncate', 'synchronous' => 'normal']);
});

it('treats the same pragma in the connection\'s pragmas array as configured', function () {
    $config = sqliteDefaultsConfig(['sqlite' => sqliteDefaultsConnection(['pragmas' => ['JOURNAL_MODE' => 'delete']])]);

    SqliteDefaults::apply($config, SQLITE_DEFAULTS_MANAGED_DB);

    expect($config->get('database.connections.sqlite.journal_mode'))->toBeNull()
        ->and($config->get('database.connections.sqlite.synchronous'))->toBe('normal');
});

it('only touches connections pointing at the database the shell manages', function () {
    $config = sqliteDefaultsConfig([
        'sqlite' => sqliteDefaultsConnection(),
        'same_file' => sqliteDefaultsConnection(),
        'bundled_readonly' => sqliteDefaultsConnection(['database' => '/data/app/laravel/database/lookup.sqlite']),
        'memory' => sqliteDefaultsConnection(['database' => ':memory:']),
        'named_memory' => sqliteDefaultsConnection(['database' => 'file:cache?mode=memory&cache=shared']),
        'url' => sqliteDefaultsConnection(['url' => 'sqlite:///'.SQLITE_DEFAULTS_MANAGED_DB]),
        'mysql' => ['driver' => 'mysql', 'database' => SQLITE_DEFAULTS_MANAGED_DB],
    ]);

    expect(SqliteDefaults::apply($config, SQLITE_DEFAULTS_MANAGED_DB))->toBe(['sqlite', 'same_file']);

    foreach (['bundled_readonly', 'memory', 'named_memory', 'url'] as $name) {
        expect($config->get("database.connections.{$name}.journal_mode"))->toBeNull("{$name} was touched");
    }

    expect($config->get('database.connections.mysql'))->not->toHaveKey('journal_mode');
});

it('matches the managed database through symlinks and relative segments', function () {
    $dir = sys_get_temp_dir().'/nativephp-sqlite-defaults-'.uniqid();
    mkdir($dir.'/real', 0777, true);
    touch($dir.'/real/database.sqlite');
    symlink($dir.'/real', $dir.'/link');

    try {
        $config = sqliteDefaultsConfig(['sqlite' => sqliteDefaultsConnection(['database' => $dir.'/link/../real/database.sqlite'])]);

        expect(SqliteDefaults::apply($config, $dir.'/link/database.sqlite'))->toBe(['sqlite']);
    } finally {
        unlink($dir.'/link');
        unlink($dir.'/real/database.sqlite');
        rmdir($dir.'/real');
        rmdir($dir);
    }
});

it('falls back to the default connection when no managed path is known', function () {
    $config = sqliteDefaultsConfig([
        'sqlite' => sqliteDefaultsConnection(['database' => '/somewhere/app.sqlite']),
        'other' => sqliteDefaultsConnection(['database' => '/somewhere/other.sqlite']),
    ]);

    expect(SqliteDefaults::apply($config, null))->toBe(['sqlite']);
    expect($config->get('database.connections.other.journal_mode'))->toBeNull();
});

it('does nothing when the default connection is not sqlite and no path is known', function () {
    $config = sqliteDefaultsConfig(['mysql' => ['driver' => 'mysql']], 'mysql');

    expect(SqliteDefaults::apply($config, null))->toBe([]);
});

it('copes with a missing connections array', function () {
    expect(SqliteDefaults::apply(new Repository([]), SQLITE_DEFAULTS_MANAGED_DB))->toBe([]);
});
