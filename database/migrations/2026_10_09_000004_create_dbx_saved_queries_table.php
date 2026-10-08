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
        Schema::connection($this->getConnection())->create('dbx_saved_queries', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->string('label')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('sql');
            $table->json('params')->nullable();
            $table->string('visibility', 16)->default('private')->index();
            $table->json('tags')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('dbx_saved_queries');
    }
};
