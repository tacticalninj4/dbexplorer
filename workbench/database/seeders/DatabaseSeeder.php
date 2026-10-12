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
        // The admin first: /login without a role signs in as it.
        UserFactory::new()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
        UserFactory::new()->create(['name' => 'Editor User', 'email' => 'editor@example.com']);
        UserFactory::new()->create(['name' => 'Viewer User', 'email' => 'viewer@example.com']);

        // The same fixture as the pgsql tests, reloaded only when tests/fixtures/pgsql.sql changes.
        if (DB::connection()->getDriverName() === 'pgsql') {
            PgsqlFixture::load(DB::connection()->getPdo());

            // The app tables in public belong to the superuser here, where the
            // README recipe has dbx_admin in the owning role: without this,
            // a whole-database pg_dump as dbx_admin fails on them.
            DB::unprepared('GRANT SELECT ON ALL TABLES IN SCHEMA public TO dbx_admin; GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO dbx_admin');
        }
    }
}
