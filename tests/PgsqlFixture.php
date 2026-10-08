<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Tests;

use PDO;
use PDOException;
use Throwable;

/**
 * The PostgreSQL environment shared by the pgsql test group and the workbench
 * (docs/TESTING.md §3.2): the dbx_* login roles, a database owned by
 * dbx_admin, and the fixture schema from tests/fixtures/pgsql.sql.
 *
 * Every step is idempotent and safe when parallel workers run it at once.
 */
final class PgsqlFixture
{
    /**
     * Explorer role => cluster-wide login role.
     */
    public const ROLES = [
        'viewer' => 'dbx_viewer',
        'editor' => 'dbx_editor',
        'admin' => 'dbx_admin',
    ];

    /**
     * The password of every dbx_* login role. Test clusters only.
     */
    public const PASSWORD = 'dbx-test-password';

    /**
     * Prefix of the database comment that records the loaded fixture version.
     */
    private const MARKER = 'dbexplorer-fixture:';

    public static function configured(): bool
    {
        return self::env('DB_PGSQL_HOST') !== '';
    }

    /**
     * A Laravel connection config for $database, as the DB_PGSQL_* superuser
     * unless another login is given.
     *
     * @return array<string, string>
     */
    public static function connection(string $database, ?string $username = null, ?string $password = null): array
    {
        return [
            'driver' => 'pgsql',
            'host' => self::env('DB_PGSQL_HOST'),
            'port' => self::env('DB_PGSQL_PORT', '5432'),
            'database' => $database,
            'username' => $username ?? self::env('DB_PGSQL_USERNAME', 'postgres'),
            'password' => $password ?? self::env('DB_PGSQL_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    /**
     * Connection configs for the three role logins on $database, keyed by explorer role.
     *
     * @return array<string, array<string, string>>
     */
    public static function roleConnections(string $database): array
    {
        $connections = [];

        foreach (self::ROLES as $role => $login) {
            $connections[$role] = self::connection($database, $login, self::PASSWORD);
        }

        return $connections;
    }

    /**
     * A superuser PDO for $database.
     */
    public static function pdo(string $database): PDO
    {
        $config = self::connection($database);

        return new PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$database}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * Create the roles and $database if missing, then load the fixture into it
     * unless the loaded version is current. Returns whether the fixture loaded.
     */
    public static function prepare(string $database): bool
    {
        $postgres = self::pdo('postgres');

        self::ensureRoles($postgres);
        self::ensureDatabase($postgres, $database);

        return self::load(self::pdo($database));
    }

    /**
     * Create or update the cluster-wide login roles. The advisory lock serializes
     * concurrent workers; duplicate_object still covers a role created elsewhere.
     */
    public static function ensureRoles(PDO $pdo): void
    {
        $roles = implode(', ', array_map([$pdo, 'quote'], self::ROLES));
        $password = $pdo->quote(self::PASSWORD);

        $pdo->exec(<<<SQL
            DO $$
            DECLARE
                r text;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('dbexplorer-fixture-roles'));

                FOREACH r IN ARRAY ARRAY[{$roles}] LOOP
                    BEGIN
                        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
                            EXECUTE format('ALTER ROLE %I LOGIN PASSWORD %L', r, {$password});
                        ELSE
                            EXECUTE format('CREATE ROLE %I LOGIN PASSWORD %L', r, {$password});
                        END IF;
                    EXCEPTION WHEN duplicate_object OR unique_violation THEN
                        NULL;
                    END;
                END LOOP;
            END
            $$
            SQL);
    }

    /**
     * Create $database owned by dbx_admin, or hand an existing one to dbx_admin.
     */
    public static function ensureDatabase(PDO $pdo, string $database): void
    {
        $owner = $pdo->prepare('SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = ?');
        $owner->execute([$database]);
        $current = $owner->fetchColumn();
        $name = self::quoteIdentifier($database);

        if ($current === false) {
            try {
                $pdo->exec("CREATE DATABASE {$name} OWNER dbx_admin");
            } catch (PDOException $e) {
                // 42P04 duplicate_database: another process won the race.
                if ($e->getCode() !== '42P04') {
                    throw $e;
                }
            }
        } elseif ($current !== 'dbx_admin') {
            $pdo->exec("ALTER DATABASE {$name} OWNER TO dbx_admin");
        }
    }

    /**
     * Load the fixture into the connected database unless its marker already
     * matches the fixture file. Returns whether it loaded.
     */
    public static function load(PDO $pdo): bool
    {
        $pdo->beginTransaction();

        try {
            $pdo->exec("SELECT pg_advisory_xact_lock(hashtext('dbexplorer-fixture-load'))");

            if (self::marker($pdo) === self::version()) {
                $pdo->commit();

                return false;
            }

            $database = (string) $pdo->query('SELECT current_database()')->fetchColumn();

            $pdo->exec('DROP SCHEMA IF EXISTS fixture CASCADE');
            $pdo->exec((string) file_get_contents(self::path()));
            $pdo->exec('COMMENT ON DATABASE '.self::quoteIdentifier($database).' IS '.$pdo->quote(self::MARKER.self::version()));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        return true;
    }

    /**
     * The fixture version loaded into the connected database, or null.
     */
    public static function marker(PDO $pdo): ?string
    {
        $comment = $pdo->query("SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname = current_database()")->fetchColumn();

        if (! is_string($comment) || strncmp($comment, self::MARKER, strlen(self::MARKER)) !== 0) {
            return null;
        }

        return substr($comment, strlen(self::MARKER));
    }

    /**
     * The version of the fixture file: a hash of its contents.
     */
    public static function version(): string
    {
        return (string) sha1_file(self::path());
    }

    public static function path(): string
    {
        return __DIR__.'/fixtures/pgsql.sql';
    }

    private static function quoteIdentifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private static function env(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
