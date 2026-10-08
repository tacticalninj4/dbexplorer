<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use TacticalNinj4\DbExplorer\Tests\PgsqlFixture;
use Workbench\Database\Factories\UserFactory;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        UserFactory::new()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // The same fixture as the pgsql tests, reloaded only when tests/fixtures/pgsql.sql changes.
        if (DB::connection()->getDriverName() === 'pgsql') {
            PgsqlFixture::load(DB::connection()->getPdo());
        }
    }
}
