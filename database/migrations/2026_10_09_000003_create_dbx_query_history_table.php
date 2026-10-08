<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::connection($this->getConnection())->create('dbx_query_history', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable();
            $table->string('label');
            $table->string('role', 32)->nullable();
            $table->text('sql');
            $table->string('classification', 32)->nullable();
            $table->bigInteger('duration_ms')->nullable();
            $table->bigInteger('row_count')->nullable();
            $table->string('outcome', 32);
            $table->text('error')->nullable();
            $table->dateTime('created_at')->index();
            $table->index(['user_id', 'label', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('dbx_query_history');
    }
};
