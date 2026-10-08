<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Events;

/**
 * Fired after every interactive query, successful or not, once its audit row
 * is written (IMPLEMENTATION_PLAN §3a).
 *
 * Never carries result values, bound parameter values or connection config.
 */
final class QueryExecuted
{
    /**
     * @param  string  $sql  The SQL text as submitted; values are bound, never part of it
     * @param  string  $outcome  'success' or 'error'
     */
    public function __construct(
        public int $auditId,
        public ?string $userId,
        public ?string $label,
        public string $role,
        public string $classification,
        public string $sql,
        public int $durationMs,
        public ?int $rowCount,
        public string $outcome,
    ) {
        //
    }
}
