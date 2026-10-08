<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Drivers\Postgres;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use TacticalNinj4\DbExplorer\Events\QueryExecuted;
use TacticalNinj4\DbExplorer\Exceptions\HiddenObjectReferenced;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Models\QueryHistory;
use TacticalNinj4\DbExplorer\Role;
use TacticalNinj4\DbExplorer\Security\HiddenObjects;
use TacticalNinj4\DbExplorer\Security\Masker;
use TacticalNinj4\DbExplorer\Sql\Classifier;
use TacticalNinj4\DbExplorer\Sql\Lexer;
use Throwable;

/**
 * Runs one interactive SQL statement for a role (PRD S8–S10, S12, S17).
 *
 * Every run, on the raw PDO of the given Laravel connection:
 *
 * 1. `BEGIN READ ONLY` for viewers (S9), `BEGIN` otherwise.
 * 2. `SELECT set_config('statement_timeout', $1, true)`: the role's timeout, local
 *    to the transaction (S10). It is a query on purpose: once a read-only
 *    transaction has run a query, `SET TRANSACTION READ WRITE` fails (25001).
 *    A bare `SET LOCAL` doesn't count, so the viewer could still flip it.
 * 3. The best-effort hidden-object check (S13), see rejectHiddenRelations().
 * 4. Statements the classifier calls read and that start with SELECT, VALUES,
 *    TABLE, WITH or `(` run through `DECLARE dbx_cur NO SCROLL CURSOR FOR …` and
 *    `FETCH <cap + 1>`, so memory stays bounded by the row cap and one extra row
 *    sets `truncated`. PostgreSQL only accepts queries in DECLARE, so a statement
 *    the classifier misjudged fails rather than runs.
 * 5. Everything else runs through a native prepare (emulation and
 *    `ATTR_DISABLE_PREPARES` explicitly off for the statement, whatever the
 *    connection's options say). PostgreSQL refuses to prepare more than one
 *    command, which closes off `COMMIT; DROP …` (S8). Rows are capped while
 *    fetching, but the driver buffers the whole result of these statements.
 * 6. COMMIT for editors and admins. ROLLBACK for viewers (a read-only
 *    transaction has nothing to commit, and rolling back also undoes any
 *    session `SET` it ran), for `EXPLAIN` and the `analyze` option, and on any error.
 *
 * The classifier is UX only (S11): it picks the cursor path and labels the run.
 * The database login and the transaction mode are the enforcement.
 *
 * Values are always bound (S12), with native prepares, including inside DECLARE
 * and EXPLAIN. PDO reads `?` as a placeholder even with no bindings, so the jsonb
 * operators `?`, `?|` and `?&` must be written `??`, `??|` and `??&`.
 *
 * NOTICEs are captured when the connection's PDO is a `Pdo\Pgsql` (PHP 8.4+,
 * created through `PDO::connect()`, as Laravel 12+ does). On a plain `PDO` there
 * is no notice API: notices are dropped and `noticesSupported` is false.
 *
 * Every run, successful or not, writes an audit entry (action `query`) and a
 * `dbx_query_history` row, then fires {@see QueryExecuted}. Neither stores bound
 * values: SQL text only, and error messages without DETAIL lines and with bound
 * values redacted.
 */
final class QueryRunner
{
    /** The cursor name used for reads. */
    public const CURSOR = 'dbx_cur';

    public const EXPLAIN_PLAIN = 'plain';

    public const EXPLAIN_ANALYZE = 'analyze';

    /** At most this many notices are kept per run. */
    public const MAX_NOTICES = 100;

    /** Leading keywords PostgreSQL accepts after DECLARE … FOR. */
    private const CURSOR_KEYWORDS = ['SELECT', 'VALUES', 'TABLE', 'WITH', '('];

    public function __construct(private DatabaseManager $db)
    {
        //
    }

