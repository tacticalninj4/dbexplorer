<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Drivers\Postgres;

use Illuminate\Database\Connection;
use Symfony\Component\Process\Process;

/**
 * pg_dump, the safe way (PRD §7.10, S20): the command is an argument array
 * that never goes through a shell, and the password reaches it only through
 * the PGPASSWORD environment variable — never in the arguments, where a ps
 * or an error log would show it. The connection's sslmode, sslrootcert,
 * sslcert and sslkey reach it the same way (PGSSLMODE, …).
 */
final class BackupTool
{
    public function __construct(
        private Connection $connection,
        private string $pgDumpPath,
    ) {
        //
    }

    /**
     * The pg_dump argument array for the connection's database. `--file` is
     * appended by the caller, with the target path. `$schemas` scopes the
     * dump with --schema (repeatable), which is also the way around tables
     * the login cannot read.
     *
     * @param  list<string>  $schemas
     * @return list<string>
     */
    public function command(string $format = 'custom', array $schemas = []): array
    {
        $config = $this->connection->getConfig();

        $arguments = [$this->pgDumpPath, '--format='.($format === 'plain' ? 'plain' : 'custom'), '--no-password'];

        foreach ($schemas as $schema) {
            if ($schema !== '') {
                $arguments[] = '--schema';
                $arguments[] = $schema;
            }
        }

        if (is_string($config['host'] ?? null) && $config['host'] !== '') {
            $arguments[] = '--host';
            $arguments[] = (string) $config['host'];
        }

        if (is_string($config['port'] ?? null) && $config['port'] !== '') {
            $arguments[] = '--port';
            $arguments[] = (string) $config['port'];
        }

        if (is_string($config['username'] ?? null) && $config['username'] !== '') {
            $arguments[] = '--username';
            $arguments[] = (string) $config['username'];
        }

        $arguments[] = '--dbname';
        $arguments[] = (string) ($config['database'] ?? '');

        return $arguments;
    }

    /** libpq's environment variable for each SSL option of a Laravel pgsql connection. */
    public const SSL_ENV = [
        'sslmode' => 'PGSSLMODE',
        'sslrootcert' => 'PGSSLROOTCERT',
        'sslcert' => 'PGSSLCERT',
        'sslkey' => 'PGSSLKEY',
    ];

    /**
     * The environment the dump runs with: the password and the connection's
     * SSL settings (so pg_dump connects as securely as the app does), and
     * nothing else it doesn't need.
     *
     * @return array<string, string>
     */
    public function env(): array
    {
        $env = ['PGPASSWORD' => (string) $this->connection->getConfig('password')];

        foreach (self::SSL_ENV as $option => $variable) {
            $value = $this->connection->getConfig($option);

            if (is_string($value) && $value !== '') {
                $env[$variable] = $value;
            }
        }

        return $env;
    }

    /**
     * pg_dump must be at least the server's major version, or its output is
     * unreliable. Null when the pair is fine, a human-readable problem when
     * not (PRD §7.10).
     */
    public function preflight(): ?string
    {
        $dumpVersion = $this->dumpMajorVersion();
        $server = (array) $this->connection->selectOne('SHOW server_version_num');

        if ($dumpVersion === null || ! isset($server['server_version_num'])) {
            return 'pg_dump could not be run: backups will fail until it is installed and dbexplorer.backup.pg_dump_path points at it.';
        }

        $serverMajor = intdiv((int) $server['server_version_num'], 10000);

        if ($dumpVersion < $serverMajor) {
            return "pg_dump {$dumpVersion} is older than the server's PostgreSQL {$serverMajor}: its dumps of this server would be unreliable. Upgrade pg_dump (dbexplorer.backup.pg_dump_path).";
        }

        return null;
    }

    /**
     * @return int|null the major version pg_dump reports
     */
    public function dumpMajorVersion(): ?int
    {
        $process = $this->run(array_merge([$this->pgDumpPath], ['--version']));

        if (! $process->isSuccessful()) {
            return null;
        }

        preg_match('/(\d+)(?:\.\d+)*/', $process->getOutput(), $matches);

        return isset($matches[1]) ? (int) $matches[1] : null;
    }

    /**
     * @param  list<string>  $command
     */
    public function run(array $command, ?float $timeout = 60.0): Process
    {
        $process = $this->start($command, $timeout);
        $process->wait();

        return $process;
    }

    /**
     * Start $command without waiting for it, for a caller that checks for a
     * cancel while it runs.
     *
     * @param  list<string>  $command
     */
    public function start(array $command, ?float $timeout = 60.0): Process
    {
        $process = new Process($command, null, $this->env(), null, $timeout);
        $process->start();

        return $process;
    }

    /**
     * The first line of a failed dump's stderr, for the run's error, with
     * the connection's host, port, login, database and password redacted
     * (libpq quotes them in connection errors).
     */
    public function describeFailure(Process $process): string
    {
        $message = strtok(trim($process->getErrorOutput() ?: $process->getOutput()), "\n");

        if ($message === false) {
            return 'pg_dump failed.';
        }

        $secrets = [];

        foreach (['password', 'host', 'username', 'database', 'port'] as $key) {
            $value = $this->connection->getConfig($key);

            if ((is_string($value) || is_int($value)) && (string) $value !== '') {
                $secrets[(string) $value] = strlen((string) $value);
            }
        }

        // Longest first, so a value inside another is never left half shown.
        arsort($secrets);

        foreach (array_keys($secrets) as $secret) {
            $message = str_replace((string) $secret, '[redacted]', $message);
        }

        return 'pg_dump failed: '.$message;
    }

    /**
     * Where pg_restore lives, if next to pg_dump; null when not found.
     */
    public function restorePath(): ?string
    {
        if (! is_file($this->pgDumpPath) || ! is_executable($this->pgDumpPath)) {
            return null;
        }

        $restore = dirname($this->pgDumpPath).'/pg_restore';

        return is_file($restore) ? $restore : null;
    }
}
