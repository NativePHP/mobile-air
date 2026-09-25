<?php

namespace Native\Mobile\Database;

use Illuminate\Database\Schema\SQLiteBuilder as BaseSQLiteBuilder;

class SQLiteBuilder extends BaseSQLiteBuilder
{
    /**
     * Laravel's db:wipe / migrate:fresh empty a file-backed SQLite database by
     * truncating the file to zero bytes. That is only safe with the rollback
     * journal. In WAL mode the recent commits live in database.sqlite-wal and
     * every open connection (the persistent runtime, the queue worker, async
     * task slots) keeps an index of them in -shm, so truncating the main file
     * underneath them leaves the WAL describing pages that no longer exist:
     * the same process sees "table already exists" or "file is not a
     * database", and the file can stay unreadable after restart.
     *
     * So when the connection is in WAL mode, drop the schema through SQLite
     * itself instead — the same writable_schema + VACUUM route Laravel already
     * uses for in-memory databases — which every other connection sees
     * consistently. Any other journal mode keeps Laravel's behaviour.
     *
     * @param  string|null  $path
     * @return void
     */
    public function refreshDatabaseFile($path = null)
    {
        $database = $this->connection->getDatabaseName();

        if (($path === null || $path === $database) && $this->usesWal()) {
            $this->wipeInPlace();

            return;
        }

        // Laravel 10's signature takes no argument; the extra one is ignored.
        parent::refreshDatabaseFile($path ?? $database);
    }

    protected function usesWal(): bool
    {
        $row = (array) $this->connection->selectOne('pragma journal_mode');

        return strtolower((string) (reset($row) ?: '')) === 'wal';
    }

    protected function wipeInPlace(): void
    {
        $this->connection->statement('pragma writable_schema = 1');
        $this->connection->statement($this->grammar->compileDropAllTables());
        $this->connection->statement('pragma writable_schema = 0');
        $this->connection->statement($this->grammar->compileRebuild());
    }
}
