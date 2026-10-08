<?php

use Illuminate\Support\Facades\Artisan;
use TacticalNinj4\DbExplorer\Tests\PgsqlFixture;
use Workbench\App\Providers\WorkbenchServiceProvider;

// Build step "workbench-pgsql": the dbx_* roles and the workbench database, before db-wipe.
Artisan::command('workbench:pgsql', function () {
    if (! PgsqlFixture::configured()) {
        $this->info('DB_PGSQL_HOST is not set, so the workbench uses SQLite.');

        return;
    }

    $postgres = PgsqlFixture::pdo('postgres');

    PgsqlFixture::ensureRoles($postgres);
    PgsqlFixture::ensureDatabase($postgres, WorkbenchServiceProvider::DATABASE);

    $this->info('Database '.WorkbenchServiceProvider::DATABASE.' and the dbx_* roles are ready.');
})->purpose('Create the dbx_* roles and the PostgreSQL workbench database');
