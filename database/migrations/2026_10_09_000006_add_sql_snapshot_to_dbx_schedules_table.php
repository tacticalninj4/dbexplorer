<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schedules run a snapshot of the SQL an admin approved, never the saved
 * query's current text (docs/security.md §6). Existing schedules get the
 * saved query's SQL copied in but stay unapproved and disabled: an admin
 * re-syncs (and so approves) each one before it runs again.
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
            $table->text('sql')->nullable();
            $table->json('params')->nullable();
            $table->string('sql_hash', 64)->nullable();
            $table->boolean('allow_writes')->default(false);
            $table->boolean('allow_ddl')->default(false);
            $table->string('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
        });

        $db = DB::connection($this->getConnection());

        $db->table('dbx_schedules')->orderBy('id')->chunkById(100, function ($schedules) use ($db): void {
            foreach ($schedules as $schedule) {
                $saved = $db->table('dbx_saved_queries')->where('id', $schedule->saved_query_id)->first(['sql', 'params']);
                $sql = $saved === null ? null : (string) $saved->sql;

                $db->table('dbx_schedules')->where('id', $schedule->id)->update([
                    'sql' => $sql,
                    'params' => $saved?->params,
                    'sql_hash' => $sql === null ? null : hash('sha256', $sql),
                    'enabled' => false,
                ]);
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        // Laravel 9 and 10 drop SQLite columns only through doctrine/dbal. The
        // columns are harmless there, and rolling back create_dbx_schedules_table
        // drops them with the table.
        if ($schema->getConnection()->getDriverName() === 'sqlite' && version_compare(app()->version(), '11', '<') && ! class_exists('Doctrine\DBAL\Connection')) {
            return;
        }

        $schema->table('dbx_schedules', function (Blueprint $table) {
            $table->dropColumn(['sql', 'params', 'sql_hash', 'allow_writes', 'allow_ddl', 'approved_by', 'approved_at']);
        });
    }
};