    /**
     * Run one statement as `$role` on the Laravel connection `$connection`.
     *
     * Options:
     * - `explain`: null, 'plain' (`EXPLAIN (FORMAT JSON)`, nothing runs) or
     *   'analyze' (`EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`, always rolled back)
     * - `unmask`: bool, skip column masking. Only admins may unmask: the caller
     *   checks the role and audits the unmask.
     * - `label`, `user_id`: for the audit entry and history row. Inside a routed
     *   request they default to the request's label and user; the history row
     *   falls back to the connection name as label.
     *
     * @param  array<int|string, scalar|null>  $bindings  Positional values, bound in order, or named (`:name` keys) values
     * @param  array{explain?: string|null, unmask?: bool, label?: string|null, user_id?: string|int|null}  $options
     *
     * @throws QueryFailed when the database rejects or fails the statement
     * @throws InvalidArgumentException for an unknown role, explain mode or binding type
     */
    public function run(string $connection, string $sql, array $bindings, string $role, array $options = []): QueryResult
    {
        if (! Role::isValid($role)) {
            throw new InvalidArgumentException("Unknown role [{$role}].");
        }

        $explain = $options['explain'] ?? null;

        if ($explain !== null && $explain !== self::EXPLAIN_PLAIN && $explain !== self::EXPLAIN_ANALYZE) {
            throw new InvalidArgumentException("Unknown explain mode [{$explain}].");
        }

        $this->validateBindings($bindings);

        $timeout = $this->limit($role, 'timeout');
        $cap = $this->limit($role, 'rows');
        $classification = Classifier::classify($sql);
        $started = hrtime(true);
        $result = null;
        $failure = null;

        try {
            $result = $this->execute($connection, $sql, $bindings, $role, $timeout, $cap, $explain, (bool) ($options['unmask'] ?? false));
        } catch (QueryFailed $e) {
            $failure = $e;
        }

        $durationMs = (hrtime(true) - $started) / 1e6;
        $auditId = $this->record($connection, $sql, $bindings, $role, $options, $classification, $durationMs, $result, $failure);

        if ($failure !== null) {
            $failure->auditId = $auditId;

            throw $failure;
        }

        /** @var array{columns: list<array{name: string, type: string, masked: bool}>, rows: list<list<mixed>>, affected: int|null, truncated: bool, notices: list<string>, noticesSupported: bool} $result */
        $plan = null;

        if ($explain !== null) {
            $json = $result['rows'][0][0] ?? null;
            $decoded = is_string($json) ? json_decode($json, true) : null;
            $plan = is_array($decoded) ? $decoded : null;
        }

        return new QueryResult(
            $classification,
            $result['columns'],
            $result['rows'],
            count($result['rows']),
            $result['affected'],
            $result['truncated'],
            round($durationMs, 3),
            $result['notices'],
            $result['noticesSupported'],
            $explain,
            $plan,
            $auditId,
        );
    }

    /**
     * Steps 1–7 on the raw PDO. The connection is left clean whatever happens:
     * no open transaction, no cursor, and the timeout gone with the transaction.
     *
     * @param  array<int|string, scalar|null>  $bindings
     * @return array{columns: list<array{name: string, type: string, masked: bool}>, rows: list<list<mixed>>, affected: int|null, truncated: bool, notices: list<string>, noticesSupported: bool}
     */
    private function execute(string $name, string $sql, array $bindings, string $role, int $timeout, int $cap, ?string $explain, bool $unmask): array
    {
        $connection = $this->connection($name);
        $pdo = $this->pdo($connection);

        if (Classifier::statements($sql) === []) {
            throw new QueryFailed('There is no statement to run.', '42601');
        }

        // Rolling back or committing would end the caller's own transaction.
        if ($connection->transactionLevel() > 0 || $pdo->inTransaction()) {
            throw new QueryFailed('The connection already has an open transaction.', '25001');
        }

        $readOnly = $role === Role::VIEWER;
        $commit = ! $readOnly && $explain === null && $this->leadingKeyword($sql) !== 'EXPLAIN';
        $notices = [];
        $prefixLines = 0;

        try {
            $pdo->exec($readOnly ? 'BEGIN READ ONLY' : 'BEGIN');

            $this->prepare($pdo, "SELECT set_config('statement_timeout', ?, true)")->execute([(string) ($timeout * 1000)]);

            $prefixLines = 1;
            $this->rejectHiddenRelations($pdo, $sql, $bindings);

            if ($explain !== null) {
                $prefix = $explain === self::EXPLAIN_ANALYZE ? 'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)' : 'EXPLAIN (FORMAT JSON)';
                $cursor = false;
            } else {
                $cursor = Classifier::classify($sql) === Classifier::READ
                    && in_array($this->leadingKeyword($sql), self::CURSOR_KEYWORDS, true);
                $prefix = $cursor ? 'DECLARE '.self::CURSOR.' NO SCROLL CURSOR FOR' : null;
            }

            // A newline after the prefix keeps the server's LINE numbers one off from the user's.
            $prefixLines = $prefix === null ? 0 : 1;
            $noticesSupported = $this->listenForNotices($pdo, $notices);

            try {
                $statement = $this->prepare($pdo, $prefix === null ? $sql : $prefix."\n".$sql);
                $this->bind($statement, $bindings);
                $statement->execute();
                $affected = null;

                if ($cursor) {
                    $prefixLines = 0;
                    $statement = $this->prepare($pdo, 'FETCH FORWARD '.($cap + 1).' FROM '.self::CURSOR);
                    $statement->execute();
                } else {
                    $affected = $statement->rowCount();
                }

                $rows = $this->fetch($statement, $cap + 1);
            } finally {
                $this->stopListening($pdo);
            }

            $truncated = count($rows) > $cap;

            if ($truncated) {
                array_pop($rows);
            }

            $meta = [];

            for ($i = 0; $i < $statement->columnCount(); $i++) {
                $column = $statement->getColumnMeta($i);
                $meta[] = is_array($column) ? $column : [];
            }

            $statement->closeCursor();

            $masker = app(Masker::class);
            $masks = $explain === null
                ? $masker->forColumns($connection, $meta, $unmask)
                : array_fill_keys(array_keys($meta), false);

            if (in_array(true, $masks, true)) {
                foreach ($rows as $i => $row) {
                    $rows[$i] = array_values($masker->maskRow($row, $masks));
                }
            }

            $pdo->exec($commit ? 'COMMIT' : 'ROLLBACK');
        } catch (PDOException $e) {
            $this->abort($connection, $pdo);

            throw QueryFailed::fromPdo($e, $sql, $prefixLines);
        } catch (Throwable $e) {
            $this->abort($connection, $pdo);

            throw $e;
        }

        $columns = [];

        foreach ($meta as $position => $column) {
            $columns[] = [
                'name' => is_scalar($column['name'] ?? null) ? (string) $column['name'] : '',
                'type' => is_scalar($column['native_type'] ?? null) ? (string) $column['native_type'] : 'unknown',
                'masked' => $masks[$position] ?? false,
            ];
        }

        /** @var list<list<mixed>> $rows */
        return [
            'columns' => $columns,
            'rows' => $rows,
            'affected' => $affected,
            'truncated' => $truncated,
            'notices' => array_slice($notices, 0, self::MAX_NOTICES),
            'noticesSupported' => $noticesSupported,
        ];
    }

