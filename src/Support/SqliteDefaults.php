<?php

namespace Native\Mobile\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Faster defaults for the SQLite database the app keeps on the device.
 *
 * SQLite's stock rollback journal (DELETE) fsyncs a journal file into
 * existence and deletes it again on every commit, which is slow on phone
 * flash: on a Pixel 9 a single Eloquent insert takes ~3.9ms that way versus
 * ~0.2ms with WAL + synchronous=NORMAL. The WAL journal stays crash-safe;
 * NORMAL only means the last few commits can roll back on sudden power loss,
 * never corruption.
 *
 * Laravel 11+ applies these connection keys as pragmas when a connection is
 * opened (they are null in the stock config/database.php). We only fill keys
 * the developer left null or unset, so an explicit value — e.g.
 * 'journal_mode' => 'delete' — always wins. Laravel 10 ignores the keys, so
 * there this is a no-op.
 *
 * busy_timeout is deliberately left alone: pdo_sqlite already opens every
 * connection with a 60s busy timeout (PDO::ATTR_TIMEOUT), and any value we
 * picked would only shorten it.
 */
class SqliteDefaults
{
    public const DEFAULTS = [
        'journal_mode' => 'wal',
        'synchronous' => 'normal',
    ];

    /**
     * Fill the defaults into the SQLite connection(s) backed by the database
     * file the native shell manages. Returns the connection names touched.
     *
     * @param  string|null  $managedDatabase  The DB_DATABASE path the iOS /
     *                                        Android shell exports. When null,
     *                                        only the default connection is
     *                                        considered.
     * @return string[]
     */
    public static function apply(Repository $config, ?string $managedDatabase): array
    {
        $connections = $config->get('database.connections');

        if (! is_array($connections)) {
            return [];
        }

        $default = $config->get('database.default');
        $touched = [];

        foreach ($connections as $name => $connection) {
            if (! is_array($connection) || ! static::isManaged((string) $name, $connection, $default, $managedDatabase)) {
                continue;
            }

            $changed = false;

            foreach (static::DEFAULTS as $key => $value) {
                if (static::isConfigured($connection, $key)) {
                    continue;
                }

                $connection[$key] = $value;
                $changed = true;
            }

            if ($changed) {
                $connections[$name] = $connection;
                $touched[] = (string) $name;
            }
        }

        if ($touched !== []) {
            $config->set('database.connections', $connections);
        }

        return $touched;
    }

    /**
     * Only a file-backed SQLite connection pointing at the database the shell
     * manages. In-memory databases, URL-configured connections and any other
     * SQLite file the app opens (a read-only DB shipped in the bundle, say)
     * are left exactly as configured.
     */
    protected static function isManaged(string $name, array $connection, mixed $default, ?string $managedDatabase): bool
    {
        if (($connection['driver'] ?? null) !== 'sqlite') {
            return false;
        }

        if (! empty($connection['url'])) {
            return false;
        }

        $database = $connection['database'] ?? null;

        if (! is_string($database) || $database === '' || static::isInMemory($database)) {
            return false;
        }

        if ($managedDatabase === null || $managedDatabase === '') {
            return $name === $default;
        }

        return static::samePath($database, $managedDatabase);
    }

    protected static function isInMemory(string $database): bool
    {
        return $database === ':memory:'
            || str_contains($database, '?mode=memory')
            || str_contains($database, '&mode=memory')
            || str_starts_with($database, 'file:');
    }

    protected static function samePath(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $realA = realpath($a);
        $realB = realpath($b);

        return $realA !== false && $realA === $realB;
    }

    /**
     * A key counts as configured when the connection sets it to anything
     * non-null, or sets the same pragma through Laravel's `pragmas` array.
     */
    protected static function isConfigured(array $connection, string $key): bool
    {
        if (array_key_exists($key, $connection) && $connection[$key] !== null) {
            return true;
        }

        $pragmas = $connection['pragmas'] ?? null;

        if (! is_array($pragmas)) {
            return false;
        }

        foreach ($pragmas as $pragma => $value) {
            if (is_string($pragma) && strcasecmp($pragma, $key) === 0 && $value !== null) {
                return true;
            }
        }

        return false;
    }
}
