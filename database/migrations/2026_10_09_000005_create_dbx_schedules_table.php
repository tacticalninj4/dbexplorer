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
        Schema::connection($this->getConnection())->create('dbx_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_query_id')->constrained('dbx_saved_queries')->cascadeOnDelete();
            $table->string('user_id')->index();
            $table->string('cron', 64);
            $table->string('timezone', 64)->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->json('notify')->nullable();
            $table->dateTime('last_run_at')->nullable();
            $table->unsignedBigInteger('last_run_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('dbx_schedules');
    }
};
