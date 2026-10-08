<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use PDO;
use PDOException;

/**
 * Base case for tests/Pgsql (group "pgsql"), see docs/TESTING.md §3.
 *
 * Connects with the DB_PGSQL_* env vars and skips when DB_PGSQL_HOST is unset.
 * Each parallel worker gets its own database, dbx_test_{TEST_TOKEN} (dbx_test
 * when not parallel). The superuser connection "pgsql_super" is the default
 * connection and the metadata store, and the dbx_* tables are migrated fresh
 * before every test.
 */
abstract class PgsqlTestCase extends TestCase
{
    /** @var array<string, true> */
    private static array $createdDatabases = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! static::configured()) {
            $this->markTestSkipped('Set DB_PGSQL_HOST to run the pgsql group (docs/TESTING.md §3).');
        }

        Artisan::call('migrate:fresh');
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        if (! static::configured()) {
            return;
        }

        static::ensureDatabaseExists(static::database());

        // Fixed, so child processes booted from this class share the audit key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.connections.pgsql_super', static::connection(static::database()));
        $app['config']->set('database.default', 'pgsql_super');
        $app['config']->set('dbexplorer.storage.connection', 'pgsql_super');
    }

    public static function configured(): bool
    {
        return static::env('DB_PGSQL_HOST') !== '';
    }

    /**
     * The database for this worker: dbx_test_{TEST_TOKEN}, or dbx_test when not parallel.
     */
    public static function database(): string
    {
        $token = static::env('TEST_TOKEN');

        return $token === '' ? 'dbx_test' : 'dbx_test_'.preg_replace('/\W/', '', $token);
    }

    /**
     * A Laravel connection config for $database using the DB_PGSQL_* credentials.
     *
     * @return array<string, string>
     */
    public static function connection(string $database, ?string $username = null, ?string $password = null): array
    {
        return [
            'driver' => 'pgsql',
            'host' => static::env('DB_PGSQL_HOST'),
            'port' => static::env('DB_PGSQL_PORT', '5432'),
            'database' => $database,
            'username' => $username ?? static::env('DB_PGSQL_USERNAME', 'postgres'),
            'password' => $password ?? static::env('DB_PGSQL_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    /**
     * Create $database once per process, connecting to the "postgres" maintenance database.
     */
    protected static function ensureDatabaseExists(string $database): void
    {
        if (isset(self::$createdDatabases[$database])) {
            return;
        }

        $config = static::connection('postgres');
        $pdo = new PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname=postgres",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $exists->execute([$database]);

        if ($exists->fetchColumn() === false) {
            try {
                $pdo->exec('CREATE DATABASE "'.str_replace('"', '""', $database).'"');
            } catch (PDOException $e) {
                // 42P04 duplicate_database: another process won the race.
                if ($e->getCode() !== '42P04') {
                    throw $e;
                }
            }
        }

        self::$createdDatabases[$database] = true;
    }

    protected static function env(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
