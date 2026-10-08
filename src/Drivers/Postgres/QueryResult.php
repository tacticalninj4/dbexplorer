<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Drivers\Postgres;

/**
 * The outcome of one {@see QueryRunner::run()} call.
 *
 * Rows are positional (one value per column, in column order), so repeated
 * column names survive. Masked values are already replaced, bytea values are
 * `\x…` hex text, and every value is JSON-encodable.
 */
final class QueryResult
{
    /**
     * @param  string  $classification  Classifier output for the SQL (UX only, PRD S11)
     * @param  list<array{name: string, type: string, masked: bool}>  $columns  `type` is the PostgreSQL type name
     * @param  list<list<mixed>>  $rows  At most the role's row cap
     * @param  int  $rowCount  Rows returned
     * @param  int|null  $affectedRows  Rows the statement reported (inserted, updated, ...); null for cursor reads
     * @param  bool  $truncated  Whether more rows existed than the cap allowed
     * @param  list<string>  $notices  NOTICE / WARNING messages raised while the statement ran
     * @param  bool  $noticesSupported  False when the PDO can't capture notices (see QueryRunner)
     * @param  string|null  $explain  'plain' or 'analyze' when the statement was explained
     * @param  array<mixed>|null  $plan  The decoded EXPLAIN (FORMAT JSON) plan
     */
    public function __construct(
        public string $classification,
        public array $columns,
        public array $rows,
        public int $rowCount,
        public ?int $affectedRows,
        public bool $truncated,
        public float $durationMs,
        public array $notices,
        public bool $noticesSupported,
        public ?string $explain = null,
        public ?array $plan = null,
        public ?int $auditId = null,
    ) {
        //
    }
}
