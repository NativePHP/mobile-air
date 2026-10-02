<?php

namespace Native\Mobile\Database;

use Illuminate\Database\SQLiteConnection as BaseSQLiteConnection;

/**
 * The on-device SQLite connection. Identical to Laravel's except that its
 * schema builder wipes a WAL-mode database without truncating the file out
 * from under the journal (see SQLiteBuilder::refreshDatabaseFile()).
 */
class SQLiteConnection extends BaseSQLiteConnection
{
    public function getSchemaBuilder()
    {
        // Let the parent set up the default schema grammar.
        parent::getSchemaBuilder();

        return new SQLiteBuilder($this);
    }
}
