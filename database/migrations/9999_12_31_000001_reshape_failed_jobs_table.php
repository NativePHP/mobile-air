<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the queue tables up to the shape of Laravel's current
 * 0001_01_01_000002_create_jobs_table. Our create_jobs_table, like Laravel
 * 11 and 12's, declared failed_jobs.connection and queue as text and had no
 * (connection, queue, failed_at) index. Tables that already have Laravel's
 * shape are left alone.
 *
 * Package migrations only run on device, where the database is SQLite.
 * jobs needs nothing there: tiny and small integers are both "integer".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('failed_jobs')
            || Schema::getColumnType('failed_jobs', 'connection') !== 'text') {
            return;
        }

        // SQLite can't change a column's type in place, and Laravel 10 can't
        // do it for us without doctrine/dbal, so set the rows aside and
        // recreate the table the way Laravel's migration creates it.
        DB::transaction(function () {
            $columns = 'id, uuid, connection, queue, payload, exception, failed_at';

            DB::statement('create temporary table failed_jobs_old as select * from failed_jobs');
            Schema::drop('failed_jobs');

            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('connection');
                $table->string('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();

                $table->index(['connection', 'queue', 'failed_at']);
            });

            DB::statement("insert into failed_jobs ({$columns}) select {$columns} from failed_jobs_old");
            DB::statement('drop table failed_jobs_old');
        });
    }

    public function down(): void
    {
        // Nothing to undo: the old shape held the same data.
    }
};
