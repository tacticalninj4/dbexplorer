<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A schedule keeps the saved query's name as it was when an admin approved
 * it, next to the SQL snapshot: notifications show that name, so renaming
 * the saved query can't change what the schedule's mail says (F38).
 * Existing schedules get their saved query's current name.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('dbexplorer.storage.connection');

        return is_string($connection) ? $connection : null;
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->table('dbx_schedules', function (Blueprint $table) {
            $table->string('name')->nullable();
        });

        $db = DB::connection($this->getConnection());

        $db->table('dbx_schedules')->orderBy('id')->chunkById(100, function ($schedules) use ($db): void {
            foreach ($schedules as $schedule) {
                $name = $db->table('dbx_saved_queries')->where('id', $schedule->saved_query_id)->value('name');

                $db->table('dbx_schedules')->where('id', $schedule->id)->update(['name' => is_string($name) ? $name : null]);
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        // Laravel 9 and 10 drop SQLite columns only through doctrine/dbal. The
        // column is harmless there, and rolling back create_dbx_schedules_table
        // drops it with the table.
        if ($schema->getConnection()->getDriverName() === 'sqlite' && version_compare(app()->version(), '11', '<') && ! class_exists('Doctrine\DBAL\Connection')) {
            return;
        }

        $schema->table('dbx_schedules', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