    /**
     * Best-effort hidden-object check (S13): plan the statement with
     * `EXPLAIN (VERBOSE, FORMAT JSON)`, nothing runs, and reject it when any
     * relation in the plan is hidden. Views are expanded in the plan, so their
     * base tables count. Skipped when `dbexplorer.hidden` is empty.
     *
     * Only statements EXPLAIN accepts are checked: SELECT, VALUES, TABLE, WITH,
     * INSERT, UPDATE, DELETE, MERGE, EXECUTE, DECLARE, CREATE TABLE … AS,
     * CREATE MATERIALIZED VIEW … AS, and the statement inside a typed EXPLAIN.
     * Others (COPY, CREATE VIEW, DO, PREPARE, ...) pass unchecked, and so do
     * functions that read a hidden table: the plan only shows the call.
     * Database grants are the real enforcement (docs/security.md).
     *
     * @param  array<int|string, scalar|null>  $bindings
     */
    private function rejectHiddenRelations(PDO $pdo, string $sql, array $bindings): void
    {
        $patterns = array_filter((array) config('dbexplorer.hidden', []), fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '');
        $target = $patterns === [] ? null : $this->explainable($sql);

        if ($target === null) {
            return;
        }

        $statement = $this->prepare($pdo, "EXPLAIN (VERBOSE, FORMAT JSON)\n".$target);
        $this->bind($statement, $bindings);
        $statement->execute();
        $plan = json_decode((string) $statement->fetchColumn(), true);
        $statement->closeCursor();

        $hidden = new HiddenObjects;

        foreach ($this->relations(is_array($plan) ? $plan : []) as [$schema, $name]) {
            if ($hidden->isHidden($schema, $name)) {
                throw HiddenObjectReferenced::relation($schema, $name, $sql);
            }
        }
    }

