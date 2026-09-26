<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Database\SQLiteConnection as BaseSQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Native\Mobile\Database\SQLiteConnection;
use Native\Mobile\NativeServiceProvider;

/*
 * The provider's on-device SQLite wiring: WAL defaults for the managed
 * database, and a db:wipe that stays safe once that database is in WAL mode.
 */

function forgetSqliteResolver(): void
{
    $resolvers = new ReflectionProperty(Connection::class, 'resolvers');
    $resolvers->setAccessible(true);
    $all = $resolvers->getValue();
    unset($all['sqlite']);
    $resolvers->setValue(null, $all);
}

function runOnDeviceSqliteWiring($app): void
{
    $provider = $app->getProvider(NativeServiceProvider::class);

    (fn () => $this->applyOnDeviceSqliteDefaults())->call($provider);
    (fn () => $this->registerWalSafeSqliteConnection())->call($provider);
}

function sqlitePragma(Connection $db, string $pragma): mixed
{
    $row = (array) $db->selectOne("pragma {$pragma}");

    return reset($row);
}

beforeEach(function () {
    forgetSqliteResolver();

    $this->dir = sys_get_temp_dir().'/nativephp-ondevice-sqlite-'.uniqid();
    mkdir($this->dir);
    $this->db = $this->dir.'/database.sqlite';
    touch($this->db);

    // The shell exports DB_DATABASE; phpunit.xml sets it to :memory:.
    $this->previousDbDatabase = [getenv('DB_DATABASE'), $_ENV['DB_DATABASE'] ?? null, $_SERVER['DB_DATABASE'] ?? null];
    putenv('DB_DATABASE='.$this->db);
    $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $this->db;

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => $this->db,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],
    ]);
    DB::purge('sqlite');
});

afterEach(function () {
    DB::purge('sqlite');
    forgetSqliteResolver();
    [$getenv, $env, $server] = $this->previousDbDatabase;
    $getenv === false ? putenv('DB_DATABASE') : putenv('DB_DATABASE='.$getenv);
    $env === null ? $_ENV = array_diff_key($_ENV, ['DB_DATABASE' => 1]) : $_ENV['DB_DATABASE'] = $env;
    $server === null ? $_SERVER = array_diff_key($_SERVER, ['DB_DATABASE' => 1]) : $_SERVER['DB_DATABASE'] = $server;

    foreach (glob($this->dir.'/*') as $file) {
        unlink($file);
    }
    rmdir($this->dir);
});

it('changes nothing off device', function () {
    config(['nativephp-internal.running' => false]);

    runOnDeviceSqliteWiring($this->app);

    expect(config('database.connections.sqlite.journal_mode'))->toBeNull()
        ->and(config('database.connections.sqlite.synchronous'))->toBeNull()
        ->and(Connection::getResolver('sqlite'))->toBeNull()
        ->and(DB::connection('sqlite'))->not->toBeInstanceOf(SQLiteConnection::class);
});

it('defaults the managed connection to WAL on device and resolves core\'s connection', function () {
    config(['nativephp-internal.running' => true]);

    runOnDeviceSqliteWiring($this->app);

    expect(config('database.connections.sqlite.journal_mode'))->toBe('wal')
        ->and(config('database.connections.sqlite.synchronous'))->toBe('normal')
        ->and(config('database.connections.sqlite.busy_timeout'))->toBeNull()
        ->and(DB::connection('sqlite'))->toBeInstanceOf(SQLiteConnection::class);
});

it('keeps the developer\'s journal mode on device', function () {
    config(['nativephp-internal.running' => true, 'database.connections.sqlite.journal_mode' => 'delete']);

    runOnDeviceSqliteWiring($this->app);

    expect(config('database.connections.sqlite.journal_mode'))->toBe('delete')
        ->and(config('database.connections.sqlite.synchronous'))->toBe('normal');
});

it('does not replace a sqlite resolver someone else registered', function () {
    config(['nativephp-internal.running' => true]);
    $theirs = fn ($pdo, $database, $prefix, $config) => new BaseSQLiteConnection($pdo, $database, $prefix, $config);
    Connection::resolverFor('sqlite', $theirs);

    runOnDeviceSqliteWiring($this->app);

    expect(Connection::getResolver('sqlite'))->toBe($theirs);
});

it('applies the pragmas on connect (Laravel 11+)', function () {
    if (! method_exists(SQLiteConnector::class, 'configureJournalMode')) {
        $this->markTestSkipped('This Laravel version does not apply journal_mode/synchronous connection keys.');
    }

    config(['nativephp-internal.running' => true]);
    runOnDeviceSqliteWiring($this->app);

    $db = DB::connection('sqlite');

    expect(sqlitePragma($db, 'journal_mode'))->toBe('wal')
        ->and((int) sqlitePragma($db, 'synchronous'))->toBe(1)
        ->and((int) sqlitePragma($db, 'busy_timeout'))->toBe(60000);
});

it('wipes a WAL database in place, consistently for other open connections', function () {
    config(['nativephp-internal.running' => true]);
    runOnDeviceSqliteWiring($this->app);

    $db = DB::connection('sqlite');
    // Laravel 10's connector ignores the journal_mode key, so switch explicitly.
    $db->statement('pragma journal_mode = wal');

    Schema::connection('sqlite')->create('notes', fn ($t) => $t->id());
    $db->table('notes')->insert([['id' => 1], ['id' => 2]]);

    // Another runtime (queue worker, async slot) holding the file open keeps
    // the WAL alive, which is exactly when truncating the file breaks it.
    $other = new PDO('sqlite:'.$this->db);
    $other->query('select count(*) from notes')->fetchAll();
    expect(file_exists($this->db.'-wal'))->toBeTrue();

    Schema::connection('sqlite')->dropAllTables();

    expect(Schema::connection('sqlite')->hasTable('notes'))->toBeFalse()
        ->and(filesize($this->db))->toBeGreaterThan(0)
        ->and(sqlitePragma($db, 'integrity_check'))->toBe('ok')
        ->and($other->query("select count(*) from sqlite_master where name = 'notes'")->fetchColumn())->toBe(0);

    // Recreating the schema on the same connection works (the in-process
    // migrate:fresh case that used to fail with "file is not a database").
    Schema::connection('sqlite')->create('notes', fn ($t) => $t->id());
    $db->table('notes')->insert(['id' => 1]);
    expect($db->table('notes')->count())->toBe(1);

    $other = null;
    DB::purge('sqlite');

    $fresh = new PDO('sqlite:'.$this->db);
    expect($fresh->query('pragma integrity_check')->fetchColumn())->toBe('ok')
        ->and((int) $fresh->query('select count(*) from notes')->fetchColumn())->toBe(1);
});

it('keeps Laravel\'s truncate for databases that are not in WAL mode', function () {
    config(['nativephp-internal.running' => true, 'database.connections.sqlite.journal_mode' => 'delete']);
    runOnDeviceSqliteWiring($this->app);

    $db = DB::connection('sqlite');
    $db->statement('pragma journal_mode = delete');
    Schema::connection('sqlite')->create('notes', fn ($t) => $t->id());
    expect(filesize($this->db))->toBeGreaterThan(0);

    Schema::connection('sqlite')->dropAllTables();

    clearstatcache();
    expect(filesize($this->db))->toBe(0);
});
