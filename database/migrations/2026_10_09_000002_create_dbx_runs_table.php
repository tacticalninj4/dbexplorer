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
        Schema::connection($this->getConnection())->create('dbx_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('status', 16)->index();
            $table->unsignedTinyInteger('progress')->nullable();
            $table->json('meta')->nullable();
            $table->string('result_disk')->nullable();
            $table->string('result_path')->nullable();
            $table->text('error')->nullable();
            $table->integer('pid')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->string('user_id')->nullable()->index();
            $table->string('label')->nullable();
            $table->string('role', 32)->nullable();
            $table->unsignedBigInteger('schedule_id')->nullable()->index();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable()->index();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('dbx_runs');
    }
};
