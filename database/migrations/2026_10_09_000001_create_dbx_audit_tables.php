<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('dbexplorer.storage.connection');

        return is_string($connection) ? $connection : null;
    }

    public function up(): void
    {
        // Single-row table. Writers lock it to serialize inserts into the hash chain.
        Schema::connection($this->getConnection())->create('dbx_audit_head', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('hash', 64)->nullable();
        });

        DB::connection($this->getConnection())->table('dbx_audit_head')->insert(['id' => 1, 'hash' => null]);

        // Free-form values use text, and created_at uses dateTime, so every value
        // reads back byte-for-byte as it was hashed on pgsql, mysql and sqlite.
        Schema::connection($this->getConnection())->create('dbx_audit_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('created_at')->index();
            $table->string('action', 64)->index();
            $table->string('user_id')->nullable()->index();
            $table->string('role', 32)->nullable();
            $table->string('label')->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('sql')->nullable();
            $table->string('classification', 32)->nullable();
            $table->bigInteger('duration_ms')->nullable();
            $table->bigInteger('row_count')->nullable();
            $table->string('outcome', 32);
            $table->text('error')->nullable();
            $table->text('meta')->nullable();
            $table->string('prev_hash', 64);
            $table->string('hash', 64)->unique();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('dbx_audit_log');
        Schema::connection($this->getConnection())->dropIfExists('dbx_audit_head');
    }
};