    /**
     * The part of `$sql` that EXPLAIN accepts, or null when it accepts none.
     */
    private function explainable(string $sql): ?string
    {
        $words = [];
        $depth = 0;
        $start = null;

        foreach (Lexer::tokens($sql) as $token) {
            if (Lexer::isTrivia($token)) {
                continue;
            }

            $word = $token['type'] === Lexer::WORD || $token['type'] === Lexer::OPEN_PAREN || $token['type'] === Lexer::CLOSE_PAREN
                ? strtoupper($token['text'])
                : '';

            if ($words === [] && $word === 'EXPLAIN') {
                $start = 'options';
                $words[] = $word;

                continue;
            }

            // Skip a typed EXPLAIN's options: `(…)` or ANALYZE / VERBOSE.
            if ($start === 'options') {
                if ($word === '(' || $depth > 0) {
                    $depth += $word === '(' ? 1 : ($word === ')' ? -1 : 0);

                    continue;
                }

                if (in_array($word, ['ANALYZE', 'ANALYSE', 'VERBOSE'], true)) {
                    continue;
                }

                return $this->explainable(substr($sql, $token['offset']));
            }

            $words[] = $word;

            if ($word === '(' && count($words) > 1) {
                $depth++;
            } elseif ($word === ')') {
                $depth--;
            } elseif ($words[0] === 'CREATE' && $word === 'AS' && $depth === 0) {
                $kind = array_values(array_diff(array_slice($words, 1), ['GLOBAL', 'LOCAL', 'TEMP', 'TEMPORARY', 'UNLOGGED']));

                return ($kind[0] ?? '') === 'TABLE' || (($kind[0] ?? '') === 'MATERIALIZED' && ($kind[1] ?? '') === 'VIEW') ? $sql : null;
            }

            if (count($words) === 1 && $word !== 'CREATE') {
                return in_array($word, ['SELECT', 'VALUES', 'TABLE', 'WITH', '(', 'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'EXECUTE', 'DECLARE'], true) ? $sql : null;
            }
        }

        return null;
    }

    /**
     * Every [schema, relation] an EXPLAIN (VERBOSE, FORMAT JSON) plan touches.
     *
     * @param  array<mixed>  $node
     * @return list<array{0: string, 1: string}>
     */
    private function relations(array $node): array
    {
        $found = [];

        if (is_string($node['Relation Name'] ?? null) && is_string($node['Schema'] ?? null)) {
            $found[] = [$node['Schema'], $node['Relation Name']];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                array_push($found, ...$this->relations($child));
            }
        }

        return $found;
    }

    private function connection(string $name): Connection
    {
        $connection = $this->db->connection($name);

        if ($connection->getDriverName() !== 'pgsql') {
            throw new InvalidArgumentException("The connection [{$name}] is not a PostgreSQL connection.");
        }

        return $connection;
    }

    private function pdo(Connection $connection): PDO
    {
        try {
            $pdo = $connection->getPdo();
        } catch (PDOException $e) {
            // The driver's message names the host and login: app log only (S7).
            report($e);

            throw QueryFailed::connectionFailed();
        }

        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('DbExplorer connections must use PDO::ERRMODE_EXCEPTION.');
        }

        return $pdo;
    }

    /**
     * A native, server-side prepare, whatever the connection's own options say (S8).
     */
    private function prepare(PDO $pdo, string $sql): PDOStatement
    {
        $disablePrepares = defined('Pdo\Pgsql::ATTR_DISABLE_PREPARES')
            ? (int) constant('Pdo\Pgsql::ATTR_DISABLE_PREPARES')
            : (int) constant('PDO::PGSQL_ATTR_DISABLE_PREPARES');

        return $pdo->prepare($sql, [
            PDO::ATTR_EMULATE_PREPARES => false,
            $disablePrepares => false,
        ]);
    }

    /**
     * @param  array<int|string, scalar|null>  $bindings
     */
    private function bind(PDOStatement $statement, array $bindings): void
    {
        $position = 0;

        foreach ($bindings as $key => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue(
                is_string($key) ? ':'.ltrim($key, ':') : ++$position,
                is_float($value) ? (string) $value : $value,
                $type,
            );
        }
    }

