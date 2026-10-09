<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Drivers\Postgres;

use Illuminate\Database\ConnectionInterface;

/**
 * Active sessions (PRD §7.10, M4.4): what `pg_stat_activity` shows for this
 * database, plus cancel and terminate. Only backends of the same database
 * login are ever signalled (`usename = current_user`), so a pid reused by
 * someone else's session can't be killed; other roles' backends need the
 * `pg_signal_backend` grant, which docs/security.md covers.
 */
final class Sessions
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->connection->select(
            'SELECT pid, usename, client_addr::text AS client, application_name, backend_type, state,
                    wait_event_type, wait_event, backend_start, state_change, query_start,
                    left(query, 500) AS query, clock_timestamp() - query_start AS query_age
             FROM pg_stat_activity
             WHERE datname = current_database() AND pid <> pg_backend_pid()
             ORDER BY coalesce(query_start, backend_start) DESC',
        );
    }

    public function cancel(int $pid): bool
    {
        return $this->signal('pg_cancel_backend', $pid);
    }

    public function terminate(int $pid): bool
    {
        return $this->signal('pg_terminate_backend', $pid);
    }

    /**
     * True when the backend existed and belonged to this login, whether or
     * not the signal changed anything (a finished backend is gone either
     * way).
     */
    private function signal(string $function, int $pid): bool
    {
        $result = $this->connection->selectOne(
            "SELECT {$function}(pid) AS signalled FROM pg_stat_activity WHERE pid = ? AND usename = current_user",
            [$pid],
        );

        return $result !== null;
    }
}
