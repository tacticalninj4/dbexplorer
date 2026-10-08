<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Base case for tests/Pgsql (group "pgsql"), see docs/TESTING.md §3.
 *
 * Connects with the DB_PGSQL_* env vars and skips when DB_PGSQL_HOST is unset.
 * Each checkout and parallel worker gets its own database (see database()),
 * owned by dbx_admin, with the read-only fixture schema
 * loaded once and kept across runs until tests/fixtures/pgsql.sql changes.
 *
 * Connections: pgsql_viewer, pgsql_editor and pgsql_admin log in as the
 * dbx_* roles and are mapped through dbexplorer.connections.main. pgsql_super
 * is the default connection, for setup and assertions, and the metadata store:
 * the dbx_* tables in schema public are migrated fresh before every test.
 */
abstract class PgsqlTestCase extends TestCase
{
    /** @var array<string, true> */
    private static array $preparedDatabases = [];

    private ?string $scratchSchema = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! static::configured()) {
            $this->markTestSkipped('Set DB_PGSQL_HOST to run the pgsql group (docs/TESTING.md §3).');
        }

        // Wipes only the search_path (public), never the fixture schema.
        Artisan::call('migrate:fresh');
    }

    protected function tearDown(): void
    {
        $this->dropScratchSchema();

        // Close the logins now: an app that isn't garbage-collected yet would
        // keep them open, and parallel workers would run out of server slots.
        if (static::configured()) {
            foreach (['pgsql_viewer', 'pgsql_editor', 'pgsql_admin', 'pgsql_super'] as $connection) {
                DB::disconnect($connection);
            }
        }

        parent::tearDown();
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if (! static::configured()) {
            return;
        }

        $database = static::database();

        if (! isset(self::$preparedDatabases[$database])) {
            PgsqlFixture::prepare($database);
            self::$preparedDatabases[$database] = true;
        }

        $config = $app['config'];

        // Fixed, so child processes booted from this class share the audit key.
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $config->set('database.connections.pgsql_super', PgsqlFixture::connection($database));
        $config->set('database.default', 'pgsql_super');
        $config->set('dbexplorer.storage.connection', 'pgsql_super');

        $main = [];

        foreach (PgsqlFixture::roleConnections($database) as $role => $connection) {
            $config->set("database.connections.pgsql_{$role}", $connection);
            $main[$role] = "pgsql_{$role}";
        }

        $config->set('dbexplorer.connections', ['main' => $main]);
    }

    /**
     * This test's scratch schema, t_<random>, for tests that change data or
     * schema. It's created on first call, owned by dbx_admin, and dropped after
     * the test. Tables that pgsql_admin creates in it are readable by the viewer
     * and writable by the editor; neither can create objects in it.
     */
    public function scratchSchema(): string
    {
        if ($this->scratchSchema !== null) {
            return $this->scratchSchema;
        }

        $schema = 't_'.bin2hex(random_bytes(6));

        DB::connection('pgsql_super')->unprepared(<<<SQL
            CREATE SCHEMA {$schema} AUTHORIZATION dbx_admin;
            GRANT USAGE ON SCHEMA {$schema} TO dbx_viewer, dbx_editor;
            ALTER DEFAULT PRIVILEGES FOR ROLE dbx_admin IN SCHEMA {$schema} GRANT SELECT ON TABLES TO dbx_viewer;
            ALTER DEFAULT PRIVILEGES FOR ROLE dbx_admin IN SCHEMA {$schema} GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO dbx_editor;
            ALTER DEFAULT PRIVILEGES FOR ROLE dbx_admin IN SCHEMA {$schema} GRANT USAGE, SELECT ON SEQUENCES TO dbx_editor;
            SQL);

        return $this->scratchSchema = $schema;
    }

    /**
     * Drop the scratch schema now. Runs automatically after each test.
     */
    public function dropScratchSchema(): void
    {
        if ($this->scratchSchema === null) {
            return;
        }

        // Closing every connection ends open transactions that could hold locks on it.
        foreach (['pgsql_viewer', 'pgsql_editor', 'pgsql_admin', 'pgsql_super'] as $connection) {
            DB::disconnect($connection);
        }

        DB::connection('pgsql_super')->statement("DROP SCHEMA IF EXISTS {$this->scratchSchema} CASCADE");

        $this->scratchSchema = null;
    }

    public static function configured(): bool
    {
        return PgsqlFixture::configured();
    }

    /**
     * The database for this checkout and worker: dbx_test_{checkout}_{TEST_TOKEN},
     * or dbx_test_{checkout} when not parallel. {checkout} is a short hash of the
     * package root, so checkouts sharing a cluster never share a database.
     */
    public static function database(): string
    {
        $database = 'dbx_test_'.substr(sha1(dirname(__DIR__)), 0, 8);
        $token = getenv('TEST_TOKEN');

        return is_string($token) && $token !== '' ? $database.'_'.preg_replace('/\W/', '', $token) : $database;
    }
}