    /**
     * Up to `$limit` rows as positional arrays, bytea streams read into `\x…` hex.
     *
     * @return list<list<mixed>>
     */
    private function fetch(PDOStatement $statement, int $limit): array
    {
        $rows = [];

        while (count($rows) < $limit && is_array($row = $statement->fetch(PDO::FETCH_NUM))) {
            foreach ($row as $i => $value) {
                if (is_resource($value)) {
                    $row[$i] = '\\x'.bin2hex((string) stream_get_contents($value));
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * End the transaction after a failure. A connection that can't even roll
     * back (e.g. stuck mid-COPY) is dropped, so Laravel reconnects on next use.
     */
    private function abort(Connection $connection, PDO $pdo): void
    {
        try {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }

            if (! $pdo->inTransaction()) {
                return;
            }
        } catch (Throwable) {
            // Fall through to disconnect.
        }

        $connection->disconnect();
    }

    /**
     * @param  list<string>  $notices
     */
    private function listenForNotices(PDO $pdo, array &$notices): bool
    {
        $set = [$pdo, 'setNoticeCallback'];

        if (! is_callable($set)) {
            return false;
        }

        $set(function (string $message) use (&$notices): void {
            if (count($notices) < self::MAX_NOTICES) {
                $notices[] = trim($message);
            }
        });

        return true;
    }

    private function stopListening(PDO $pdo): void
    {
        $set = [$pdo, 'setNoticeCallback'];

        if (is_callable($set)) {
            $set(null);
        }
    }

    /**
     * The first keyword of the SQL, upper-cased, or `(` for a parenthesized query.
     */
    private function leadingKeyword(string $sql): string
    {
        foreach (Lexer::tokens($sql) as $token) {
            if (Lexer::isTrivia($token)) {
                continue;
            }

            return $token['type'] === Lexer::WORD || $token['type'] === Lexer::OPEN_PAREN ? strtoupper($token['text']) : '';
        }

        return '';
    }

    /**
     * A positive integer limit from `dbexplorer.limits.<role>.<key>`.
     */
    private function limit(string $role, string $key): int
    {
        $value = config("dbexplorer.limits.{$role}.{$key}");

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("dbexplorer.limits.{$role}.{$key} must be a positive integer.");
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     */
    private function validateBindings(array $bindings): void
    {
        $named = null;

        foreach ($bindings as $key => $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Query bindings must be scalars or null.');
            }

            if ($named !== null && $named !== is_string($key)) {
                throw new InvalidArgumentException('Query bindings must be all positional or all named.');
            }

            $named = is_string($key);
        }
    }

    /**
     * Audit entry, history row, then the event. Returns the audit id.
     *
     * @param  array<int|string, scalar|null>  $bindings
     * @param  array{explain?: string|null, unmask?: bool, label?: string|null, user_id?: string|int|null}  $options
     * @param  array{columns: list<array{name: string, type: string, masked: bool}>, rows: list<list<mixed>>, affected: int|null, truncated: bool, notices: list<string>, noticesSupported: bool}|null  $result
     */
    private function record(string $connection, string $sql, array $bindings, string $role, array $options, string $classification, float $durationMs, ?array $result, ?QueryFailed $failure): int
    {
        $rowCount = null;

        if ($result !== null) {
            $rowCount = $result['columns'] === [] ? $result['affected'] : count($result['rows']);
        }

        $error = $failure === null ? null : $this->auditError($failure, $bindings);
        $outcome = $failure === null ? 'success' : 'error';

        $entry = [
            'action' => 'query',
            'role' => $role,
            'sql' => $sql,
            'classification' => $classification,
            'duration_ms' => $durationMs,
            'row_count' => $rowCount,
            'outcome' => $outcome,
            'error' => $error,
            'meta' => array_filter([
                'explain' => $options['explain'] ?? null,
                'unmasked' => ($options['unmask'] ?? false) === true ? true : null,
                'truncated' => $result !== null && $result['truncated'] ? true : null,
                'bindings' => $bindings === [] ? null : count($bindings),
                'sqlstate' => $failure?->sqlState,
                'hidden_object' => $failure instanceof HiddenObjectReferenced ? $failure->relation : null,
            ], fn (mixed $value): bool => $value !== null),
        ];

        foreach (['label', 'user_id'] as $key) {
            if (isset($options[$key])) {
                $entry[$key] = (string) $options[$key];
            }
        }

        $audit = DbExplorer::audit($entry);

        QueryHistory::query()->insert([
            'user_id' => $audit->user_id,
            'label' => $audit->label ?? $connection,
            'role' => $role,
            'sql' => $sql,
            'classification' => $classification,
            'duration_ms' => (int) round($durationMs),
            'row_count' => $rowCount,
            'outcome' => $outcome,
            'error' => $error,
            'created_at' => Date::now()->format('Y-m-d H:i:s'),
        ]);

        event(new QueryExecuted(
            $audit->id,
            $audit->user_id,
            $audit->label,
            $role,
            $classification,
            $sql,
            (int) round($durationMs),
            $rowCount,
            $outcome,
        ));

        return $audit->id;
    }

    /**
     * The error for the audit log and history: SQLSTATE and the primary message
     * only (DETAIL lines often quote row values), with bound values redacted.
     *
     * @param  array<int|string, scalar|null>  $bindings
     */
    private function auditError(QueryFailed $failure, array $bindings): string
    {
        $message = strtok($failure->getMessage(), "\n");
        $message = $message === false ? '' : $message;
        $values = [];

        foreach ($bindings as $value) {
            $text = is_bool($value) ? '' : (string) $value;

            if ($text !== '') {
                $values[$text] = strlen($text);
            }
        }

        arsort($values);

        foreach (array_keys($values) as $value) {
            $message = str_replace((string) $value, '?', $message);
        }

        return ($failure->sqlState !== null ? $failure->sqlState.': ' : '').$message;
    }
}
